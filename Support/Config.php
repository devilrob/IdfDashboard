<?php

namespace App\Plugins\IdfDashboard\Support;

/**
 * Single source of truth for every admin-configurable setting this
 * plugin has. LibreNMS persists plugin settings as a flat, string-keyed
 * JSON array (see App\Models\Plugin::$casts — 'settings' => 'array',
 * written by PluginSettingsController::update() from a plain HTML
 * form's settings[key] fields). Both Settings.php (renders the form,
 * needs labels/groups/current values) and Page.php (needs typed,
 * clamped values to actually use) read from this one FIELDS list, so
 * adding or renaming a setting never means updating casting logic in
 * two places and having them drift apart.
 */
class Config
{
    public const FIELDS = [
        // --- Timing -----------------------------------------------
        'refresh_seconds' => [
            'type' => 'int', 'default' => 30, 'min' => 5, 'max' => 3600,
            'label' => 'Dashboard refresh interval (seconds)',
            'group' => 'timing',
            'help' => 'How often the dashboard silently re-fetches data in the background (no page reload).',
        ],
        'sensor_fresh_minutes' => [
            'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 1440,
            'label' => 'Sensor stale timeout (minutes)',
            'group' => 'timing',
            'help' => 'A sensor reading older than this is flagged "stale" and shows its age — severity is never softened by staleness, a bad last-known reading stays Critical/Warning.',
        ],
        'event_window_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168,
            'label' => 'Event log activity window (hours)',
            'group' => 'timing',
            'help' => 'How far back the "Event Log Activity" coverage card looks. Informational only — never raises severity.',
        ],
        'tv_default_slide_seconds' => [
            'type' => 'int', 'default' => 12, 'min' => 5, 'max' => 120,
            'label' => 'TV Mode default slide time (seconds)',
            'group' => 'timing',
            'help' => 'Default time each TV Mode slide stays on screen before rotating to the next one.',
        ],

        // --- Thresholds ---------------------------------------------
        'battery_critical_percent' => [
            'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 100,
            'label' => 'Battery Charge — Critical at or below (%)',
            'group' => 'thresholds',
            'help' => 'LibreNMS rarely has a configured threshold for UPS battery charge percent, so this dashboard applies its own.',
        ],
        'battery_warning_percent' => [
            'type' => 'int', 'default' => 50, 'min' => 0, 'max' => 100,
            'label' => 'Battery Charge — Warning at or below (%)',
            'group' => 'thresholds',
            'help' => '',
        ],
        'storage_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Storage — Critical at or above (%)',
            'group' => 'thresholds',
            'help' => 'LibreNMS only has a single "warning" percent per filesystem, no critical tier — this dashboard applies its own ceiling. A "crashinfo" partition never exceeds Warning here regardless of this setting (see the help text on the "Storage" problem type).',
        ],
        'memory_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Memory — Critical at or above (%)',
            'group' => 'thresholds',
            'help' => 'Same reasoning as Storage above — LibreNMS\'s mempool warning threshold has no critical counterpart.',
        ],
        'processor_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Processor — Critical at or above (%)',
            'group' => 'thresholds',
            'help' => 'Same reasoning as Storage above.',
        ],

        // --- Visual ---------------------------------------------------
        'animations_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Enable card animations (Critical/Warning glow, alert pulse)',
            'group' => 'visual',
            'help' => 'Turn off for a fully static display — colors and text still reflect severity, only the motion is disabled.',
        ],

        // --- Phase 2 navigation --------------------------------------
        'default_view' => [
            'type' => 'choice', 'default' => 'overview',
            'options' => ['overview' => 'Overview', 'locations' => 'Locations', 'devices' => 'Devices'],
            'label' => 'Default dashboard view',
            'group' => 'navigation',
            'help' => 'The first view shown when the URL does not explicitly select one.',
        ],
        'devices_per_page' => [
            'type' => 'choice', 'default' => 25,
            'options' => [25 => '25', 50 => '50', 100 => '100'],
            'label' => 'Devices per page',
            'group' => 'navigation',
            'help' => 'A defensive maximum of 100 devices is enforced for every request.',
        ],
        'show_healthy_locations' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Show healthy locations',
            'group' => 'navigation',
            'help' => 'Healthy locations remain available through filters even when hidden by default.',
        ],
        'maximum_priority_issues' => [
            'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50,
            'label' => 'Maximum Priority Attention devices',
            'group' => 'navigation',
            'help' => 'Priority Attention keeps one primary row per device and reports additional causes.',
        ],
        'default_problems_only' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Show only problems by default',
            'group' => 'navigation',
            'help' => 'Can be changed per URL without changing the organization-wide default.',
        ],

        // --- Updates --------------------------------------------------
        'update_check_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Periodically check the stable release channel',
            'group' => 'updates',
            'help' => 'Checks GitHub at most every six hours when an administrator opens this Settings page. Installation always requires the CLI command below.',
        ],

        // --- Default Severity shown on load ----------------------------
        'default_severity_critical' => ['type' => 'bool', 'default' => true, 'label' => 'Critical', 'group' => 'severity', 'help' => ''],
        'default_severity_warning' => ['type' => 'bool', 'default' => true, 'label' => 'Warning', 'group' => 'severity', 'help' => ''],
        'default_severity_unknown' => ['type' => 'bool', 'default' => true, 'label' => 'Needs Review', 'group' => 'severity', 'help' => 'A state sensor whose current value has no known translation — never confirmed healthy, never guessed at as a real problem either.'],
        'default_severity_healthy' => ['type' => 'bool', 'default' => false, 'label' => 'Healthy', 'group' => 'severity', 'help' => ''],

        // --- Default Problem types shown on load -----------------------
        'default_problem_temperature' => ['type' => 'bool', 'default' => true, 'label' => 'Temperature', 'group' => 'problem', 'help' => ''],
        'default_problem_humidity' => ['type' => 'bool', 'default' => true, 'label' => 'Humidity', 'group' => 'problem', 'help' => ''],
        'default_problem_battery' => ['type' => 'bool', 'default' => true, 'label' => 'Battery', 'group' => 'problem', 'help' => ''],
        'default_problem_voltage' => ['type' => 'bool', 'default' => true, 'label' => 'Voltage', 'group' => 'problem', 'help' => ''],
        'default_problem_fan' => ['type' => 'bool', 'default' => true, 'label' => 'Fan', 'group' => 'problem', 'help' => ''],
        'default_problem_device' => ['type' => 'bool', 'default' => true, 'label' => 'Device down', 'group' => 'problem', 'help' => ''],
        'default_problem_service' => ['type' => 'bool', 'default' => true, 'label' => 'Service issue', 'group' => 'problem', 'help' => ''],
        'default_problem_alert' => ['type' => 'bool', 'default' => true, 'label' => 'Alert', 'group' => 'problem', 'help' => ''],
        'default_problem_state' => ['type' => 'bool', 'default' => true, 'label' => 'State sensor', 'group' => 'problem', 'help' => 'Discrete/enum sensors such as "System Status" or "Battery Status" — decoded via LibreNMS\'s state_translations table, not a numeric threshold.'],
        'default_problem_storage' => ['type' => 'bool', 'default' => true, 'label' => 'Storage', 'group' => 'problem', 'help' => 'Filesystem/flash usage from LibreNMS\'s storage table. A "crashinfo" partition full at 100% is common and often benign on some vendors\' switches, so it is capped at Warning here, never auto-Critical.'],
        'default_problem_memory' => ['type' => 'bool', 'default' => true, 'label' => 'Memory', 'group' => 'problem', 'help' => 'Memory pool usage from LibreNMS\'s mempools table.'],
        'default_problem_processor' => ['type' => 'bool', 'default' => true, 'label' => 'Processor', 'group' => 'problem', 'help' => 'CPU usage from LibreNMS\'s processors table.'],
        'default_problem_stale' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Stale data',
            'group' => 'problem',
            'help' => 'When disabled, stale readings are excluded from telemetry, severity, counters, cards, filters, and Priority Attention.',
        ],
        'default_problem_other' => ['type' => 'bool', 'default' => true, 'label' => 'Other', 'group' => 'problem', 'help' => ''],

        // --- Default Sections visible on load ---------------------------
        'default_section_priority' => ['type' => 'bool', 'default' => true, 'label' => 'Priority Attention', 'group' => 'section', 'help' => 'The "what to check first" list at the top of the dashboard.'],
        'default_section_coverage' => ['type' => 'bool', 'default' => true, 'label' => 'Coverage panel', 'group' => 'section', 'help' => ''],
        'default_section_summary' => ['type' => 'bool', 'default' => false, 'label' => 'Summary panel', 'group' => 'section', 'help' => ''],
        'default_section_mdfServers' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Servers', 'group' => 'section', 'help' => ''],
        'default_section_mdfPower' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Power', 'group' => 'section', 'help' => ''],
        'default_section_mdfInfrastructure' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Infrastructure', 'group' => 'section', 'help' => ''],
        'default_section_idf' => ['type' => 'bool', 'default' => true, 'label' => 'IDF Locations', 'group' => 'section', 'help' => ''],
        'default_section_otherLocations' => ['type' => 'bool', 'default' => true, 'label' => 'Other Locations', 'group' => 'section', 'help' => ''],
    ];

    /**
     * Turns the raw string-keyed settings array LibreNMS persists into
     * typed, range-clamped values, falling back to the default for
     * anything missing/blank/out of range. Defensive on purpose: this
     * plugin cannot add its own server-side validation to
     * PluginSettingsController::update() (that would mean touching
     * LibreNMS core), so a stray or malicious value saved through the
     * form must still resolve to something safe here.
     */
    public static function resolve(array $settings): array
    {
        $resolved = [];

        foreach (self::FIELDS as $key => $field) {
            $raw = $settings[$key] ?? null;

            if ($field['type'] === 'bool') {
                $resolved[$key] = $raw === null
                    ? $field['default']
                    : in_array((string) $raw, ['1', 'true', 'on'], true);

                continue;
            }

            if ($field['type'] === 'choice') {
                $options = array_map('strval', array_keys($field['options']));
                $candidate = $raw === null ? (string) $field['default'] : (string) $raw;
                $resolved[$key] = in_array($candidate, $options, true)
                    ? (is_int($field['default']) ? (int) $candidate : $candidate)
                    : $field['default'];

                continue;
            }

            // int
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                $resolved[$key] = $field['default'];
                continue;
            }

            $value = (int) $raw;
            $resolved[$key] = max($field['min'], min($field['max'], $value));
        }

        return $resolved;
    }

    /**
     * Normalize every shareable GET parameter before it reaches presentation
     * or collection filtering. Security still comes from DeviceAccess; this
     * strict schema prevents arbitrary sort keys, unlimited pages and noisy
     * values from becoming part of a dashboard request.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalizeDashboardRequest(array $input, array $config): array
    {
        $allowedViews = ['overview', 'locations', 'devices', 'location', 'device'];
        $view = strtolower(trim((string) ($input['view'] ?? $config['default_view'])));
        $view = in_array($view, $allowedViews, true) ? $view : $config['default_view'];

        $search = preg_replace('/\s+/', ' ', trim((string) ($input['search'] ?? ''))) ?? '';
        $search = substr($search, 0, 100);
        $severity = strtolower(trim((string) ($input['severity'] ?? '')));
        $severity = in_array($severity, ['critical', 'warning', 'unknown', 'stale', 'maintenance', 'healthy'], true)
            ? $severity
            : '';
        $category = trim((string) ($input['category'] ?? ''));
        $category = in_array($category, DeviceClassifier::CATEGORIES, true) ? $category : '';
        $problem = strtolower(trim((string) ($input['problem'] ?? '')));
        $problem = in_array($problem, ['device', 'service', 'alert', 'temperature', 'humidity', 'battery', 'voltage', 'fan', 'state', 'storage', 'memory', 'processor', 'stale', 'other'], true)
            ? $problem
            : '';
        $sort = strtolower(trim((string) ($input['sort'] ?? 'severity')));
        $sort = in_array($sort, ['severity', 'name', 'location', 'freshness'], true) ? $sort : 'severity';
        $direction = strtolower(trim((string) ($input['direction'] ?? 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
        $configuredPerPage = (int) $config['devices_per_page'];
        $perPage = filter_var($input['per_page'] ?? $configuredPerPage, FILTER_VALIDATE_INT);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : $configuredPerPage;
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = is_int($page) ? max(1, min(100000, $page)) : 1;
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $id = is_int($id) && $id >= 0 ? $id : null;
        $location = filter_var($input['location'] ?? null, FILTER_VALIDATE_INT);
        $location = is_int($location) && $location >= 0 ? $location : null;

        return [
            'view' => $view,
            'id' => $id,
            'search' => $search,
            'severity' => $severity,
            'category' => $category,
            'problem' => $problem,
            'location' => $location,
            'problems_only' => self::requestBool($input, 'problems_only', (bool) $config['default_problems_only']),
            'stale' => self::requestBool($input, 'stale'),
            'no_sensor' => self::requestBool($input, 'no_sensor'),
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'per_page' => $perPage,
            'tv' => self::requestBool($input, 'tv'),
        ];
    }

    /** @param array<string, mixed> $input */
    private static function requestBool(array $input, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return in_array(strtolower((string) $input[$key]), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Groups FIELDS by their 'group' key, preserving declaration
     * order — used by settings.blade.php to render each section.
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::FIELDS as $key => $field) {
            $groups[$field['group']][$key] = $field;
        }

        return $groups;
    }
}
