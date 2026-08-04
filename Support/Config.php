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
