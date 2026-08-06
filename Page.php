<?php

namespace App\Plugins\IdfDashboard;

use App\Models\AlertSchedule;
use App\Models\User;
use App\Plugins\Hooks\PageHook;
use App\Plugins\IdfDashboard\Support\Config;
use App\Plugins\IdfDashboard\Support\DeviceAccess;
use App\Plugins\IdfDashboard\Support\DeviceClassifier;
use App\Plugins\IdfDashboard\Support\Freshness;
use App\Plugins\IdfDashboard\Support\IssueBuilder;
use App\Plugins\IdfDashboard\Support\ProblemPolicy;
use App\Plugins\IdfDashboard\Support\Severity;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Page extends PageHook
{
    /**
     * Set once at the top of data() from the admin-configured plugin
     * settings (see Support/Config.php + the Settings page) — every
     * other method reads these instead of a hardcoded constant, so
     * changing the stale timeout / battery thresholds / refresh
     * interval in Settings actually takes effect without a code
     * change. A fresh Page instance is constructed per request (see
     * PageHook::handle()), so instance properties here never leak
     * between requests/users the way a static/class const wouldn't
     * anyway, but *would* if this were a shared service.
     */
    private int $sensorFreshMinutes;

    private int $eventWindowHours;

    private int $batteryCriticalPercent;

    private int $batteryWarningPercent;

    private int $storageCriticalPercent;

    private int $memoryCriticalPercent;

    private int $processorCriticalPercent;

    private int $refreshSeconds;

    private bool $staleEnabled;

    /**
     * The Settings page's "Default Problem Types" checkboxes — set
     * once in data() from $config, keyed the same as
     * metricProblemType()'s return values (see that method's own
     * comment for why this exists and why it is applied to severity,
     * not just display).
     */
    private array $enabledProblemTypes;

    /**
     * Device roles whose power telemetry is mission critical. Stale
     * data on these roles elevates the device to "warning" because we
     * genuinely do not know its current state. Stale data on every
     * other device type is shown as informational only, so broadening
     * sensor collection to the whole network does not manufacture
     * new false criticals on best-effort sensors.
     */
    private const STALE_IS_URGENT_ROLES = ['pdu', 'ups'];

    /**
     * LibreNMS's built-in "Sensor over/under limit" alert rules
     * (verified via the API: rule query is
     * `sensors.sensor_current > sensors.sensor_limit ... sensor_alert = 1`,
     * i.e. exactly the same condition sensorState() already evaluates
     * per-sensor) fire on ANY sensor class device-wide with no
     * indication of which sensor triggered them — a generic "Sensor
     * over limit - Check Device Health Settings" text that cannot be
     * attributed to Temperature/Humidity/Battery/etc., so it could
     * never respect those Problem checkboxes (reported: unchecking
     * "Humidity" left a humidity-caused instance of this alert
     * showing anyway). Since this dashboard's own per-sensor telemetry
     * already surfaces the identical condition with the specific
     * sensor, its value, and proper filtering, showing this alert too
     * is pure duplicate noise, not additional information — suppressed
     * in loadActiveAlerts(). Alerts from *other* rules (device down,
     * service up/down, vendor-specific rules, etc.) are unaffected;
     * those are not redundant with the sensor telemetry view.
     */
    private const REDUNDANT_ALERT_RULE_NAMES = [
        'sensor over limit',
        'sensor under limit',
    ];

    private const RECENT_EVENTS_PER_DEVICE = 3;

    /**
     * Defensive ceiling for one dashboard request. Three events per
     * device remain complete for fleets up to 1,000 authorized devices;
     * larger fleets are marked as limited instead of silently claiming
     * complete event coverage.
     */
    private const RECENT_EVENTS_GLOBAL_LIMIT = 3000;

    public function authorize(User $user): bool
    {
        return true;
    }

    /**
     * $settings is resolved and injected automatically — PageHook::
     * handle() calls this via Laravel's container `$app->call()`,
     * which matches constructor/method parameters to the plugin's
     * persisted settings array by name. See Settings.php for where
     * an admin edits these, and Support/Config.php for the typed,
     * range-clamped defaults applied to anything unset.
     */
    public function data(array $settings = [], ?Request $request = null): array
    {
        $user = $request?->user() ?? request()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('An authenticated LibreNMS user is required.');
        }

        $config = Config::resolve($settings);
        $dashboardRequest = Config::normalizeDashboardRequest(
            $request?->query() ?? request()->query(),
            $config
        );

        $this->sensorFreshMinutes = $config['sensor_fresh_minutes'];
        $this->eventWindowHours = $config['event_window_hours'];
        $this->batteryCriticalPercent = $config['battery_critical_percent'];
        $this->batteryWarningPercent = $config['battery_warning_percent'];
        $this->storageCriticalPercent = $config['storage_critical_percent'];
        $this->memoryCriticalPercent = $config['memory_critical_percent'];
        $this->processorCriticalPercent = $config['processor_critical_percent'];
        $this->refreshSeconds = $config['refresh_seconds'];
        $this->staleEnabled = (bool) $config['default_problem_stale'];

        /*
         * Reported directly by a user of this dashboard: turning a
         * Problem Type off in Settings ("I don't want Humidity/State
         * sensor to matter here") only ever hid that metric's chip —
         * the device's Critical/Warning badge still reflected it,
         * with an honest "hiding N Critical" note. That was a
         * deliberate design choice earlier in this plugin's history
         * (never silently soften severity behind a *viewer's* filter —
         * see the stale-battery entries in AUDIT_NOTES.md) but it is
         * not what this setting is for: Problem Type checkboxes are
         * an *admin* policy decision ("this fleet does not consider
         * this signal a real problem at all"), not a per-viewer
         * display toggle — this plugin has had no per-viewer Problem
         * Type UI since the toolbar was simplified, so there is no
         * other way to express "ignore this signal" than Settings.
         * Confirmed explicitly with the user before changing this:
         * disabling a Problem Type now excludes it from severity
         * computation entirely (normalizeDevice(), via
         * metricProblemType()), not just from what's displayed.
         */
        $this->enabledProblemTypes = [
            'temperature' => (bool) $config['default_problem_temperature'],
            'humidity' => (bool) $config['default_problem_humidity'],
            'battery' => (bool) $config['default_problem_battery'],
            'voltage' => (bool) $config['default_problem_voltage'],
            'fan' => (bool) $config['default_problem_fan'],
            'device' => (bool) $config['default_problem_device'],
            'service' => (bool) $config['default_problem_service'],
            'alert' => (bool) $config['default_problem_alert'],
            'state' => (bool) $config['default_problem_state'],
            'storage' => (bool) $config['default_problem_storage'],
            'memory' => (bool) $config['default_problem_memory'],
            'processor' => (bool) $config['default_problem_processor'],
            'stale' => (bool) $config['default_problem_stale'],
            'other' => (bool) $config['default_problem_other'],
        ];

        /*
         * The single centralized visibility decision (Support/
         * ProblemPolicy::deviceVisible()) both Priority Attention and
         * TV Mode's server-filtered collections now consult, instead of
         * severity display policy living only in Blade-embedded
         * JavaScript. `$tvPolicy` additionally intersects the `tv_hide_*`
         * restrictions and can only ever be a subset of `$policy`.
         */
        $policy = Config::visibilityPolicy($config, 'global');
        $tvPolicy = Config::visibilityPolicy($config, 'tv');

        /*
         * Load every active LibreNMS device authorized by the core
         * Device::hasAccess() scope. No authorized location or device
         * type is silently excluded; every downstream table is then
         * constrained to the IDs returned by this SQL query.
         *
         * `ignore = 1` is LibreNMS's own "don't alert on this device"
         * flag — distinct from `disabled` (which already excludes a
         * device entirely) — set deliberately in LibreNMS by whoever
         * manages this fleet for a device that shouldn't page anyone
         * (e.g. a known-flaky/decommissioned-but-still-polled host).
         * A real fleet audit found this dashboard didn't check it at
         * all: an `ignore = 1` device that happened to be down
         * (`Terrace 3 Credit Card Devices`, device_id 139) was showing
         * as a live Critical "device down" card — exactly the ignored-
         * device noise a NOC dashboard must not manufacture. Ignored
         * devices are excluded from the operational set the same way
         * `disabled` already is; their count is still surfaced
         * separately (`coverage.ignored_count`) so they're visible,
         * never silently dropped from the audit trail.
         */
        $authorizedDevices = DeviceAccess::query($user);

        $excludedCounts = (clone $authorizedDevices)
            ->selectRaw('SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as disabled_count')
            ->selectRaw('SUM(CASE WHEN disabled = 0 AND `ignore` = 1 THEN 1 ELSE 0 END) as ignored_count')
            ->first();
        $ignoredCount = (int) ($excludedCounts->ignored_count ?? 0);
        $disabledCount = (int) ($excludedCounts->disabled_count ?? 0);

        $rawDevices = (clone $authorizedDevices)
            ->leftJoin('locations as l', 'l.id', '=', 'devices.location_id')
            ->where('devices.disabled', 0)
            ->where('devices.ignore', 0)
            ->select([
                'l.id as location_id',
                'l.location',
                'devices.device_id',
                'devices.hostname',
                'devices.ip',
                'devices.display',
                'devices.sysName',
                'devices.type',
                'devices.os',
                'devices.hardware',
                'devices.sysDescr',
                'devices.purpose',
                'devices.status',
                'devices.status_reason',
                'devices.last_polled',
                'devices.last_ping_timetaken',
                'devices.uptime',
            ])
            ->orderBy('l.location')
            ->orderBy('devices.display')
            ->get();

        $allDeviceIds = $rawDevices
            ->pluck('device_id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        /*
         * Load telemetry for every active device, not only devices
         * classified as "power". Switches, servers, firewalls, and
         * wireless controllers frequently report their own chassis
         * temperature/fan/voltage sensors in LibreNMS, and skipping
         * them here was a real monitoring gap: a device could carry a
         * critical internal sensor reading and still show as
         * "healthy" simply because this plugin never looked.
         *
         * Every non-deleted sensor class is loaded — not a fixed
         * whitelist — so a sensor class this dashboard has no curated
         * label for (e.g. a device reporting "current" or "power")
         * still surfaces generically (see genericTelemetry()) instead
         * of silently vanishing the day a new device type is added.
         */
        $sensorMap = collect();

        if ($allDeviceIds->isNotEmpty()) {
            $sensors = DB::table('sensors')
                ->whereIn('device_id', $allDeviceIds)
                ->where('sensor_deleted', 0)
                ->select([
                    'sensor_id',
                    'device_id',
                    'sensor_class',
                    'sensor_descr',
                    'sensor_current',
                    'sensor_limit',
                    'sensor_limit_warn',
                    'sensor_limit_low',
                    'sensor_limit_low_warn',
                    'sensor_alert',
                    'lastupdate',
                ])
                ->get();

            /*
             * "state"-class sensors (2,159 in a real fleet audit — see
             * AUDIT_NOTES.md) are LibreNMS enum/discrete-value sensors
             * (e.g. "System Status" = 16 meaning "normalWithAlarm").
             * Every single one has sensor_limit/_warn/_low/_low_warn
             * = NULL — confirmed against real data, not an assumption
             * — because LibreNMS does not use those columns for this
             * class at all; it resolves severity through
             * sensors_to_state_indexes + state_translations instead
             * (see App\Models\Sensor::currentTranslation() /
             * StateTranslation::severity() in LibreNMS core, reused
             * here rather than reinvented). Without this, every
             * "state" sensor silently evaluated as healthy regardless
             * of its real value — a false negative (a missed real
             * alarm), not a false positive, and the more dangerous
             * kind for a NOC console.
             */
            $stateTranslations = $this->loadStateTranslations($allDeviceIds);

            $sensorMap = $sensors
                ->map(function ($sensor) use ($stateTranslations) {
                    if ($sensor->sensor_class !== 'state') {
                        return $sensor;
                    }

                    $translation = $stateTranslations->get((int) $sensor->sensor_id);

                    $sensor->state_descr = $translation->state_descr ?? null;
                    $sensor->state_name = $translation->state_name ?? null;
                    $sensor->state_generic_value = $translation !== null
                        ? (int) $translation->state_generic_value
                        : null;

                    return $sensor;
                })
                ->groupBy('device_id');
        }

        /*
         * Load service checks for every active device.
         */
        $serviceMap = collect();

        if ($allDeviceIds->isNotEmpty()) {
            $serviceMap = DB::table('services')
                ->whereIn('device_id', $allDeviceIds)
                ->where('service_disabled', 0)
                ->where('service_ignore', 0)
                ->select([
                    'service_id',
                    'device_id',
                    'service_type',
                    'service_name',
                    'service_desc',
                    'service_status',
                    'service_message',
                    'service_changed',
                ])
                ->orderByDesc('service_status')
                ->orderBy('service_name')
                ->get()
                ->groupBy('device_id');
        }

        /*
         * Load active LibreNMS alerts and recent event log entries.
         * Both are optional: the exact column names vary slightly
         * across LibreNMS versions, so these loaders resolve columns
         * defensively and degrade to "unavailable" instead of
         * breaking the whole dashboard when a table/column is not
         * found. See AUDIT_NOTES.md for the assumptions made here.
         */
        $deviceAlerts = $this->loadActiveAlerts($allDeviceIds);
        $deviceEvents = $this->loadRecentEvents($allDeviceIds);

        /*
         * Storage/Memory/Processor — confirmed via a real fleet audit
         * to be genuinely present (84/90/85 devices respectively) and
         * completely unread by this dashboard until now. Same
         * defensive tableExists() pattern as alerts/events, even
         * though these are core LibreNMS tables that should always
         * exist, for the same reason: never take the whole dashboard
         * down over one missing/renamed table.
         */
        $deviceStorage = $this->loadStorage($allDeviceIds);
        $deviceMempools = $this->loadMempools($allDeviceIds);
        $deviceProcessors = $this->loadProcessors($allDeviceIds);
        $deviceOutages = $this->loadDeviceOutages($allDeviceIds);
        $deviceAvailability = $this->loadAvailability($allDeviceIds);
        $maintenanceMap = $this->loadMaintenanceDevices($allDeviceIds);

        /*
         * Normalize every active device exactly once.
         */
        $devices = $rawDevices
            ->map(function ($device) use (
                $sensorMap,
                $serviceMap,
                $deviceAlerts,
                $deviceEvents,
                $deviceStorage,
                $deviceMempools,
                $deviceProcessors,
                $deviceOutages,
                $deviceAvailability,
                $maintenanceMap
            ): array {
                $deviceId = (int) $device->device_id;

                return $this->normalizeDevice(
                    $device,
                    $sensorMap->get($deviceId, collect()),
                    $serviceMap->get($deviceId, collect()),
                    $deviceAlerts['byDevice']->get($deviceId, collect()),
                    $deviceEvents['byDevice']->get($deviceId, collect()),
                    $deviceStorage->get($deviceId, collect()),
                    $deviceMempools->get($deviceId, collect()),
                    $deviceProcessors->get($deviceId, collect()),
                    $deviceOutages['current']->get($deviceId),
                    $deviceOutages['recovered']->get($deviceId),
                    $deviceAvailability->get($deviceId, collect()),
                    $maintenanceMap->get($deviceId)
                );
            })
            ->values();

        /*
         * IDF locations.
         */
        $idfDevices = $devices
            ->filter(fn (array $device): bool => $this->isIdfLocation(
                $device['location']
            ))
            ->values();

        $idfLocations = $this->buildLocationGroups($idfDevices);

        /*
         * MDF is divided into three complete sections.
         */
        $mdfDevices = $devices
            ->where('location', 'MDF')
            ->values();

        $mdfServers = $this->sortDevices(
            $mdfDevices->where('category', 'Server')->values()
        );

        $mdfPower = $this->sortDevices(
            $mdfDevices->where('category', 'Power')->values()
        );

        $mdfInfrastructure = $this->sortDevices(
            $mdfDevices
                ->reject(fn (array $device): bool => in_array(
                    $device['category'],
                    ['Server', 'Power'],
                    true
                ))
                ->values()
        );

        /*
         * Everything that is neither IDF nor MDF remains grouped by
         * location. Every active device lands in exactly one of
         * idf / mdf / other, so this split is a taxonomy, not a
         * monitoring-coverage measurement. Real coverage (are we
         * actually receiving sensor/service/alert/event evidence for
         * these devices) is computed separately below.
         */
        $otherDevices = $devices
            ->reject(function (array $device): bool {
                return $device['location'] === 'MDF'
                    || $this->isIdfLocation($device['location']);
            })
            ->values();

        $otherLocations = $this->buildLocationGroups($otherDevices);

        /*
         * TV Mode's own collections, filtered server-side through the
         * exact same $tvPolicy every other TV consumer (Priority
         * Attention, future views) uses — the browser never receives a
         * Healthy/disabled-severity device's markup for TV at all
         * (bounding TV's DOM/HTML size), and TV's JS-side
         * tvDeviceMatches() check on top of this is now pure defense in
         * depth against a stale client-side settings cache, not the
         * only enforcement point. Desktop's own $idfLocations/
         * $otherLocations/$mdfServers/etc. above are deliberately left
         * as the full authorized set — desktop's "Problems only"/"View
         * all" toggle is a genuine per-viewer *session* override (see
         * settings.blade.php's own header text), which only makes sense
         * against an unfiltered base collection.
         */
        $tvVisible = fn (array $device): bool => ProblemPolicy::deviceVisible($device, $tvPolicy);

        $tvIdfDevices = $idfDevices->filter($tvVisible)->values();
        $tvIdfLocations = $this->buildLocationGroups($tvIdfDevices);

        $tvOtherDevices = $otherDevices->filter($tvVisible)->values();
        $tvOtherLocations = $this->buildLocationGroups($tvOtherDevices);

        $tvMdfServers = $this->sortDevices($mdfServers->filter($tvVisible)->values());
        $tvMdfPower = $this->sortDevices($mdfPower->filter($tvVisible)->values());
        $tvMdfInfrastructure = $this->sortDevices($mdfInfrastructure->filter($tvVisible)->values());

        /*
         * Defensive DOM-size ceiling: even after severity filtering, a
         * very large fleet with everything enabled could still exceed
         * what a TV screen should ever render into the page at once.
         * Truncation always happens worst-severity-first (sortDevices()
         * / buildLocationGroups() already order that way), so a
         * Critical/Warning device is never pushed out by a
         * lower-severity one that fits — the omitted remainder is
         * reported as a count, never silently dropped from the fleet.
         */
        $tvMaxDevices = max(1, $tvPolicy['tvMaximumDevicesRendered']);
        $tvOmittedDeviceCount = max(0, $tvMdfServers->count() - $tvMaxDevices)
            + max(0, $tvMdfPower->count() - $tvMaxDevices)
            + max(0, $tvMdfInfrastructure->count() - $tvMaxDevices);
        $tvMdfServers = $tvMdfServers->take($tvMaxDevices)->values();
        $tvMdfPower = $tvMdfPower->take($tvMaxDevices)->values();
        $tvMdfInfrastructure = $tvMdfInfrastructure->take($tvMaxDevices)->values();

        /*
         * Real monitoring-coverage audit.
         *
         * This intentionally replaces the previous "coverage" metric,
         * which always reported 100% because every active device is
         * unconditionally placed into idf/mdf/other by construction
         * (that grouping can never fail, so it can never reveal a
         * gap). The metrics below can genuinely be less than 100%.
         */
        $activeCount = $devices->count();

        $unassignedLocationCount = $devices
            ->where('location', 'Unassigned')
            ->count();

        $powerRoleDevices = $devices
            ->whereIn('role', self::STALE_IS_URGENT_ROLES)
            ->values();

        $powerSensorCovered = $powerRoleDevices
            ->filter(fn (array $device): bool => $device['telemetry']->contains(
                fn (array $metric): bool => in_array(
                    $metric['state'],
                    ['healthy', 'warning', 'critical'],
                    true
                )
            ))
            ->count();

        $devicesWithServices = $devices
            ->filter(fn (array $device): bool => $device['service_total'] > 0)
            ->count();

        $activeAlertCount = $devices->sum(
            fn (array $device): int => $device['alert_count']
        );

        $devicesWithActiveAlerts = $devices
            ->filter(fn (array $device): bool => $device['alert_count'] > 0)
            ->count();

        $devicesWithRecentEvents = $devices
            ->filter(fn (array $device): bool => $device['recent_event_count'] > 0)
            ->count();

        $locationPercent = $activeCount > 0
            ? round((($activeCount - $unassignedLocationCount) / $activeCount) * 100, 1)
            : 100.0;

        $powerSensorPercent = $powerRoleDevices->count() > 0
            ? round(($powerSensorCovered / $powerRoleDevices->count()) * 100, 1)
            : 100.0;

        $servicePercent = $activeCount > 0
            ? round(($devicesWithServices / $activeCount) * 100, 1)
            : 100.0;

        $eventPercent = ($deviceEvents['available']
            && $deviceEvents['complete']
            && $activeCount > 0)
            ? round(($devicesWithRecentEvents / $activeCount) * 100, 1)
            : null;

        $serviceChecks = $devices->sum(
            fn (array $device): int => $device['service_total']
        );

        $serviceProblems = $devices->sum(
            fn (array $device): int => $device['service_problem_count']
        );

        $staleSensorDevices = $devices
            ->filter(fn (array $device): bool => in_array(
                'stale',
                $device['problem_types'],
                true
            ))
            ->count();

        $maintenanceCount = $devices
            ->where('maintenance', true)
            ->count();

        $noSensorInstalled = $devices->sum(
            fn (array $device): int => $device['no_sensor_count']
        );

        $recentRecoveries = $devices
            ->where('recovered_recently', true)
            ->count();

        $operationalDown = $devices
            ->filter(fn (array $device): bool => $device['status'] === 0 && ! $device['maintenance'])
            ->count();

        /*
         * Priority Attention now consults the same centralized policy
         * TV Mode does — previously it filtered purely on each issue's
         * fixed Severity::metadata()['actionable'] flag, so disabling
         * "Needs Review" (Unknown) in Settings had no effect here even
         * though it correctly hid those devices from TV: two different
         * sources of truth for the same "is this severity shown"
         * question, exactly what a single policy is meant to prevent.
         */
        $priorityAttention = $this->buildPriorityAttention(
            $devices->filter(fn (array $device): bool => ProblemPolicy::deviceVisible($device, $policy))->values(),
            (int) $config['maximum_priority_issues']
        );
        $allLocations = $this->buildLocationGroups($devices);
        $filteredDevices = $this->filterDevices($devices, $dashboardRequest);
        $filteredLocations = $this->buildLocationGroups($filteredDevices);

        if (! $config['show_healthy_locations'] && $dashboardRequest['severity'] !== Severity::HEALTHY) {
            $filteredLocations = $filteredLocations
                ->reject(fn (array $location): bool => $location['health'] === Severity::HEALTHY)
                ->values();
        }

        $viewData = $this->buildViewData(
            $dashboardRequest,
            $devices,
            $filteredDevices,
            $allLocations,
            $filteredLocations,
            $priorityAttention
        );

        /*
         * The header summary (`$visibleSummary`) now consults the same
         * centralized policy Priority Attention and TV Mode do — it
         * previously counted from $filteredDevices, which reflects only
         * the Phase 2 per-request interactive filter (a URL-scoped
         * severity/category/problem dropdown, search, pagination), never
         * default_severity_*. An admin disabling Unknown/Stale/
         * Maintenance/Healthy in Settings had no effect on these header
         * counters at all — a third, independent source of truth for
         * "is this severity shown" alongside the two already fixed.
         *
         * Deliberately a *separate* collection from $filteredDevices/
         * $filteredLocations, not a mutation of them: the Locations/
         * Devices paginated list views and their own independent
         * severity/category/problem dropdown filter are unaffected by
         * this change, matching this phase's explicit "summary total
         * interno / summary visible / summary TV" three-way distinction
         * rather than collapsing them into one shared collection.
         */
        $policyVisibleDevices = $filteredDevices
            ->filter(fn (array $device): bool => ProblemPolicy::deviceVisible($device, $policy))
            ->values();
        $policyVisibleLocations = $this->buildLocationGroups($policyVisibleDevices);

        $visibleSummary = [
            'critical_devices' => $policyVisibleDevices->where('health', Severity::CRITICAL)->count(),
            'warning_devices' => $policyVisibleDevices->where('health', Severity::WARNING)->count(),
            'devices_down' => $policyVisibleDevices
                ->filter(fn (array $device): bool => $device['status'] === 0 && ! $device['maintenance'])
                ->count(),
            'service_problems' => $policyVisibleDevices->sum('service_problem_count'),
            'locations_affected' => $policyVisibleLocations->where('issue_count', '>', 0)->count(),
            'stale_sensor_devices' => $policyVisibleDevices
                ->filter(fn (array $device): bool => in_array('stale', $device['problem_types'], true))
                ->count(),
            /*
             * no_sensor_count is never part of a device's overall health
             * (Severity::worst() explicitly skips NO_SENSOR — a device
             * with nothing else wrong is 'healthy' and only reaches
             * $policyVisibleDevices if the Healthy toggle allows it,
             * which would make this counter always read 0 whenever
             * Healthy is off, unrelated to the actual no_sensor
             * setting). Counted from the request-filtered-but-not-
             * severity-filtered set instead, gated only by
             * default_severity_no_sensor's own toggle — Caso 8's exact
             * requirement: "no aparece ... en su contador" when that
             * one setting is off, independent of Healthy.
             */
            'no_sensor_installed' => $policy['no_sensor']
                ? $filteredDevices->sum('no_sensor_count')
                : 0,
            'devices' => $policyVisibleDevices->count(),
        ];
        $locationOptions = $allLocations
            ->map(fn (array $location): array => [
                'id' => $location['location_id'] ?? 0,
                'name' => $location['name'],
            ])
            ->sortBy('name')
            ->values();

        return [
            'pluginTitle' => 'Infrastructure Health Dashboard',

            'priorityAttention' => $priorityAttention,

            'locations' => $idfLocations,
            'otherLocations' => $otherLocations,

            'mdf' => [
                'total_devices' => $mdfDevices->count(),
                'devices_up' => $mdfDevices->where('status', 1)->count(),
                'devices_down' => $mdfDevices
                    ->filter(fn (array $device): bool => $device['status'] === 0 && ! $device['maintenance'])
                    ->count(),

                'servers' => $mdfServers,
                'server_count' => $mdfServers->count(),

                'power' => $mdfPower,
                'power_count' => $mdfPower->count(),

                'infrastructure' => $mdfInfrastructure,
                'infrastructure_count' => $mdfInfrastructure->count(),
            ],

            /*
             * TV Mode's own, already-Settings-filtered collections (see
             * $tvVisible above). TV Mode must render from these, never
             * from the unfiltered 'locations'/'otherLocations'/'mdf'
             * keys above — that unfiltered path is what let TV silently
             * show devices outside the configured severity policy.
             */
            'tv' => [
                'idfLocations' => $tvIdfLocations,
                'otherLocations' => $tvOtherLocations,
                'mdfServers' => $tvMdfServers,
                'mdfPower' => $tvMdfPower,
                'mdfInfrastructure' => $tvMdfInfrastructure,
                'omittedDeviceCount' => $tvOmittedDeviceCount,
                'policy' => $tvPolicy,
            ],

            'summary' => [
                'active_devices' => $activeCount,
                'devices_up' => $devices->where('status', 1)->count(),
                'devices_down' => $operationalDown,

                'critical_devices' => $devices
                    ->where('health', 'critical')
                    ->count(),

                'warning_devices' => $devices
                    ->where('health', 'warning')
                    ->count(),

                'unknown_devices' => $devices
                    ->where('health', 'unknown')
                    ->count(),

                'stale_devices' => $devices
                    ->where('health', Severity::STALE)
                    ->count(),

                'maintenance_devices' => $maintenanceCount,

                'healthy_devices' => $devices
                    ->where('health', 'healthy')
                    ->count(),

                'idf_locations' => $idfLocations->count(),
                'idf_devices' => $idfDevices->count(),

                'other_locations' => $otherLocations->count(),
                'other_devices' => $otherDevices->count(),

                'service_checks' => $serviceChecks,
                'service_problems' => $serviceProblems,

                'active_alerts' => $activeAlertCount,
                'devices_with_active_alerts' => $devicesWithActiveAlerts,

                'stale_sensor_devices' => $staleSensorDevices,
                'no_sensor_installed' => $noSensorInstalled,
                'recent_recoveries' => $recentRecoveries,
                'locations_affected' => $allLocations->where('issue_count', '>', 0)->count(),
            ],

            'coverage' => [
                'active_devices' => $activeCount,
                'ignored_count' => $ignoredCount,
                'disabled_count' => $disabledCount,
                'idf_count' => $idfDevices->count(),
                'mdf_count' => $mdfDevices->count(),
                'other_count' => $otherDevices->count(),
                'unassigned_count' => $unassignedLocationCount,

                'location_percent' => $locationPercent,
                'location_ok' => $unassignedLocationCount === 0,

                'power_devices' => $powerRoleDevices->count(),
                'power_sensor_covered' => $powerSensorCovered,
                'power_sensor_percent' => $powerSensorPercent,
                'power_sensor_ok' => $powerRoleDevices->isEmpty()
                    || $powerSensorCovered === $powerRoleDevices->count(),

                'service_covered' => $devicesWithServices,
                'service_percent' => $servicePercent,

                'alerts_available' => $deviceAlerts['available'],
                'active_alert_count' => $activeAlertCount,
                'devices_with_active_alerts' => $devicesWithActiveAlerts,
                'alert_ok' => (! $deviceAlerts['available'])
                    || $devicesWithActiveAlerts === 0,

                'events_available' => $deviceEvents['available'],
                'events_complete' => $deviceEvents['complete'],
                'devices_with_recent_events' => $devicesWithRecentEvents,
                'event_percent' => $eventPercent,
            ],

            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'refreshSeconds' => $this->refreshSeconds,
            'sensorFreshMinutes' => $this->sensorFreshMinutes,
            'eventWindowHours' => $this->eventWindowHours,
            'severityDefinitions' => Severity::definitions(),

            // Every admin-configured default the toolbar/TV Mode JS
            // needs — see Support/Config.php for the full schema.
            // Passed through as one array rather than flattened so
            // the blade template and Config.php stay the single
            // source of truth for which keys exist.
            'config' => $config,

            /*
             * A viewer's browser persists their filter choices in
             * localStorage (so "Problems only" survives a page
             * refresh) — but that meant an admin changing the
             * Settings-configured defaults had no visible effect on
             * an already-loaded browser/TV until someone manually
             * clicked "Reset", since the persisted values always won
             * over new defaults for any key already saved. Reported:
             * "tengo que dar reset en page... no revisa los setting".
             * This is a hash of every resolved setting; the JS side
             * (loadPersistedState()) compares it against the value it
             * last saved and, on a mismatch, discards the persisted
             * filter state and re-adopts the fresh defaults below —
             * so the *next* real page load after a Settings change
             * self-corrects with no manual Reset needed. A viewer can
             * still locally override live during their session; that
             * override only gets invalidated the next time Settings
             * actually change again.
             */
            'settingsVersion' => md5(json_encode($config)),
            'dashboardView' => $dashboardRequest['view'],
            'filters' => $dashboardRequest,
            'viewData' => $viewData,
            'allLocations' => $allLocations,
            'locationOptions' => $locationOptions,
            'categories' => DeviceClassifier::CATEGORIES,
            'dashboardUrl' => url('/plugin/IdfDashboard'),
            'visibleSummary' => $visibleSummary,
        ];
    }

    /**
     * Apply Phase 2 filters only after the collection has been built from the
     * authorized SQL boundary. No filter is ever used as an authorization
     * substitute, and every sort key is selected from a fixed whitelist in
     * Config::normalizeDashboardRequest().
     *
     * @param  array<string, mixed>  $filters
     */
    private function filterDevices(Collection $devices, array $filters): Collection
    {
        $search = Str::lower((string) $filters['search']);

        $filtered = $devices
            ->filter(function (array $device) use ($filters, $search): bool {
                if ($search !== '') {
                    $haystack = Str::lower(implode(' ', [
                        $device['name'],
                        $device['hostname'],
                        $device['ip'],
                        $device['hardware'],
                        $device['location'],
                    ]));

                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                if ($filters['severity'] !== '' && $device['health'] !== $filters['severity']) {
                    return false;
                }

                if ($filters['category'] !== '' && $device['category'] !== $filters['category']) {
                    return false;
                }

                if ($filters['problem'] !== '' && ! in_array($filters['problem'], $device['problem_types'], true)) {
                    return false;
                }

                if ($filters['location'] !== null && ($device['location_id'] ?? 0) !== $filters['location']) {
                    return false;
                }

                if ($filters['problems_only'] && ! $device['has_issue']) {
                    return false;
                }

                if ($filters['stale'] && ! in_array('stale', $device['problem_types'], true)) {
                    return false;
                }

                return ! $filters['no_sensor'] || $device['no_sensor_count'] > 0;
            })
            ->values();

        $sorter = match ($filters['sort']) {
            'name' => fn (array $device): array => [Str::lower($device['name']), $device['device_id']],
            'location' => fn (array $device): array => [Str::lower($device['location']), Str::lower($device['name']), $device['device_id']],
            'freshness' => fn (array $device): array => [-(int) ($device['freshness']['age_seconds'] ?? -1), Str::lower($device['name']), $device['device_id']],
            default => fn (array $device): array => [(int) Severity::metadata($device['health'], $device['health'] === Severity::STALE)['rank'], Str::lower($device['name']), $device['device_id']],
        };

        return ($filters['direction'] === 'desc' ? $filtered->sortByDesc($sorter) : $filtered->sortBy($sorter))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildViewData(
        array $filters,
        Collection $devices,
        Collection $filteredDevices,
        Collection $allLocations,
        Collection $filteredLocations,
        array $priorityAttention
    ): array {
        $view = $filters['view'];

        if ($view === 'devices') {
            return [
                'kind' => 'devices',
                'devices' => $this->paginateCollection($filteredDevices, $filters['page'], $filters['per_page']),
            ];
        }

        if ($view === 'locations') {
            return [
                'kind' => 'locations',
                'locations' => $this->paginateCollection($filteredLocations, $filters['page'], $filters['per_page']),
            ];
        }

        if ($view === 'location') {
            $locationId = $filters['id'];
            $location = $locationId === null
                ? null
                : $allLocations->first(fn (array $row): bool => ($row['location_id'] ?? 0) === $locationId);

            if ($location === null) {
                return ['kind' => 'location', 'found' => false];
            }

            $locationDevices = $this->filterDevices(
                collect($location['devices']),
                array_replace($filters, ['location' => null])
            );

            return [
                'kind' => 'location',
                'found' => true,
                'location' => $location,
                'devices' => $this->paginateCollection($locationDevices, $filters['page'], $filters['per_page']),
            ];
        }

        if ($view === 'device') {
            $device = $filters['id'] === null
                ? null
                : $devices->first(fn (array $row): bool => $row['device_id'] === $filters['id']);

            return [
                'kind' => 'device',
                'found' => $device !== null,
                'device' => $device,
            ];
        }

        $visiblePriority = $this->buildPriorityAttention(
            $filteredDevices,
            max(1, count($priorityAttention['items']))
        );
        $problemLocations = $filteredLocations
            ->where('issue_count', '>', 0)
            ->take(8)
            ->values();

        return [
            'kind' => 'overview',
            'priority' => $visiblePriority,
            'critical_locations' => $problemLocations,
            'healthy' => [
                'devices' => $filteredDevices->where('health', Severity::HEALTHY)->count(),
                'locations' => $filteredLocations->where('health', Severity::HEALTHY)->count(),
            ],
        ];
    }

    /** @return array{items: array<int, mixed>, total: int, page: int, per_page: int, pages: int, from: int, to: int} */
    private function paginateCollection(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $pageItems = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pageItems->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'from' => $from,
            'to' => $total === 0 ? 0 : $from + $pageItems->count() - 1,
        ];
    }

    /**
     * Classifies a telemetry metric into the same category keys as
     * $enabledProblemTypes and the Settings "Default Problem Types"
     * checkboxes, by label — the single shared source for that
     * classification (previously duplicated between this loop and a
     * near-identical one in page.blade.php's $metricIcon; only this
     * copy matters for severity, so it lives here). The "state" branch
     * is kept distinct from the 'other' catch-all deliberately — see
     * its own history in AUDIT_NOTES.md (2,159 previously-invisible
     * state sensors).
     */
    private function metricProblemType(array $metric): string
    {
        $label = Str::lower($metric['label']);

        if (Str::contains($label, 'temperature')) {
            return 'temperature';
        }

        if (Str::contains($label, 'humidity')) {
            return 'humidity';
        }

        if (Str::contains($label, 'battery')) {
            return 'battery';
        }

        if (Str::contains($label, 'voltage')) {
            return 'voltage';
        }

        if (Str::contains($label, 'fan')) {
            return 'fan';
        }

        if (Str::contains($label, 'state')) {
            return 'state';
        }

        if (Str::contains($label, 'storage')) {
            return 'storage';
        }

        if (Str::contains($label, 'memory')) {
            return 'memory';
        }

        if (Str::contains($label, 'processor')) {
            return 'processor';
        }

        return 'other';
    }

    private function telemetryMetricEnabled(array $metric): bool
    {
        $type = $this->metricProblemType($metric);

        if (! ($this->enabledProblemTypes[$type] ?? true)) {
            return false;
        }

        $stale = (bool) ($metric['stale'] ?? false);

        if (ProblemPolicy::metricEnabled($this->enabledProblemTypes, $type, $stale)) {
            return true;
        }

        // Disabling stale removes stale-only healthy telemetry, but must
        // never hide a real last-known Critical/Warning/Unknown cause.
        return in_array(
            Severity::normalize((string) ($metric['state'] ?? Severity::UNKNOWN)),
            [Severity::CRITICAL, Severity::WARNING, Severity::UNKNOWN],
            true
        );
    }

    private function normalizeDevice(
        object $device,
        Collection $sensors,
        Collection $services,
        Collection $alerts,
        Collection $events,
        Collection $storage,
        Collection $mempools,
        Collection $processors,
        ?object $currentOutage = null,
        ?object $recentRecovery = null,
        ?Collection $availability = null,
        ?object $maintenance = null
    ): array {
        $name = $this->deviceName($device);

        $location = trim((string) ($device->location ?? ''));

        if ($location === '') {
            $location = 'Unassigned';
        }

        $role = $this->deviceRole(
            $name,
            (string) $device->type,
            (string) ($device->hardware ?? ''),
            (string) ($device->sysDescr ?? '')
        );

        $classification = DeviceClassifier::classify([
            'type' => $device->type,
            'os' => $device->os,
            'hardware' => $device->hardware,
            'sysDescr' => $device->sysDescr,
            'purpose' => $device->purpose,
            'hostname' => $device->hostname,
            'display' => $name,
        ], $sensors->pluck('sensor_class')->all());

        // A Problem Type turned off in Settings is an *admin* decision
        // that the signal doesn't matter for this fleet at all — not a
        // per-viewer display filter (see $enabledProblemTypes' own
        // comment). Filtered out here, at the source, so it is excluded
        // from severity, from problem_types tagging, and from the
        // rendered telemetry chips alike: a single rule instead of three
        // places that could drift out of sync.
        $telemetry = collect($this->buildTelemetry($role, $sensors))
            ->concat($this->buildResourceTelemetry($storage, $mempools, $processors))
            ->filter(function (array $metric): bool {
                return $this->telemetryMetricEnabled($metric);
            })
            ->values();

        $serviceRows = $services
            ->map(function ($service): array {
                $status = (int) $service->service_status;

                $name = trim((string) ($service->service_name ?? ''));

                if ($name === '') {
                    $name = trim((string) ($service->service_desc ?? ''));
                }

                if ($name === '') {
                    $name = 'Service #' . $service->service_id;
                }

                return [
                    'service_id' => (int) $service->service_id,
                    'name' => Str::limit($name, 90),
                    'type' => (string) $service->service_type,
                    'status' => $status,
                    'status_label' => $this->serviceStatusLabel($status),
                    'status_class' => $this->serviceStatusClass($status),

                    'message' => Str::limit(
                        trim((string) ($service->service_message ?? '')),
                        180
                    ),

                    'changed' => ((int) ($service->service_changed ?? 0)) > 0
                        ? Carbon::createFromTimestamp((int) $service->service_changed)
                        : null,
                ];
            })
            ->values();

        $serviceProblems = $serviceRows
            ->where('status', '!=', 0)
            ->values();

        // A stale reading's *severity* is never softened — a UPS
        // battery last seen at 0% is still a dead battery, not a
        // data-quality footnote to gray out. `stale` (see
        // sensorMetric()/batteryChargeMetric()) is a separate,
        // additive flag on every metric meaning "this may not
        // reflect this exact instant," never "ignore this."
        $issueTelemetry = $telemetry
            ->filter(fn (array $metric): bool => in_array(
                $metric['state'],
                ['warning', 'critical', 'unknown'],
                true
            ))
            ->values();

        $staleTelemetry = $telemetry
            ->filter(fn (array $metric): bool => $metric['stale'] ?? false)
            ->values();

        // A stale reading whose last known value was itself healthy
        // is a pure monitoring-visibility gap — no evidence of a
        // problem, but no fresh evidence there isn't one either. On
        // mission-critical power roles that alone still earns a
        // Warning; it must not double up with (or blunt) a reading
        // that's already counted as critical/warning above. Scoped to
        // *curated* telemetry only (see the comment in buildTelemetry())
        // — a stale ancillary/generic sensor among a dozen others
        // must not carry the same weight as losing the device's actual
        // curated power reading.
        $staleHealthyTelemetry = $staleTelemetry
            ->filter(fn (array $metric): bool => $metric['state'] === 'healthy'
                && ($metric['curated'] ?? false))
            ->values();

        $staleIsUrgent = ProblemPolicy::staleEscalates(
            $this->enabledProblemTypes,
            $role,
            $staleHealthyTelemetry->isNotEmpty()
        );

        $activeAlerts = $alerts->values();

        $recentEvents = $events->values();

        // Same admin exclusion as the telemetry filter above, applied
        // to the three problem categories that aren't per-sensor
        // metrics: a device-down, a service problem, or an alert only
        // counts toward health/problem_types if its own Settings
        // checkbox is on. Unlike telemetry, the underlying
        // service/alert rows are still returned in full below — they
        // are structural lists with their own display purpose, not
        // chips that would clutter the card the way an excluded sensor
        // reading would.
        $deviceDownCounts = $this->enabledProblemTypes['device'] ?? true;
        $serviceCounts = $this->enabledProblemTypes['service'] ?? true;
        $alertsCount = $this->enabledProblemTypes['alert'] ?? true;

        $maintenanceActive = $maintenance !== null;
        $downSince = $currentOutage !== null && isset($currentOutage->going_down)
            ? Carbon::createFromTimestamp((int) $currentOutage->going_down)
            : null;
        $recoveredAt = (int) $device->status === 1
            && $recentRecovery !== null
            && isset($recentRecovery->up_again)
                ? Carbon::createFromTimestamp((int) $recentRecovery->up_again)
                : null;

        $issues = $this->buildDeviceIssues(
            $device,
            $location,
            $sensors,
            $telemetry,
            $serviceProblems,
            $activeAlerts,
            $maintenanceActive,
            $downSince,
            $staleIsUrgent,
            $deviceDownCounts,
            $serviceCounts,
            $alertsCount
        );

        $health = Severity::worst(
            $issues
                ->filter(fn (array $issue): bool => (bool) $issue['actionable'])
                ->pluck('severity'),
            true
        );

        if ($health === Severity::HEALTHY && $maintenanceActive) {
            $health = Severity::MAINTENANCE;
        }

        $problemTypes = collect();

        if ($deviceDownCounts && ! $maintenanceActive && (int) $device->status === 0) {
            $problemTypes->push('device');
        }

        foreach ($issueTelemetry as $metric) {
            $problemTypes->push($this->metricProblemType($metric));
        }

        if ($serviceCounts && $serviceProblems->isNotEmpty()) {
            $problemTypes->push('service');
        }

        if ($alertsCount && $activeAlerts->isNotEmpty()) {
            $problemTypes->push('alert');
        }

        // "Stale data" as a Problem tag reflects *any* stale telemetry
        // on the device, not just the subset that also happens to be
        // the reason for its health color — a device already Critical
        // from a fresh reading can still usefully be found via the
        // "Stale data" filter if some other sensor of its stopped
        // updating too.
        if (ProblemPolicy::staleEnabled($this->enabledProblemTypes) && $staleTelemetry->isNotEmpty()) {
            $problemTypes->push('stale');
        }

        if (
            $health !== 'healthy'
            && $problemTypes->isEmpty()
        ) {
            $problemTypes->push('other');
        }

        $primaryIssue = $issues
            ->where('actionable', true)
            ->sortBy(fn (array $issue): array => [$issue['priority'], $issue['key']])
            ->first();
        $pollFreshness = Freshness::evaluate(
            $device->last_polled,
            $this->sensorFreshMinutes,
            $this->staleEnabled,
            false
        );
        $availabilityRows = $availability ?? collect();
        $availabilityRow = $availabilityRows->firstWhere('duration', 86400)
            ?? $availabilityRows->sortBy('duration')->first();

        return [
            'location_id' => $device->location_id !== null
                ? (int) $device->location_id
                : null,

            'location' => $location,
            'device_id' => (int) $device->device_id,
            'name' => $name,
            'hostname' => (string) $device->hostname,
            'ip' => (string) ($device->ip ?? ''),
            'sys_name' => (string) ($device->sysName ?? ''),
            'type' => (string) $device->type,
            'os' => (string) $device->os,
            'hardware' => (string) ($device->hardware ?? ''),
            'purpose' => (string) ($device->purpose ?? ''),
            'status' => (int) $device->status,
            'status_reason' => (string) ($device->status_reason ?? ''),
            'last_polled' => $device->last_polled,
            'uptime_seconds' => max(0, (int) ($device->uptime ?? 0)),
            'uptime' => ((int) ($device->uptime ?? 0)) > 0
                ? $this->formatDuration((float) $device->uptime)
                : null,
            'freshness' => $pollFreshness,
            'latency_ms' => $device->last_ping_timetaken !== null
                ? round((float) $device->last_ping_timetaken, 2)
                : null,
            'availability' => $availabilityRow !== null
                ? [
                    'percent' => round((float) $availabilityRow->availability_perc, 3),
                    'duration_seconds' => (int) $availabilityRow->duration,
                    'window' => $this->formatDuration((float) $availabilityRow->duration),
                ]
                : null,
            'role' => $role,
            'classification' => $classification,
            'category' => $classification['category'],
            'health' => $health,

            'telemetry' => $telemetry,
            'issue_telemetry' => $issueTelemetry,

            'service_total' => $serviceRows->count(),
            'service_problem_count' => $serviceProblems->count(),
            'services' => $serviceRows,
            'service_problems' => $serviceProblems,

            'alerts' => $activeAlerts,
            'alert_count' => $activeAlerts->count(),

            'recent_events' => $recentEvents->take(3)->values(),
            'recent_event_count' => $recentEvents->count(),

            'maintenance' => $maintenanceActive,
            'maintenance_title' => $maintenanceActive
                ? trim((string) ($maintenance->title ?? 'Scheduled maintenance'))
                : null,
            'down_since' => $downSince,
            'down_age_seconds' => $downSince !== null ? max(0, now()->timestamp - $downSince->timestamp) : null,
            'recovered_at' => $recoveredAt,
            'recovered_recently' => $recoveredAt !== null,
            'issues' => $issues,
            'primary_issue' => $primaryIssue,
            'issue_count' => $issues->where('actionable', true)->count(),
            'no_sensor_count' => $issues->where('severity', Severity::NO_SENSOR)->count(),
            'has_issue' => Severity::metadata($health, $health === Severity::STALE)['actionable'],
            'problem_types' => $problemTypes
                ->unique()
                ->values()
                ->all(),
            'device_url' => url('device/device=' . (int) $device->device_id),
        ];
    }

    /**
     * Build every operational condition once. Device health, summaries and
     * Priority Attention consume this same structure instead of independently
     * re-interpreting sensor/service/alert state.
     */
    private function buildDeviceIssues(
        object $device,
        string $location,
        Collection $sensors,
        Collection $telemetry,
        Collection $serviceProblems,
        Collection $alerts,
        bool $maintenance,
        ?Carbon $downSince,
        bool $staleIsUrgent,
        bool $deviceDownCounts,
        bool $serviceCounts,
        bool $alertsCount
    ): Collection {
        $deviceId = (int) $device->device_id;
        $locationId = $device->location_id !== null ? (int) $device->location_id : null;
        $deviceUrl = url('device/device=' . $deviceId);
        $issues = collect();

        if ($deviceDownCounts && ! $maintenance && (int) $device->status === 0) {
            $ageSeconds = $downSince !== null ? max(0, now()->timestamp - $downSince->timestamp) : null;
            $duration = $ageSeconds !== null ? $this->formatDuration((float) $ageSeconds) : 'duration unavailable';
            $issues->push(IssueBuilder::make([
                'key' => 'device:' . $deviceId . ':down',
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => Severity::CRITICAL,
                'priority' => IssueBuilder::PRIORITY_DEVICE_DOWN,
                'source' => 'device',
                'type' => 'device_down',
                'title' => 'Device Down',
                'description' => 'Device down — unavailable for ' . $duration,
                'timestamp' => $downSince?->format('Y-m-d H:i:s'),
                'age_seconds' => $ageSeconds,
                'actionable' => true,
                'device_url' => $deviceUrl,
            ]));
        }

        $representedSensorIds = $telemetry
            ->pluck('sensor_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $issueMetrics = $telemetry->values();

        foreach ($sensors as $sensor) {
            if (in_array((int) $sensor->sensor_id, $representedSensorIds, true)) {
                continue;
            }

            [$sensorLabel, $sensorFormat] = $this->sensorPresentation((string) $sensor->sensor_class);
            $description = trim((string) $sensor->sensor_descr);
            $metric = $this->individualSensorMetric(
                $sensor,
                $description !== '' ? $description : $sensorLabel,
                $sensorFormat
            ) + ['curated' => false];

            if (! $this->telemetryMetricEnabled($metric)) {
                continue;
            }

            if (in_array($metric['state'], [Severity::CRITICAL, Severity::WARNING, Severity::UNKNOWN], true)) {
                $issueMetrics->push($metric);
            }
        }

        foreach ($issueMetrics as $metric) {
            $state = Severity::normalize($metric['state'] ?? null);
            $sensorId = (int) ($metric['sensor_id'] ?? 0);
            $type = $this->metricProblemType($metric);
            $freshness = is_array($metric['freshness'] ?? null) ? $metric['freshness'] : [];
            $description = trim((string) ($metric['cause'] ?? ''));

            if ($description === '') {
                $description = trim((string) ($metric['label'] ?? 'Sensor'))
                    . ' — ' . trim((string) ($metric['value'] ?? 'Current value unavailable'));
            }

            if (in_array($state, [Severity::CRITICAL, Severity::WARNING, Severity::UNKNOWN], true)) {
                $issues->push(IssueBuilder::make([
                    'key' => 'sensor:' . $deviceId . ':' . $sensorId . ':' . $state,
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $state,
                    'source' => 'sensor',
                    'type' => $type,
                    'title' => (string) $metric['label'],
                    'description' => $description,
                    'value' => $metric['current_value'] ?? null,
                    'unit' => $metric['unit'] ?? null,
                    'threshold' => $metric['threshold'] ?? null,
                    'threshold_direction' => $metric['threshold_direction'] ?? null,
                    'timestamp' => $metric['lastupdate'] ?? null,
                    'age_seconds' => $freshness['age_seconds'] ?? null,
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }

            if ($state === Severity::NO_SENSOR) {
                $issues->push(IssueBuilder::make([
                    'key' => 'sensor:' . $deviceId . ':missing:' . $type,
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => Severity::NO_SENSOR,
                    'priority' => IssueBuilder::PRIORITY_INFORMATIONAL,
                    'source' => 'sensor',
                    'type' => $type,
                    'title' => (string) $metric['label'],
                    'description' => $description,
                    'actionable' => false,
                    'device_url' => $deviceUrl,
                ]));
            }

            if ($staleIsUrgent
                && $state === Severity::HEALTHY
                && ($metric['stale'] ?? false)
                && ($metric['curated'] ?? false)
            ) {
                $issues->push(IssueBuilder::make([
                    'key' => 'sensor:' . $deviceId . ':' . $sensorId . ':stale',
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => Severity::STALE,
                    'priority' => IssueBuilder::PRIORITY_STALE,
                    'source' => 'sensor',
                    'type' => 'stale',
                    'title' => 'Sensor stale',
                    'description' => 'Sensor stale — ' . ($freshness['reason'] ?? 'last update unavailable'),
                    'value' => $metric['current_value'] ?? null,
                    'unit' => $metric['unit'] ?? null,
                    'timestamp' => $metric['lastupdate'] ?? null,
                    'age_seconds' => $freshness['age_seconds'] ?? null,
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        if ($serviceCounts) {
            foreach ($serviceProblems as $service) {
                $severity = match ((int) $service['status']) {
                    2 => Severity::CRITICAL,
                    1 => Severity::WARNING,
                    default => Severity::UNKNOWN,
                };
                $changed = $service['changed'] instanceof Carbon ? $service['changed'] : null;
                $ageSeconds = $changed !== null ? max(0, now()->timestamp - $changed->timestamp) : null;
                $description = 'Service ' . $service['name'] . ' — ' . Str::title(Str::lower($service['status_label']));

                if ($ageSeconds !== null) {
                    $description .= ' for ' . $this->formatDuration((float) $ageSeconds);
                }

                if ($service['message'] !== '') {
                    $description .= ' — ' . $service['message'];
                }

                $issues->push(IssueBuilder::make([
                    'key' => 'service:' . $deviceId . ':' . $service['service_id'] . ':' . $service['status'],
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $severity,
                    'source' => 'service',
                    'type' => 'service',
                    'title' => 'Service ' . $service['name'],
                    'description' => $description,
                    'timestamp' => $changed?->format('Y-m-d H:i:s'),
                    'age_seconds' => $ageSeconds,
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        if ($alertsCount) {
            foreach ($alerts as $alert) {
                $name = trim((string) $alert['name']);
                $isDownDuplicate = (int) $device->status === 0
                    && preg_match('/\b(?:device|host)\b.*\b(?:down|unreachable)\b/i', $name) === 1;

                if ($isDownDuplicate) {
                    continue;
                }

                $severity = ($alert['severity_class'] ?? '') === Severity::CRITICAL
                    ? Severity::CRITICAL
                    : Severity::WARNING;
                $issues->push(IssueBuilder::make([
                    'key' => 'alert:' . $deviceId . ':' . md5($name),
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $severity,
                    'source' => 'alert',
                    'type' => 'alert',
                    'title' => 'Active alert',
                    'description' => 'Active alert — ' . ($name !== '' ? $name : 'rule name unavailable'),
                    'timestamp' => $alert['timestamp'] ?? null,
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        return $issues
            ->unique('key')
            ->sortBy(fn (array $issue): array => [$issue['priority'], $issue['key']])
            ->values();
    }

    /**
     * Fleet-wide "what to check first" feed — one row per unhealthy
     * device (not one row per sensor/alert/service), sorted worst-
     * first, each naming the single specific cause a NOC operator
     * would look at first. Deliberately one row per device rather than
     * one per individual issue: a PDU with three simultaneous problems
     * should occupy one slot in a limited top-N list, not crowd out
     * three other genuinely distinct devices.
     */
    private function buildPriorityAttention(
        Collection $devices,
        int $limit = 20
    ): array {
        $items = $devices
            ->map(function (array $device): ?array {
                $actionable = $device['issues']
                    ->filter(fn (array $issue): bool => $issue['actionable']
                        && ! ($issue['source'] === 'alert' && $issue['severity'] === Severity::WARNING))
                    ->sortBy(fn (array $issue): array => [$issue['priority'], $issue['key']])
                    ->values();
                $primary = $actionable->first();

                if ($primary === null) {
                    return null;
                }

                return $primary + [
                    'icon' => Severity::metadata($primary['severity'], $primary['severity'] === Severity::STALE)['icon'],
                    'cause' => $primary['description'],
                    'since' => $this->relativeTime($primary['timestamp']),
                    'location' => $device['location'],
                    'device_name' => $device['name'],
                    'role' => $device['role'],
                    'category' => $device['category'],
                    'additional_count' => max(0, $actionable->count() - 1),
                    'all_issues' => $actionable,
                ];
            })
            ->filter()
            ->values();

        $sorted = $items
            ->sortBy(fn (array $item): array => [
                $item['priority'],
                $item['device_name'],
            ])
            ->values();

        return [
            'items' => $sorted->take($limit)->all(),
            'total' => $sorted->count(),
        ];
    }

    private function relativeTime(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildLocationGroups(
        Collection $devices
    ): Collection {
        return $devices
            ->groupBy('location')
            ->map(function (
                Collection $locationDevices,
                string $location
            ): array {
                $sortedDevices = $this->sortDevices(
                    $locationDevices->values()
                );

                $critical = $sortedDevices
                    ->where('health', 'critical')
                    ->count();

                $warning = $sortedDevices
                    ->where('health', 'warning')
                    ->count();

                $unknown = $sortedDevices
                    ->where('health', 'unknown')
                    ->count();
                $stale = $sortedDevices->where('health', Severity::STALE)->count();
                $maintenance = $sortedDevices->where('health', Severity::MAINTENANCE)->count();
                $health = Severity::worst($sortedDevices->pluck('health'), true);
                $temperatureMax = $this->maximumTelemetryValue($sortedDevices, 'temperature');
                $humidityMax = $this->maximumTelemetryValue($sortedDevices, 'humidity');
                $lastUpdated = $sortedDevices
                    ->pluck('last_polled')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'location_id' => $sortedDevices
                        ->first()['location_id'],

                    'name' => $location,
                    'total' => $sortedDevices->count(),

                    'up' => $sortedDevices
                        ->where('status', 1)
                        ->count(),

                    'down' => $sortedDevices
                        ->filter(fn (array $device): bool => $device['status'] === 0 && ! $device['maintenance'])
                        ->count(),

                    'critical' => $critical,
                    'warning' => $warning,
                    'unknown' => $unknown,
                    'stale' => $stale,
                    'maintenance' => $maintenance,

                    'issue_count' => $sortedDevices
                        ->where('has_issue', true)
                        ->count(),

                    'temperature_max' => $temperatureMax,
                    'humidity_max' => $humidityMax,
                    'power_affected' => $sortedDevices
                        ->filter(fn (array $device): bool => $device['category'] === 'Power' && $device['has_issue'])
                        ->count(),
                    'services_affected' => $sortedDevices->sum('service_problem_count'),
                    'no_sensor' => $sortedDevices->sum('no_sensor_count'),
                    'last_updated' => $lastUpdated,

                    'health' => $health,
                    'devices' => $sortedDevices,
                ];
            })
            ->sortBy(function (array $location): string {
                return $this->healthPriority(
                    $location['health']
                ) . '-' . $location['name'];
            })
            ->values();
    }

    private function maximumTelemetryValue(Collection $devices, string $class): ?float
    {
        $values = $devices
            ->flatMap(fn (array $device): iterable => $device['telemetry'])
            ->filter(function (array $metric) use ($class): bool {
                $label = Str::lower((string) ($metric['label'] ?? ''));

                return str_contains($label, $class)
                    && isset($metric['current_value'])
                    && is_numeric($metric['current_value']);
            })
            ->pluck('current_value')
            ->map(fn ($value): float => (float) $value);

        return $values->isEmpty() ? null : (float) $values->max();
    }

    private function sortDevices(
        Collection $devices
    ): Collection {
        return $devices
            ->sortBy(function (array $device): string {
                return $this->healthPriority(
                    $device['health']
                )
                    . '-'
                    . $this->rolePriority($device['role'])
                    . '-'
                    . $device['name'];
            })
            ->values();
    }

    private function healthPriority(string $health): string
    {
        return str_pad((string) Severity::metadata($health, $health === Severity::STALE)['rank'], 3, '0', STR_PAD_LEFT);
    }

    private function rolePriority(string $role): string
    {
        return match ($role) {
            'firewall' => '0',
            'switch' => '1',
            'wireless' => '2',
            'server' => '3',
            'management' => '4',
            'pdu' => '5',
            'ups' => '6',
            'printer' => '7',
            'appliance' => '8',
            default => '9',
        };
    }

    private function isIdfLocation(string $location): bool
    {
        return preg_match(
            '/^IDF[0-9]{2}(?:[A-Z]|M)?$/',
            $location
        ) === 1;
    }

    private function deviceName(object $device): string
    {
        $display = trim((string) ($device->display ?? ''));

        if ($display !== '') {
            return $display;
        }

        $sysName = trim((string) ($device->sysName ?? ''));

        if ($sysName !== '') {
            return $sysName;
        }

        return (string) $device->hostname;
    }

    private function deviceRole(
        string $name,
        string $type,
        string $hardware = '',
        string $sysDescr = ''
    ): string {
        $identity = strtoupper($name . ' ' . $hardware . ' ' . $sysDescr);

        if (preg_match('/(?<![A-Z0-9])PDU(?![A-Z0-9])/', $identity) === 1
            || str_contains($identity, 'POWER DISTRIBUTION UNIT')
        ) {
            return 'pdu';
        }

        if (preg_match('/(?<![A-Z0-9])UPS(?![A-Z0-9])/', $identity) === 1
            || str_contains($identity, 'UNINTERRUPTIBLE POWER')
        ) {
            return 'ups';
        }

        return match ($type) {
            'network' => 'switch',
            'server' => 'server',
            'firewall' => 'firewall',
            'wireless' => 'wireless',
            'management' => 'management',
            'printer' => 'printer',
            'appliance' => 'appliance',
            'power' => 'power',
            default => $type !== '' ? $type : 'device',
        };
    }

    /**
     * Curated telemetry per role, plus any other sensor LibreNMS
     * discovered on the device that the curated view did not already
     * consume. This is what closes the "only power-type devices get
     * sensors" gap: a switch or server with its own temperature/fan
     * sensor is now surfaced too, instead of being silently ignored.
     */
    private function buildTelemetry(
        string $role,
        Collection $sensors
    ): array {
        $curated = [];

        if ($role === 'pdu') {
            $curated = [
                $this->sensorMetric(
                    $sensors,
                    'temperature',
                    [],
                    'Temperature',
                    'temperature'
                ),

                $this->sensorMetric(
                    $sensors,
                    'humidity',
                    [],
                    'Humidity',
                    'humidity'
                ),
            ];
        } elseif ($role === 'ups') {
            $curated = [
                $this->sensorMetric(
                    $sensors,
                    'voltage',
                    ['input'],
                    'Input Voltage',
                    'voltage'
                ),

                $this->batteryChargeMetric($sensors),
            ];
        }

        $usedSensorIds = collect($curated)
            ->pluck('sensor_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $generic = $this->genericTelemetry($sensors, $usedSensorIds);

        /*
         * Flags each metric as 'curated' (the specific power reading
         * this role is defined by — Temperature/Humidity for a PDU,
         * Input Voltage/Battery Charge for a UPS) or not. Real fleet
         * data showed why this distinction matters: broadening sensor
         * collection to every class (see the comment in data()) gave
         * a single PDU/UPS dozens of telemetry entries instead of 2-4
         * (per-phase current, count, state, frequency, ...) — with
         * every entry treated equally, a stale *ancillary* sensor
         * (e.g. an unused phase's current reading, or a "Telnet
         * Enabled" state flag) was enough to mark the whole device
         * Warning via $staleIsUrgent in normalizeDevice(), even with
         * its real power telemetry fully fresh and healthy. Real
         * numbers: this alone was responsible for 64 of the fleet's
         * Warning devices. normalizeDevice() now only lets *curated*
         * stale-but-healthy telemetry drive that escalation — losing
         * visibility into the reading this dashboard actually curates
         * a device by is still a real monitoring gap worth a Warning;
         * losing it on one of a dozen ancillary per-phase/enum
         * sensors is not.
         */
        $curated = array_map(
            function (array $metric) use ($role): array {
                $metric['curated'] = true;

                if (isset($metric['freshness']) && is_array($metric['freshness'])) {
                    $metric['freshness']['actionable'] = $metric['freshness']['state'] === Freshness::STALE
                        && $this->staleEnabled
                        && in_array($role, self::STALE_IS_URGENT_ROLES, true);
                }

                return $metric;
            },
            $curated
        );

        $generic = array_map(
            fn (array $metric): array => $metric + ['curated' => false],
            $generic
        );

        return array_merge($curated, $generic);
    }

    /**
     * Storage/Memory/Processor, one metric per device per resource
     * type (not curated by role — any device with rows in these
     * tables gets them, switch or server alike). Each picks the worst
     * instance among that device's rows using the same severity-then-
     * value "worst of N" precedence sensorMetric() already uses for
     * sensors — see resourceUsageState() for the severity policy
     * itself. Marked 'curated' => false: losing visibility into disk/
     * memory/CPU going stale is not treated as urgent as losing a
     * PDU/UPS's actual curated power reading (see the big comment on
     * $staleIsUrgent in buildTelemetry() above) — and in any case none
     * of these three tables carry a per-row last-polled timestamp to
     * evaluate staleness against (unlike `sensors.lastupdate`), so
     * `stale` is always false here; freshness for these three is only
     * as good as the device's own last successful poll.
     */
    private function buildResourceTelemetry(
        Collection $storage,
        Collection $mempools,
        Collection $processors
    ): array {
        $metrics = [
            $this->resourceMetric(
                $storage,
                'Storage',
                fn (object $row): float => (float) $row->storage_perc,
                fn (object $row): ?int => $row->storage_perc_warn !== null ? (int) $row->storage_perc_warn : null,
                fn (object $row): string => (string) $row->storage_descr,
                fn (object $row): int => (int) $row->storage_id,
                $this->storageCriticalPercent
            ),
            $this->resourceMetric(
                $mempools,
                'Memory',
                fn (object $row): float => (float) $row->mempool_perc,
                fn (object $row): ?int => $row->mempool_perc_warn !== null ? (int) $row->mempool_perc_warn : null,
                fn (object $row): string => (string) $row->mempool_descr,
                fn (object $row): int => (int) $row->mempool_id,
                $this->memoryCriticalPercent
            ),
            $this->resourceMetric(
                $processors,
                'Processor',
                fn (object $row): float => (float) $row->processor_usage,
                fn (object $row): ?int => $row->processor_perc_warn !== null ? (int) $row->processor_perc_warn : null,
                fn (object $row): string => (string) $row->processor_descr,
                fn (object $row): int => (int) $row->processor_id,
                $this->processorCriticalPercent
            ),
        ];

        return array_map(
            fn (array $metric): array => $metric + ['curated' => false],
            array_filter(
                $metrics,
                fn (array $metric): bool => $metric['state'] !== 'missing'
            )
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  \Closure(object): float  $percOf
     * @param  \Closure(object): ?int  $percWarnOf
     * @param  \Closure(object): string  $descrOf
     * @param  \Closure(object): int  $idOf
     */
    private function resourceMetric(
        Collection $rows,
        string $label,
        \Closure $percOf,
        \Closure $percWarnOf,
        \Closure $descrOf,
        \Closure $idOf,
        int $criticalPercent
    ): array {
        // Silently absent when the fleet has no rows for this
        // resource at all (e.g. a network with no polled processors)
        // — 'missing' is filtered out by buildResourceTelemetry()
        // above rather than rendered as "No sensor installed" noise
        // on every single device.
        if ($rows->isEmpty()) {
            return ['label' => $label, 'value' => '', 'state' => 'missing', 'stale' => false, 'description' => '', 'lastupdate' => null, 'sensor_id' => null];
        }

        $statePriority = ['critical' => 0, 'warning' => 1, 'unknown' => 2, 'healthy' => 3];

        $scored = $rows->map(function (object $row) use ($percOf, $percWarnOf, $descrOf, $idOf, $criticalPercent): array {
            $perc = $percOf($row);
            $descr = $descrOf($row);

            return [
                'perc' => $perc,
                'descr' => $descr,
                'id' => $idOf($row),
                'state' => $this->resourceUsageState($perc, $percWarnOf($row), $criticalPercent, $descr),
            ];
        });

        $instanceCount = $scored->count();

        $worst = $scored
            ->sortBy(fn (array $row): array => [
                $statePriority[$row['state']] ?? 4,
                -$row['perc'],
            ])
            ->first();

        $displayLabel = $instanceCount > 1
            ? $label . ' (worst of ' . $instanceCount . ')'
            : $label;
        $worstSource = $rows->first(
            fn (object $row): bool => $idOf($row) === $worst['id']
        );
        $warningThreshold = $worstSource !== null ? $percWarnOf($worstSource) : null;
        $resourceThreshold = match ($worst['state']) {
            Severity::CRITICAL => (float) $criticalPercent,
            Severity::WARNING => $warningThreshold ?? ($worst['perc'] >= $criticalPercent ? (float) $criticalPercent : null),
            default => null,
        };

        return [
            'label' => $displayLabel,
            'value' => round($worst['perc']) . '%',
            'state' => $worst['state'],
            'stale' => false,
            'description' => trim($worst['descr'], ": \t"),
            'lastupdate' => null,
            'sensor_id' => $worst['id'],
            'current_value' => $worst['perc'],
            'unit' => '%',
            'threshold' => $resourceThreshold,
            'threshold_direction' => in_array($worst['state'], [Severity::CRITICAL, Severity::WARNING], true)
                ? 'above configured limit'
                : null,
            'freshness' => Freshness::evaluate(null, $this->sensorFreshMinutes, false),
            'cause' => $displayLabel . ' ' . round($worst['perc']) . '%'
                . ($resourceThreshold !== null ? ' — above configured limit ' . $resourceThreshold . '%' : ''),
        ];
    }

    /**
     * LibreNMS's own storage/mempool/processor tables only carry a
     * single "warning" percent per entry — there is no native
     * "critical" tier for any of these three resource types (verified
     * by reading App\Models\Storage / the StoragesController query:
     * the only severity check anywhere is `storage_perc >=
     * storage_perc_warn`). This dashboard adds its own configurable
     * critical ceiling (Support/Config.php: storage/memory/
     * processor_critical_percent), same pattern as the UPS battery
     * thresholds above, since LibreNMS has none to defer to.
     *
     * Real fleet audit found EAM-SW-MDF4-STACK's `crashinfo`/
     * `crashinfo-2`/`crashinfo-3` flash partitions permanently at
     * 100% — a known, often-benign pattern on some vendors' switches
     * (a small fixed crash-dump reserve, not "the disk is filling
     * up"). Never auto-escalated to Critical regardless of the
     * configured ceiling; it can still reach Warning like any other
     * entry once it crosses the real (non-zero) `*_perc_warn`
     * threshold LibreNMS itself discovered.
     */
    private function resourceUsageState(
        float $perc,
        ?int $percWarn,
        int $criticalPercent,
        string $descr
    ): string {
        $isCrashinfo = Str::contains(Str::lower($descr), 'crashinfo');

        if ($perc >= $criticalPercent) {
            return $isCrashinfo ? 'warning' : 'critical';
        }

        // A *_perc_warn of exactly 0 is the same "never actually
        // configured" placeholder this dashboard already treats as
        // absent for sensor thresholds (see sensorState()) — real
        // fleet data showed this fleet's own mempool_perc_warn is 0
        // on every row, not a real "warn immediately" instruction.
        if ($percWarn !== null && $percWarn > 0 && $perc >= $percWarn) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Any remaining sensor class not already represented by the
     * curated metrics above, using the exact same freshness/threshold
     * logic. Classes that had a sensor row but it was already
     * consumed by the curated view are suppressed here so a UPS does
     * not show "No sensor installed" for voltage right next to its
     * own real Input Voltage reading.
     *
     * A sensor class LibreNMS reports that isn't in `$classLabels`
     * still renders — with its class name title-cased as the label
     * and raw formatting — rather than being silently dropped. This
     * list only exists to make well-known classes read nicely; it is
     * not a filter.
     */
    private function genericTelemetry(
        Collection $sensors,
        array $usedSensorIds
    ): array {
        $remaining = $sensors->reject(
            fn ($sensor): bool => in_array(
                (int) $sensor->sensor_id,
                $usedSensorIds,
                true
            )
        );

        $classes = $remaining
            ->pluck('sensor_class')
            ->unique()
            ->values();

        return $classes
            ->map(function (string $class) use ($remaining): array {
                [$label, $format] = $this->sensorPresentation($class);

                return $this->sensorMetric(
                    $remaining,
                    $class,
                    [],
                    $label,
                    $format
                );
            })
            // A class with only an already-curated sensor has nothing
            // left after exclusion; do not render a misleading
            // "No sensor installed" badge for it.
            ->reject(fn (array $metric): bool => $metric['state'] === 'missing')
            ->values()
            ->all();
    }

    /** @return array{0: string, 1: string} */
    private function sensorPresentation(string $class): array
    {
        return match ($class) {
            'temperature' => ['Temperature', 'temperature'],
            'humidity' => ['Humidity', 'humidity'],
            'voltage' => ['Voltage', 'voltage'],
            'fanspeed' => ['Fan Speed', 'rpm'],
            'runtime' => ['Runtime', 'duration'],
            'state' => ['State', 'raw'],
            'charge' => ['Battery', 'percent'],
            'current' => ['Current', 'amps'],
            'power' => ['Power', 'watts'],
            'frequency' => ['Frequency', 'hertz'],
            'signal', 'dbm' => ['Signal', 'dbm'],
            'load', 'percent' => ['Utilization', 'percent'],
            'count', 'pressure', 'airflow', 'power_consumed' => [Str::title(str_replace(['_', '-'], ' ', $class)), 'raw'],
            default => [Str::title(str_replace(['_', '-'], ' ', $class)), 'raw'],
        };
    }

    private function batteryChargeMetric(
        Collection $sensors
    ): array {
        $chargeSensors = $sensors
            ->where('sensor_class', 'charge')
            ->filter(function ($sensor): bool {
                $description = Str::lower(
                    (string) $sensor->sensor_descr
                );

                return Str::contains(
                    $description,
                    [
                        'battery charge',
                        'charge remaining',
                    ]
                );
            })
            ->sortByDesc('lastupdate')
            ->values();

        if ($chargeSensors->isEmpty()) {
            return $this->unavailableSensorMetric('Battery Charge', null);
        }

        $sensor = $chargeSensors->first();

        if ($this->finiteNumber($sensor->sensor_current) === null) {
            return $this->unavailableSensorMetric('Battery Charge', $sensor);
        }

        /*
         * Severity is always computed from the last known value, even
         * when it is stale. A UPS battery last reported at 0% is a
         * dead battery, not a data-quality footnote to gray out —
         * conflating "we haven't repolled recently" with "this isn't
         * really a problem" hid a real, actionable condition (see
         * AUDIT_NOTES.md). `stale` below is only a freshness flag the
         * view uses to show how old the reading is; it never changes
         * `state`.
         */
        $value = $this->finiteNumber($sensor->sensor_current);
        $freshness = Freshness::evaluate(
            $sensor->lastupdate,
            $this->sensorFreshMinutes,
            $this->staleEnabled
        );
        $stale = $freshness['state'] === Freshness::STALE;
        $alertingEnabled = $this->sensorAlertingEnabled($sensor);

        if ($alertingEnabled && $value <= $this->batteryCriticalPercent) {
            $state = 'critical';
        } elseif ($alertingEnabled && $value <= $this->batteryWarningPercent) {
            $state = 'warning';
        } else {
            $state = 'healthy';
        }

        $displayValue = number_format($value, 0) . ' %';

        if ($stale) {
            $displayValue .= ' · last known' . $this->staleAge($sensor->lastupdate);
        }

        $threshold = match ($state) {
            Severity::CRITICAL => (float) $this->batteryCriticalPercent,
            Severity::WARNING => (float) $this->batteryWarningPercent,
            default => null,
        };
        return [
            'label' => 'Battery Charge',
            'value' => $displayValue,
            'state' => $state,
            'stale' => $stale,
            'description' => (string) $sensor->sensor_descr,
            'lastupdate' => $sensor->lastupdate,
            'sensor_id' => (int) $sensor->sensor_id,
            'current_value' => $value,
            'unit' => '%',
            'threshold' => $threshold,
            'threshold_direction' => $threshold !== null ? 'below configured threshold' : null,
            'freshness' => $freshness,
            'cause' => $threshold !== null
                ? 'Battery ' . number_format($value, 0) . '% — below ' . strtolower($state) . ' threshold ' . number_format($threshold, 0) . '%'
                : 'Battery ' . number_format($value, 0) . '%',
        ];
    }

    private function sensorMetric(
        Collection $sensors,
        string $class,
        array $preferredDescriptions,
        string $label,
        string $format
    ): array {
        $classSensors = $sensors
            ->where('sensor_class', $class)
            ->values();

        if ($classSensors->isEmpty()) {
            return $this->unavailableSensorMetric($label, null);
        }

        $selected = null;
        $stale = false;
        $instanceCount = 1;

        if ($preferredDescriptions !== []) {
            foreach ($preferredDescriptions as $preferred) {
                $matches = $classSensors
                    ->filter(function ($sensor) use ($preferred): bool {
                        return Str::contains(
                            Str::lower((string) $sensor->sensor_descr),
                            Str::lower($preferred)
                        );
                    })
                    ->sortByDesc('lastupdate')
                    ->values();

                if ($matches->isEmpty()) {
                    continue;
                }

                $fresh = $matches->first(
                    fn ($sensor): bool => $this->sensorIsFresh(
                        $sensor->lastupdate
                    )
                );

                if ($fresh !== null) {
                    $selected = $fresh;
                    $stale = false;
                    break;
                }

                // Keep searching the remaining preferred descriptions
                // for a fresh candidate, but remember the first stale
                // fallback in case none of them have one.
                if ($selected === null) {
                    $selected = $matches->first();
                    $stale = true;
                }
            }
        } else {
            $matches = $classSensors
                ->sortByDesc('lastupdate')
                ->values();

            $instanceCount = $classSensors->count();

            /*
             * A class can have many instances on one device (e.g. a
             * switch with dozens of per-port optical "dbm"/"current"
             * sensors) — this view only has room to summarize the
             * class as one metric. Two failure modes to avoid here,
             * both real: picking whichever instance simply happened
             * to update most recently would let a real critical
             * reading on port 7 stay invisible if port 3 just happens
             * to poll a few seconds later; and considering only the
             * *fresh* instances would let a critical reading stay
             * invisible forever if polling for that one instance
             * broke (a real, physically-broken transceiver doesn't
             * self-heal just because LibreNMS stopped re-checking it —
             * see AUDIT_NOTES.md for the incident this generalizes).
             * So: consider every instance that has ever reported a
             * value, fresh or stale, and always surface the single
             * worst state among all of them (critical beats warning
             * beats healthy); only among instances tied on state does
             * freshness break the tie, preferring the more current one.
             */
            $withValue = $matches
                ->filter(fn ($sensor): bool => $this->finiteNumber($sensor->sensor_current) !== null)
                ->values();

            if ($withValue->isNotEmpty()) {
                $statePriority = ['critical' => 0, 'warning' => 1, 'healthy' => 2];

                $selected = $withValue
                    ->sortBy(fn ($sensor): array => [
                        $statePriority[$this->sensorState($sensor)] ?? 3,
                        $this->sensorIsFresh($sensor->lastupdate) ? 0 : 1,
                    ])
                    ->first();

                $stale = ! $this->sensorIsFresh($selected->lastupdate);
            } else {
                $selected = $matches->first();
                $stale = $selected !== null && ! $this->sensorIsFresh($selected->lastupdate);
            }
        }

        if ($selected === null || $this->finiteNumber($selected->sensor_current) === null) {
            return $this->unavailableSensorMetric($label, $selected);
        }

        /*
         * Severity always comes from the last known value, fresh or
         * not — see the comment on this same choice in
         * batteryChargeMetric(). `stale` is purely a freshness flag
         * layered onto the value text below; it never softens the
         * computed `state`.
         */
        $numericValue = $this->finiteNumber($selected->sensor_current);

        if (
            $selected->sensor_class === 'state'
            && property_exists($selected, 'state_descr')
            && $selected->state_descr !== null
            && $selected->state_descr !== ''
        ) {
            // e.g. "normalWithAlarm" instead of the raw numeric code
            // "16" — the code alone means nothing to someone glancing
            // at a NOC display.
            $value = (string) $selected->state_descr;
        } else {
            $value = $this->formatSensorValue(
                $numericValue,
                $format
            );
        }

        $freshness = Freshness::evaluate(
            $selected->lastupdate,
            $this->sensorFreshMinutes,
            $this->staleEnabled
        );
        $stale = $freshness['state'] === Freshness::STALE;

        if ($stale) {
            $value .= ' · last known' . $this->staleAge($selected->lastupdate);
        }

        $state = $this->sensorState($selected);
        $threshold = $this->sensorThreshold($selected, $state, $format);

        return [
            // Flags that this is the worst of several same-class
            // instances (e.g. "Signal (worst of 82)") so it reads as
            // a summary, not as if the device only has one such
            // sensor — the renderer's title tooltip also carries the
            // specific sensor's own description for the exact source.
            'label' => $instanceCount > 1
                ? $label . ' (worst of ' . $instanceCount . ')'
                : $label,

            'value' => $value,
            'state' => $state,
            'stale' => $stale,
            'description' => (string) $selected->sensor_descr,
            'lastupdate' => $selected->lastupdate,
            'sensor_id' => (int) $selected->sensor_id,
            'current_value' => $selected->sensor_class === 'state'
                ? ($selected->state_descr ?? $numericValue)
                : $this->displayNumber($numericValue, $format),
            'unit' => $this->sensorUnit($format),
            'threshold' => $threshold['value'],
            'threshold_direction' => $threshold['direction'],
            'freshness' => $freshness,
            'cause' => $this->sensorCause($label, $state, $selected, $numericValue, $format, $threshold),
        ];
    }

    private function unavailableSensorMetric(string $label, ?object $sensor): array
    {
        $installed = $sensor !== null;
        $freshness = $installed
            ? Freshness::evaluate($sensor->lastupdate ?? null, $this->sensorFreshMinutes, $this->staleEnabled)
            : [
                'state' => Freshness::UNKNOWN,
                'age_seconds' => null,
                'age_minutes' => null,
                'timestamp' => null,
                'label' => 'Unknown',
                'actionable' => false,
                'reason' => 'No sensor installed',
            ];

        return [
            'label' => $label,
            'value' => $installed ? 'Sensor unavailable' : 'No sensor installed',
            'state' => $installed ? Severity::UNKNOWN : Severity::NO_SENSOR,
            'stale' => $installed && $freshness['state'] === Freshness::STALE,
            'description' => $installed ? (string) ($sensor->sensor_descr ?? '') : '',
            'lastupdate' => $installed ? ($sensor->lastupdate ?? null) : null,
            'sensor_id' => $installed ? (int) $sensor->sensor_id : null,
            'current_value' => null,
            'unit' => null,
            'threshold' => null,
            'threshold_direction' => null,
            'freshness' => $freshness,
            'cause' => $installed ? 'Current value unavailable' : 'No ' . strtolower($label) . ' sensor installed',
        ];
    }

    private function individualSensorMetric(object $sensor, string $label, string $format): array
    {
        $numericValue = $this->finiteNumber($sensor->sensor_current ?? null);

        if ($numericValue === null) {
            return $this->unavailableSensorMetric($label, $sensor);
        }

        $state = $this->sensorState($sensor);
        $threshold = $this->sensorThreshold($sensor, $state, $format);
        $freshness = Freshness::evaluate(
            $sensor->lastupdate ?? null,
            $this->sensorFreshMinutes,
            $this->staleEnabled
        );
        $stale = $freshness['state'] === Freshness::STALE;
        $translated = $sensor->sensor_class === 'state'
            ? trim((string) ($sensor->state_descr ?? ''))
            : '';
        $value = $translated !== ''
            ? $translated
            : $this->formatSensorValue($numericValue, $format);

        if ($stale) {
            $value .= ' · last known' . $this->staleAge($sensor->lastupdate ?? null);
        }

        return [
            'label' => $label,
            'value' => $value,
            'state' => $state,
            'stale' => $stale,
            'description' => (string) ($sensor->sensor_descr ?? ''),
            'lastupdate' => $sensor->lastupdate ?? null,
            'sensor_id' => (int) $sensor->sensor_id,
            'current_value' => $sensor->sensor_class === 'state'
                ? ($translated !== '' ? $translated : $numericValue)
                : $this->displayNumber($numericValue, $format),
            'unit' => $this->sensorUnit($format),
            'threshold' => $threshold['value'],
            'threshold_direction' => $threshold['direction'],
            'freshness' => $freshness,
            'cause' => $this->sensorCause($label, $state, $sensor, $numericValue, $format, $threshold),
        ];
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /** @return array{value: float|null, direction: string|null} */
    private function sensorThreshold(object $sensor, string $state, string $format): array
    {
        if (! in_array($state, [Severity::CRITICAL, Severity::WARNING], true)) {
            return ['value' => null, 'direction' => null];
        }

        $restingAtZero = $sensor->sensor_class === 'current'
            || $this->isOnBatteryDurationSensor($sensor);
        $evaluation = IssueBuilder::evaluateNumericSensor(
            $sensor->sensor_current,
            $sensor->sensor_limit ?? null,
            $sensor->sensor_limit_warn ?? null,
            $sensor->sensor_limit_low ?? null,
            $sensor->sensor_limit_low_warn ?? null,
            $restingAtZero
        );

        if ($evaluation['severity'] === $state && $evaluation['threshold'] !== null) {
            return [
                'value' => $this->displayNumber($evaluation['threshold'], $format),
                'direction' => $evaluation['direction'],
            ];
        }

        return ['value' => null, 'direction' => null];
    }

    /** @param array{value: float|null, direction: string|null} $threshold */
    private function sensorCause(
        string $label,
        string $state,
        object $sensor,
        float $current,
        string $format,
        array $threshold
    ): string {
        if ($sensor->sensor_class === 'state') {
            $translated = trim((string) ($sensor->state_descr ?? ''));

            return $translated !== ''
                ? $label . ' — ' . $translated
                : $label . ' — translation unknown; raw value ' . $current;
        }

        $display = $this->displayNumber($current, $format);
        $unit = $this->sensorUnit($format);
        $cause = $label . ' ' . $display . ($unit !== null ? ' ' . $unit : '');

        if ($threshold['direction'] !== null && $threshold['value'] !== null) {
            $direction = str_replace(
                ['above critical high', 'above warning high', 'below critical low', 'below warning low'],
                ['above high limit', 'above warning limit', 'below low limit', 'below warning limit'],
                $threshold['direction']
            );
            $cause .= ' — ' . $direction . ' ' . $threshold['value'] . ($unit !== null ? ' ' . $unit : '');
        } elseif (in_array($state, [Severity::CRITICAL, Severity::WARNING], true)) {
            $cause .= ' — threshold not configured';
        }

        return $cause;
    }

    private function displayNumber(float $value, string $format): float
    {
        return $format === 'temperature' ? round(($value * 9 / 5) + 32, 1) : $value;
    }

    private function sensorUnit(string $format): ?string
    {
        return match ($format) {
            'temperature' => '°F',
            'humidity', 'percent' => '%',
            'voltage' => 'V',
            'rpm' => 'RPM',
            'duration' => 's',
            'amps' => 'A',
            'watts' => 'W',
            'hertz' => 'Hz',
            'dbm' => 'dBm',
            default => null,
        };
    }

    /**
     * A bare "· stale" badge doesn't distinguish a sensor that missed
     * one 5-minute poll from one that hasn't reported in months — and
     * real data here showed both exist side by side (several MDF UPS
     * battery-charge sensors were found stuck for 30-78+ days while
     * the device itself stayed reachable). Appending the actual age
     * turns "stale" from a vague caveat into the same "hasn't reported
     * since X" signal a NOC would want.
     */
    private function staleAge(mixed $lastUpdate): string
    {
        if ($lastUpdate === null || $lastUpdate === '') {
            return '';
        }

        try {
            return ' (' . Carbon::parse($lastUpdate)->diffForHumans() . ')';
        } catch (\Throwable) {
            return '';
        }
    }

    private function sensorIsFresh(mixed $lastUpdate): bool
    {
        $freshness = Freshness::evaluate(
            $lastUpdate,
            $this->sensorFreshMinutes,
            $this->staleEnabled
        );

        return in_array($freshness['state'], [Freshness::FRESH, Freshness::AGING], true);
    }

    /**
     * LibreNMS lets an administrator disable alerting on an
     * individual sensor (sensor_alert = 0) without removing the
     * sensor itself, for example a UPS that is known to run warm.
     * Ignoring that flag and recomputing severity purely from the raw
     * threshold columns was a real source of false criticals: this
     * dashboard would light up red for a sensor the NOC team already
     * chose to silence in LibreNMS. When the flag is absent (older
     * schemas / never set) it defaults to enabled, preserving the
     * previous behaviour.
     */
    private function sensorAlertingEnabled(object $sensor): bool
    {
        if (! property_exists($sensor, 'sensor_alert') || $sensor->sensor_alert === null) {
            return true;
        }

        return (int) $sensor->sensor_alert === 1;
    }

    /**
     * A real fleet audit (see AUDIT_NOTES.md) found a second class of
     * false critical distinct from the "threshold left at 0" one: the
     * standard UPS-MIB "time on battery" sensor (upsSecondsOnBattery,
     * LibreNMS class 'runtime') is a countdown of how long the UPS has
     * *currently* been running on battery power — 0 is the normal,
     * healthy value (mains power is fine), and only a *high* value
     * (an ongoing, lengthening outage) is a real problem. LibreNMS's
     * discovered `sensor_limit_low`/`sensor_limit_low_warn` for this
     * exact sensor were both 0, so the generic low-threshold check
     * ("critical if current <= 0") fired on every single healthy UPS
     * — the opposite of what this sensor's 0 actually means. This is
     * unrelated to (and just as real as) the "runtime" class also
     * legitimately containing "Estimated battery time remaining",
     * where 0 really is a dead battery — that sensor is left
     * untouched; only the on-battery-duration counter is exempted
     * from the low-threshold checks in sensorState().
     */
    private function isOnBatteryDurationSensor(object $sensor): bool
    {
        if ($sensor->sensor_class !== 'runtime') {
            return false;
        }

        return Str::contains(
            Str::lower((string) $sensor->sensor_descr),
            'on battery'
        );
    }

    /**
     * A real fleet audit (see AUDIT_NOTES.md, "false positives" entry)
     * found that LibreNMS's own `state_translations` seed data for this
     * fleet's Vertiv/Liebert PDUs assigns severity to a handful of
     * "state" sensors that are config toggles, not health indicators —
     * and gets it backwards for how this fleet actually runs them:
     * - `Telnet Enabled` / `Velocity Server Enabled`: LibreNMS maps
     *   "no" (the service is off) to critical(2) — but a disabled
     *   management service is the *secure*, intended configuration on
     *   this fleet, not a fault. Confirmed against real data: 46 of 49
     *   real PDU/UPS devices have Telnet disabled and were all showing
     *   Critical purely from this one sensor.
     * - `State Receptacle N`: LibreNMS maps "on" to warning(1) — this
     *   MIB was evidently designed for a switched outlet expected to
     *   normally be off, but every receptacle in this fleet is a
     *   permanently-loaded, always-on outlet feeding real equipment;
     *   "on" is the correct resting state here, not a warning.
     *   Confirmed at scale: 47 devices / 603 receptacle sensors.
     * - `Webserver Mode`: same shape as the above at the same scale
     *   (46 of 49 devices report "http" → warning) — reviewed with the
     *   user and confirmed out of scope for this dashboard's severity:
     *   the admin web UI running on plain HTTP is standard config
     *   across this fleet, not something this NOC view should flag as
     *   a device-health problem.
     * Distinct from `System Status` (the sensor that motivated adding
     * state-sensor support in the first place — a real, correctly-
     * mapped alarm, re-confirmed this pass: 10 of its 36 real
     * `normalWithAlarm` readings fleet-wide are freshly polled today,
     * not stale echoes, and the original reporting device — IDF11 —
     * is among them), left untouched.
     */
    private function isNonHealthStateSensor(object $sensor): bool
    {
        if ($sensor->sensor_class !== 'state') {
            return false;
        }

        $descr = (string) $sensor->sensor_descr;

        return $descr === 'Telnet Enabled'
            || $descr === 'Velocity Server Enabled'
            || $descr === 'Webserver Mode'
            || Str::startsWith($descr, 'State Receptacle');
    }

    private function sensorState(object $sensor): string
    {
        // Callers (sensorMetric()) never invoke this on a sensor whose
        // current value is null — they resolve that to 'missing'
        // themselves. This guard only exists so this function can
        // never return the no-longer-valid 'stale' state if ever
        // called directly on such a sensor in the future; freshness
        // is tracked separately from severity throughout this file
        // (see AUDIT_NOTES.md).
        if ($this->finiteNumber($sensor->sensor_current) === null) {
            return Severity::UNKNOWN;
        }

        if (! $this->sensorAlertingEnabled($sensor)) {
            return 'healthy';
        }

        /*
         * "state" class sensors are LibreNMS enum/discrete values —
         * their sensor_limit* columns are never populated (confirmed:
         * 0 of 2,159 real "state" sensors had any threshold set). Real
         * severity comes from state_generic_value, resolved by
         * loadStateTranslations() and attached onto the sensor object
         * for the specific state_value that matches sensor_current
         * (see the comment above that call in data()).
         *
         * This branch now always returns for a state-class sensor —
         * it never falls through to the numeric logic below, which
         * would silently resolve to 'healthy' (no threshold columns
         * are ever set on a state sensor, so every numeric check below
         * is a no-op for this class). That fallthrough was itself the
         * bug: LibreNMS's own canonical severity method,
         * HasThresholds::currentStatus(), returns Severity::Unknown —
         * not Ok — when `currentTranslation()` finds no match, and its
         * own SensorState enum has a distinct Unknown=3 case separate
         * from Ok=0. A sensor whose current value has no known
         * translation, or whose translation tables aren't available in
         * this schema, is something a NOC operator should go look at —
         * "we don't know" must never render identically to "confirmed
         * fine". See `isNonHealthStateSensor()` just above for the
         * separate, narrow case of sensors that really are pure config
         * toggles (Telnet Enabled, etc.), which stay 'healthy'.
         */
        if ($sensor->sensor_class === 'state') {
            if ($this->isNonHealthStateSensor($sensor)) {
                return 'healthy';
            }

            $genericValue = property_exists($sensor, 'state_generic_value')
                ? $sensor->state_generic_value
                : null;

            return Severity::fromLibreNmsGenericState($genericValue);
        }

        $current = (float) $sensor->sensor_current;

        /*
         * A real fleet audit (see AUDIT_NOTES.md) found dozens of
         * sensors where LibreNMS discovery had left a threshold at
         * exactly 0 — not a real intended limit, just an unset
         * default that happens to compare true against almost any
         * reading. Two distinct cases, handled differently:
         *
         * - An *upper* ("too much") threshold of exactly 0 is never
         *   physically meaningful for any metric — "critical if
         *   current/power/etc. >= 0" fires on any non-negative
         *   reading at all, i.e. always. Ignored universally,
         *   regardless of sensor class.
         *
         * - A *lower* ("too little") threshold of exactly 0 can be
         *   legitimate for some classes (e.g. a freeze-warning
         *   threshold at 0 degrees C, or a UPS whose 0V/0Hz output
         *   really is dead — confirmed against real data: device 64's
         *   Output Voltage/Frequency sensors both sit at limit_low=0
         *   and are a real critical, the same dead UPS as its 0%
         *   battery). But for "current" specifically, 0 is the normal
         *   resting value of an idle/unused PDU phase — real data
         *   showed 161 such sensors, all on unpopulated legs, which
         *   is what actually caused the earlier "97 of 208 devices
         *   Critical" false-alarm spike this fix corrects. So the
         *   lower-threshold-of-zero-is-a-placeholder assumption is
         *   scoped to sensor_class === 'current' only, not applied
         *   universally. The UPS "time on battery" duration counter
         *   (see isOnBatteryDurationSensor()) gets the same treatment
         *   for the same reason — 0 is its normal healthy value too.
         */
        $restingAtZero = $sensor->sensor_class === 'current'
            || $this->isOnBatteryDurationSensor($sensor);

        return IssueBuilder::evaluateNumericSensor(
            $current,
            $sensor->sensor_limit ?? null,
            $sensor->sensor_limit_warn ?? null,
            $sensor->sensor_limit_low ?? null,
            $sensor->sensor_limit_low_warn ?? null,
            $restingAtZero
        )['severity'];
    }

    private function formatSensorValue(
        float $value,
        string $format
    ): string {
        return match ($format) {
            'temperature' => number_format(
                ($value * 9 / 5) + 32,
                1
            ) . ' °F',

            'humidity' => number_format($value, 1) . ' %',
            'voltage' => number_format($value, 1) . ' V',
            'percent' => number_format($value, 0) . ' %',
            'rpm' => number_format($value, 0) . ' RPM',
            'duration' => $this->formatDuration($value),
            'amps' => number_format($value, 2) . ' A',
            'watts' => number_format($value, 1) . ' W',
            'hertz' => number_format($value, 1) . ' Hz',
            'dbm' => number_format($value, 1) . ' dBm',
            'raw' => number_format($value, 0),
            default => number_format($value, 1),
        };
    }

    private function formatDuration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours . 'h ' . $remainingMinutes . 'm';
    }

    private function serviceStatusLabel(int $status): string
    {
        return match ($status) {
            0 => 'OK',
            1 => 'WARNING',
            2 => 'CRITICAL',
            3 => 'UNKNOWN',
            default => 'STATUS ' . $status,
        };
    }

    private function serviceStatusClass(int $status): string
    {
        return match ($status) {
            0 => 'healthy',
            1 => 'warning',
            2 => 'critical',
            default => 'unknown',
        };
    }

    /**
     * Resolves every "state"-class sensor's current numeric value to
     * LibreNMS's own human description and normalized severity via
     * sensors_to_state_indexes + state_translations (see
     * App\Models\StateTranslation / App\Models\Sensor::
     * currentTranslation() in LibreNMS core — this mirrors that same
     * lookup with a plain query instead of loading Eloquent relations).
     * Defensive like loadActiveAlerts()/loadRecentEvents(): degrades
     * to an empty result (state sensors fall back to their prior
     * "always healthy, raw number" behavior) rather than breaking the
     * dashboard if either table is missing on some install.
     */
    private function loadStateTranslations(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()
            || ! $this->tableExists('sensors_to_state_indexes')
            || ! $this->tableExists('state_indexes')
            || ! $this->tableExists('state_translations')
        ) {
            return collect();
        }

        try {
            return DB::table('sensors as s')
                ->join('sensors_to_state_indexes as ssi', 'ssi.sensor_id', '=', 's.sensor_id')
                ->join('state_indexes as si', 'si.state_index_id', '=', 'ssi.state_index_id')
                ->join('state_translations as st', function ($join): void {
                    $join->on('st.state_index_id', '=', 'ssi.state_index_id')
                        ->whereColumn('st.state_value', '=', 's.sensor_current');
                })
                ->whereIn('s.device_id', $deviceIds)
                ->where('s.sensor_class', 'state')
                ->where('s.sensor_deleted', 0)
                ->select(['s.sensor_id', 'si.state_name', 'st.state_descr', 'st.state_generic_value'])
                ->get()
                ->keyBy('sensor_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Active, still-open LibreNMS alerts for the given devices. Table
     * and column names are resolved defensively because they differ
     * slightly across LibreNMS releases; when the expected shape is
     * not found this returns available=false rather than throwing,
     * so a schema mismatch degrades to an honest "N/A" instead of a
     * broken dashboard. See AUDIT_NOTES.md.
     */
    private function loadActiveAlerts(Collection $deviceIds): array
    {
        $unavailable = ['available' => false, 'byDevice' => collect()];

        if ($deviceIds->isEmpty() || ! $this->tableExists('alerts')) {
            return $unavailable;
        }

        $deviceCol = $this->firstExistingColumn('alerts', ['device_id']);
        $stateCol = $this->firstExistingColumn('alerts', ['state']);
        $ruleCol = $this->firstExistingColumn('alerts', ['rule_id']);
        $openCol = $this->firstExistingColumn('alerts', ['open']);
        $timeCol = $this->firstExistingColumn('alerts', ['timestamp', 'time_logged']);

        if ($deviceCol === null || $stateCol === null || $ruleCol === null) {
            return $unavailable;
        }

        $hasRules = $this->tableExists('alert_rules');
        $nameCol = $hasRules
            ? $this->firstExistingColumn('alert_rules', ['name'])
            : null;
        $severityCol = $hasRules
            ? $this->firstExistingColumn('alert_rules', ['severity'])
            : null;

        try {
            $query = DB::table('alerts as a')
                ->whereIn("a.$deviceCol", $deviceIds)
                ->where("a.$stateCol", 1);

            if ($openCol !== null) {
                $query = $query->where("a.$openCol", 1);
            }

            $select = [
                "a.$deviceCol as device_id",
                "a.$stateCol as state",
            ];

            if ($timeCol !== null) {
                $select[] = "a.$timeCol as timestamp";
            }

            if ($hasRules && $nameCol !== null) {
                $query = $query->leftJoin('alert_rules as r', 'r.id', '=', "a.$ruleCol");
                $select[] = "r.$nameCol as rule_name";

                if ($severityCol !== null) {
                    $select[] = "r.$severityCol as severity";
                }
            }

            $rows = $query->select($select)->get();
        } catch (\Throwable) {
            return $unavailable;
        }

        $byDevice = $rows
            ->reject(function ($row): bool {
                $name = Str::lower(trim((string) ($row->rule_name ?? '')));

                return $name !== '' && Str::contains($name, self::REDUNDANT_ALERT_RULE_NAMES);
            })
            ->map(function ($row): array {
                $severity = Str::lower(trim((string) ($row->severity ?? '')));
                $isCritical = $severity !== '' && Str::contains(
                    $severity,
                    ['crit', 'high', 'error']
                );

                return [
                    'device_id' => (int) $row->device_id,
                    'name' => trim((string) ($row->rule_name ?? '')) !== ''
                        ? trim((string) $row->rule_name)
                        : 'Active alert',
                    'severity' => $severity !== '' ? $severity : 'warning',
                    'severity_class' => $isCritical ? 'critical' : 'warning',
                    'timestamp' => $row->timestamp ?? null,
                ];
            })
            ->groupBy('device_id');

        return ['available' => true, 'byDevice' => $byDevice];
    }

    /**
     * Recent event log entries for the given devices, used only as
     * an informational coverage signal (is the eventlog pipeline
     * active for this device) and never to raise severity, which
     * would risk false criticals from historical/benign log entries.
     */
    private function loadRecentEvents(Collection $deviceIds): array
    {
        $unavailable = [
            'available' => false,
            'complete' => false,
            'byDevice' => collect(),
        ];

        if ($deviceIds->isEmpty() || ! $this->tableExists('eventlog')) {
            return $unavailable;
        }

        $deviceCol = $this->firstExistingColumn(
            'eventlog',
            ['device_id', 'host_id', 'host']
        );

        $timeCol = $this->firstExistingColumn(
            'eventlog',
            ['datetime', 'timestamp']
        );

        $messageCol = $this->firstExistingColumn('eventlog', ['message']);

        if ($deviceCol === null || $timeCol === null) {
            return $unavailable;
        }

        try {
            $since = Carbon::now()->subHours($this->eventWindowHours);

            $select = [
                "$deviceCol as device_id",
                "$timeCol as event_time",
            ];

            if ($messageCol !== null) {
                $select[] = "$messageCol as message";
            }

            $grammar = DB::connection()->getQueryGrammar();
            $wrappedDevice = $grammar->wrap($deviceCol);
            $wrappedTime = $grammar->wrap($timeCol);

            $ranked = DB::table('eventlog')
                ->whereIn($deviceCol, $deviceIds)
                ->where($timeCol, '>=', $since)
                ->select($select)
                ->selectRaw(
                    "ROW_NUMBER() OVER (PARTITION BY $wrappedDevice ORDER BY $wrappedTime DESC) as event_rank"
                );

            $requestedLimit = $deviceIds->count() * self::RECENT_EVENTS_PER_DEVICE;
            $globalLimit = min(self::RECENT_EVENTS_GLOBAL_LIMIT, $requestedLimit);

            $rows = DB::query()
                ->fromSub($ranked, 'ranked_events')
                ->where('event_rank', '<=', self::RECENT_EVENTS_PER_DEVICE)
                ->orderByDesc('event_time')
                ->limit($globalLimit)
                ->get();
        } catch (\Throwable) {
            return $unavailable;
        }

        $byDevice = $rows
            ->map(fn ($row): array => [
                'device_id' => (int) $row->device_id,
                'time' => $row->event_time,
                'message' => Str::limit(
                    trim((string) ($row->message ?? '')),
                    140
                ),
            ])
            ->groupBy('device_id');

        return [
            'available' => true,
            'complete' => $requestedLimit <= self::RECENT_EVENTS_GLOBAL_LIMIT,
            'byDevice' => $byDevice,
        ];
    }

    /**
     * Current outages and reliable recent recoveries from LibreNMS's
     * device_outages table. Both queries are grouped in SQL and restricted
     * to the already-authorized IDs; alerts are never used as a substitute
     * for outage timestamps.
     *
     * @return array{current: Collection, recovered: Collection}
     */
    private function loadDeviceOutages(Collection $deviceIds): array
    {
        $empty = ['current' => collect(), 'recovered' => collect()];

        if ($deviceIds->isEmpty() || ! $this->tableExists('device_outages')) {
            return $empty;
        }

        try {
            $current = DB::table('device_outages')
                ->whereIn('device_id', $deviceIds)
                ->whereNull('up_again')
                ->selectRaw('device_id, MAX(going_down) as going_down')
                ->groupBy('device_id')
                ->get()
                ->keyBy('device_id');

            $recovered = DB::table('device_outages')
                ->whereIn('device_id', $deviceIds)
                ->whereNotNull('up_again')
                ->where('up_again', '>=', Carbon::now()->subHours($this->eventWindowHours)->timestamp)
                ->selectRaw('device_id, MAX(up_again) as up_again')
                ->groupBy('device_id')
                ->get()
                ->keyBy('device_id');

            return ['current' => $current, 'recovered' => $recovered];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * LibreNMS 26.8 stores precomputed availability windows in its core
     * availability table. Loading them once for authorized IDs avoids RRD
     * access and avoids a relationship query for every device.
     */
    private function loadAvailability(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty() || ! $this->tableExists('availability')) {
            return collect();
        }

        try {
            return DB::table('availability')
                ->whereIn('device_id', $deviceIds)
                ->select(['device_id', 'duration', 'availability_perc'])
                ->orderBy('duration')
                ->get()
                ->groupBy('device_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Resolve active maintenance without DeviceMaintenanceCache: that core
     * cache intentionally loads every scheduled device, while this plugin's
     * security boundary requires every query to stay inside the authorized
     * device IDs. Direct-device, location and device-group schedules are
     * therefore resolved by three fixed, scoped queries (never per device).
     */
    private function loadMaintenanceDevices(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()
            || ! $this->tableExists('alert_schedule')
            || ! $this->tableExists('alert_schedulables')
        ) {
            return collect();
        }

        try {
            $direct = AlertSchedule::query()
                ->isActive()
                ->join('alert_schedulables as scheduled', 'scheduled.schedule_id', '=', 'alert_schedule.schedule_id')
                ->where('scheduled.alert_schedulable_type', 'device')
                ->whereIn('scheduled.alert_schedulable_id', $deviceIds)
                ->selectRaw('scheduled.alert_schedulable_id as device_id, alert_schedule.behavior, alert_schedule.title')
                ->get();

            $locations = AlertSchedule::query()
                ->isActive()
                ->join('alert_schedulables as scheduled', 'scheduled.schedule_id', '=', 'alert_schedule.schedule_id')
                ->join('devices as maintenance_devices', 'maintenance_devices.location_id', '=', 'scheduled.alert_schedulable_id')
                ->where('scheduled.alert_schedulable_type', 'location')
                ->whereIn('maintenance_devices.device_id', $deviceIds)
                ->selectRaw('maintenance_devices.device_id, alert_schedule.behavior, alert_schedule.title')
                ->get();

            $groups = collect();

            if ($this->tableExists('device_group_device')) {
                $groups = AlertSchedule::query()
                    ->isActive()
                    ->join('alert_schedulables as scheduled', 'scheduled.schedule_id', '=', 'alert_schedule.schedule_id')
                    ->join('device_group_device as grouped_devices', 'grouped_devices.device_group_id', '=', 'scheduled.alert_schedulable_id')
                    ->where('scheduled.alert_schedulable_type', 'device_group')
                    ->whereIn('grouped_devices.device_id', $deviceIds)
                    ->selectRaw('grouped_devices.device_id, alert_schedule.behavior, alert_schedule.title')
                    ->get();
            }

            return $direct
                ->concat($locations)
                ->concat($groups)
                ->sortBy('behavior')
                ->keyBy('device_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Storage/Memory/Processor — real fleet audit confirmed 84/90/85
     * active devices respectively have rows in these core LibreNMS
     * tables (`storage`, `mempools`, `processors`), previously never
     * read by this plugin at all. Grouped by device_id, same pattern
     * as $sensorMap/$serviceMap — one query each, no N+1.
     */
    private function loadStorage(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty() || ! $this->tableExists('storage')) {
            return collect();
        }

        try {
            return DB::table('storage')
                ->whereIn('device_id', $deviceIds)
                ->select([
                    'storage_id',
                    'device_id',
                    'storage_type',
                    'storage_descr',
                    'storage_perc',
                    'storage_perc_warn',
                ])
                ->get()
                ->groupBy('device_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function loadMempools(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty() || ! $this->tableExists('mempools')) {
            return collect();
        }

        try {
            return DB::table('mempools')
                ->whereIn('device_id', $deviceIds)
                ->where('mempool_deleted', 0)
                ->select([
                    'mempool_id',
                    'device_id',
                    'mempool_descr',
                    'mempool_perc',
                    'mempool_perc_warn',
                ])
                ->get()
                ->groupBy('device_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function loadProcessors(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty() || ! $this->tableExists('processors')) {
            return collect();
        }

        try {
            return DB::table('processors')
                ->whereIn('device_id', $deviceIds)
                ->select([
                    'processor_id',
                    'device_id',
                    'processor_descr',
                    'processor_usage',
                    'processor_perc_warn',
                ])
                ->get()
                ->groupBy('device_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            try {
                if (Schema::hasColumn($table, $candidate)) {
                    return $candidate;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
