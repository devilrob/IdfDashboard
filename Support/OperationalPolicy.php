<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use App\Plugins\IdfDashboard\Page;
use Illuminate\Support\Collection;

/**
 * Layer 3 — Operational Policy. This class exists to answer exactly one
 * question, and nothing else: "given a technical condition LibreNMS has
 * already observed (Layer 1/2), and given that no administrator-selected
 * LibreNMS Alert Rule already covers THIS SAME CONDITION for this
 * device, what operational severity should this dashboard show —
 * without inventing a Critical that was never asked for, and without
 * ever hiding a real technical failure?"
 *
 * This is deliberately NOT a second severity engine competing with
 * Support\AlertRules. It is the opposite: a narrow, clearly-labeled
 * safety net, evaluated independently per technical CONDITION rather
 * than once per device or once per category.
 *
 * Suppression requires EXACT structured identity, never a category
 * label. Device Down is the one condition where that exact identity is
 * trivially available: there is only one "device unreachable" condition
 * per device, and an Alert-Rule-sourced issue already carries that same
 * real device_id — see $coveredCategories/AlertRules::CATEGORY_DEVICE_DOWN
 * below. A numeric/state sensor condition and a service condition each
 * have their OWN identity (a specific sensor_id / service_id), but an
 * Alert-Rule-sourced issue in this schema carries none of those (see
 * Page::buildDeviceIssues() — no sensor_id/service_id survives onto an
 * alert issue, only device_id and the rule's own name/severity). An
 * administrator can still tag a rule as "covers Sensors" or "covers
 * Services" in Settings (AlertRules::resolveConditionCoverage()) — that
 * tag remains useful for Settings/Policy Health as a documented,
 * administrative statement of INTENT — but it is deliberately never
 * used here to suppress a sensor/service fallback, because a category
 * tag is not an exact identity: an unrelated Temperature Alert Rule
 * tagged "Sensors" must never hide a failed Power Supply on the same
 * device. Without a real per-entity identifier on both sides, this
 * class always prefers a safe, clearly-labeled visual duplicate over a
 * hidden incident.
 *
 * "Operationally critical" is intentionally NOT re-derived from device
 * type/role/hostname pattern-matching (that was tried once already, in
 * this plugin's very first design, and produced exactly the false
 * positives/negatives this redesign exists to fix — a printer is not
 * "technically a printer therefore never critical", a server is not
 * "technically a server therefore always critical"). It is read from
 * one real, administrator-controlled LibreNMS Device Group membership
 * (device_group_device), exactly matching how a real NOC already
 * decides "which of my devices actually page someone at 3am" — see
 * Page::loadOperationallyCriticalDeviceIds().
 */
final class OperationalPolicy
{
    /**
     * Every technical condition this policy knows how to fall back on.
     * Anything genuinely outside this list (a service_status value other
     * than 0/1/2/3, a sensor state this dashboard cannot decode) already
     * has its own, separate "Needs Review" handling in
     * Page::buildDeviceIssues() — this class does not need a matching
     * catch-all, because Severity::UNKNOWN already IS that catch-all.
     *
     * @param  array<int, string>  $coveredCategories  The subset of
     *     Support\AlertRules::CATEGORIES an active, administrator-
     *     selected Alert Rule is tagged as covering for this device.
     *     Only AlertRules::CATEGORY_DEVICE_DOWN is ever used to
     *     suppress anything here — see this class's own docblock for
     *     why 'sensor'/'service' tags are read elsewhere (Settings,
     *     Policy Health) but never here.
     * @param  array<string, mixed>  $policyConfig  The resolved
     *     'operational_severity_policy' slice of Support\Config — see
     *     Support\Config::FIELDS. Every severity/enabled decision below
     *     reads only from here, never a hardcoded literal, so an
     *     administrator can change this policy without editing code.
     */
    public static function resolveFallbackIssues(
        int $deviceId,
        ?int $locationId,
        string $deviceUrl,
        int $deviceStatus,
        bool $maintenanceActive,
        bool $operationallyCritical,
        array $coveredCategories,
        Collection $serviceRows,
        Collection $telemetry,
        array $policyConfig
    ): Collection {
        $issues = collect();

        // A maintenance window is LibreNMS's own explicit "do not treat
        // this device's current state as an incident" signal; the
        // fallback stays silent for the same reason real Alert Rules
        // are expected to (via LibreNMS's own maintenance/schedule
        // suppression) — this dashboard must not manufacture new
        // Warning/Critical noise a NOC operator did not ask for during
        // an already-acknowledged maintenance window. Administrator-
        // configurable (fallback_suppress_during_maintenance) — default
        // true preserves this project's original behavior exactly.
        if ($maintenanceActive && ($policyConfig['fallback_suppress_during_maintenance'] ?? true)) {
            return $issues;
        }

        // Device Down is the only condition category with a real,
        // shared exact identity (device_id) on both sides — see this
        // class's own docblock. 'sensor'/'service' tags are
        // deliberately never consulted here: a category label is not
        // an exact per-sensor/per-service identity, and suppressing on
        // one would risk hiding an unrelated device's real failure.
        $deviceDownCovered = in_array(AlertRules::CATEGORY_DEVICE_DOWN, $coveredCategories, true);

        if ($deviceStatus === 0
            && ! $deviceDownCovered
            && ($policyConfig['fallback_device_down_enabled'] ?? true)
        ) {
            $severity = self::resolveSeverity($policyConfig, $operationallyCritical
                ? 'fallback_device_down_critical_group_severity'
                : 'fallback_device_down_normal_severity');

            if ($severity !== null) {
                $issues->push(IssueBuilder::make([
                    'key' => 'policy:' . $deviceId . ':device_down',
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $severity,
                    'priority' => $severity === Severity::CRITICAL
                        ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                        : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                    'source' => 'device',
                    'type' => 'device',
                    'title' => 'Device unreachable',
                    'description' => self::describeFallback(
                        'Device unreachable',
                        $operationallyCritical ? 'Operational Critical device' : 'standard device'
                    ),
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        // No exact per-service identity exists on an Alert-Rule-sourced
        // issue (see this class's own docblock) — a 'service' category
        // tag can never suppress this loop; only the enable/disable
        // toggle and each service's own status govern whether an issue
        // is generated here.
        if ($policyConfig['fallback_service_enabled'] ?? true) {
            foreach ($serviceRows as $service) {
                $status = (int) ($service['status'] ?? 0);

                if ($status === 0) {
                    continue;
                }

                // service_status: 1=Warning, 2=Critical, 3=Unknown (the
                // same validated Nagios mapping
                // Page::serviceStatusClass() already encodes).
                //
                // Deliberately NOT gated on $operationallyCritical the
                // way Device Down and sensor conditions are: a service
                // check that has already classified itself Critical (a
                // specific failing application/health-check, not raw
                // ICMP/SNMP reachability) is Critical for every device
                // by default — this is a stronger, more specific signal
                // than the device's own infrastructure-tier Device
                // Group membership. Administrator-configurable per
                // status via fallback_service_*_severity.
                $severity = self::resolveSeverity($policyConfig, match ($status) {
                    2 => 'fallback_service_critical_severity',
                    1 => 'fallback_service_warning_severity',
                    default => 'fallback_service_unknown_severity',
                });

                if ($severity === null) {
                    continue;
                }

                $name = trim((string) ($service['name'] ?? 'Service'));
                $message = trim((string) ($service['message'] ?? ''));

                $issues->push(IssueBuilder::make([
                    'key' => 'policy:' . $deviceId . ':service:' . ($service['service_id'] ?? $name),
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $severity,
                    'priority' => $severity === Severity::CRITICAL
                        ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                        : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                    'source' => 'service',
                    'type' => 'service',
                    'title' => 'Service ' . $name,
                    'description' => self::describeFallback(
                        $name . ($message !== '' ? ' — ' . $message : ''),
                        'a service check reporting status ' . $status . ' applies to every device by default'
                    ),
                    'timestamp' => isset($service['changed']) ? (string) $service['changed'] : null,
                    'actionable' => true,
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        // No exact per-sensor identity exists on an Alert-Rule-sourced
        // issue either — a 'sensor' category tag can never suppress
        // this loop. Each metric's own per-type enable/disable toggle
        // is the only gate.
        foreach ($telemetry as $metric) {
            $state = Severity::normalize($metric['state'] ?? null);

            if (! in_array($state, [Severity::CRITICAL, Severity::WARNING], true)) {
                continue;
            }

            $isStateSensor = Page::metricProblemType($metric) === 'state';
            $enabledKey = $isStateSensor ? 'fallback_state_sensor_enabled' : 'fallback_numeric_sensor_enabled';

            if (! ($policyConfig[$enabledKey] ?? true)) {
                continue;
            }

            if ($state === Severity::WARNING) {
                $severity = Severity::WARNING;
            } else {
                $settingKey = $operationallyCritical
                    ? ($isStateSensor ? 'fallback_state_sensor_critical_group_severity' : 'fallback_numeric_sensor_critical_group_severity')
                    : ($isStateSensor ? 'fallback_state_sensor_normal_severity' : 'fallback_numeric_sensor_normal_severity');
                $severity = self::resolveSeverity($policyConfig, $settingKey);
            }

            if ($severity === null) {
                continue;
            }

            $sensorId = (int) ($metric['sensor_id'] ?? 0);
            $description = trim((string) ($metric['cause'] ?? ''));

            if ($description === '') {
                $description = trim((string) ($metric['label'] ?? 'Sensor'))
                    . ' — ' . trim((string) ($metric['value'] ?? 'Current value unavailable'));
            }

            $issues->push(IssueBuilder::make([
                'key' => 'policy:' . $deviceId . ':sensor:' . $sensorId,
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => $severity,
                'priority' => $severity === Severity::CRITICAL
                    ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                    : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                'source' => 'sensor',
                'type' => Page::metricProblemType($metric),
                'title' => (string) ($metric['label'] ?? 'Sensor'),
                'description' => self::describeFallback(
                    $description,
                    $operationallyCritical ? 'Operational Critical device' : 'standard device'
                ),
                'value' => $metric['current_value'] ?? null,
                'unit' => $metric['unit'] ?? null,
                'threshold' => $metric['threshold'] ?? null,
                'threshold_direction' => $metric['threshold_direction'] ?? null,
                'timestamp' => $metric['lastupdate'] ?? null,
                'age_seconds' => is_array($metric['freshness'] ?? null)
                    ? ($metric['freshness']['age_seconds'] ?? null)
                    : null,
                'actionable' => true,
                'device_url' => $deviceUrl,
            ]));
        }

        return $issues;
    }

    /**
     * Fail-safe severity-choice resolution (Section 15's own
     * requirement): reads one 'critical'/'warning'/'disabled' choice
     * out of the already-typed/validated $policyConfig (Support\
     * Config::resolve() already rejected anything outside FIELDS'
     * declared options — this is defense in depth, never trusting a
     * raw/untyped value here). 'disabled' resolves to null, meaning
     * "generate no issue for this slot"; any unrecognized value falls
     * back to Severity::WARNING rather than silently producing a
     * malformed severity string.
     *
     * Deliberately does NOT offer Severity::UNKNOWN as a policy choice:
     * UNKNOWN means "technically indeterminate / Needs Review" — a real,
     * distinct technical state (an unreadable/undecoded sensor, see
     * Page::buildDeviceIssues()'s own UNKNOWN handling) — not a
     * synonym for "low-urgency but confirmed". This dashboard has no
     * real "Informational" severity tier; rather than invent one or
     * silently borrow UNKNOWN's meaning, the only choices offered are
     * severities that already mean what they say.
     */
    private static function resolveSeverity(array $policyConfig, string $settingKey): ?string
    {
        $choice = (string) ($policyConfig[$settingKey] ?? Severity::WARNING);

        return match ($choice) {
            Severity::CRITICAL => Severity::CRITICAL,
            Severity::WARNING => Severity::WARNING,
            'disabled' => null,
            default => Severity::WARNING,
        };
    }

    /**
     * Every fallback issue's description is explicit about being a
     * fallback — an administrator looking at a Warning/Critical badge
     * must be able to tell "this came from a real Alert Rule I
     * configured" apart from "this is the dashboard's own safety net
     * because no rule covers it yet" (Section 12/25's own requirement).
     * $reason names the actual policy basis for the chosen severity —
     * never just a bare true/false, since Device Down/sensor conditions
     * and Service status conditions are governed by genuinely different
     * defaults (see resolveFallbackIssues()'s own comments) and the
     * text shown to an administrator must say which one actually
     * applied here, not a generic label.
     */
    private static function describeFallback(string $cause, string $reason): string
    {
        return $cause . ' (no active Alert Rule covers this — default policy for a ' . $reason . ')';
    }
}
