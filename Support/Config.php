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
        // --- TV Mode ----------------------------------------------------
        'tv_default_slide_seconds' => [
            'type' => 'int', 'default' => 12, 'min' => 5, 'max' => 120,
            'label' => 'TV Mode default slide time (seconds)',
            'group' => 'tv',
            'help' => 'Default time each TV Mode slide stays on screen before rotating to the next one.',
        ],
        'tv_maximum_devices_rendered' => [
            'type' => 'int', 'default' => 200, 'min' => 10, 'max' => 2000,
            'label' => 'TV Mode maximum devices rendered (defensive ceiling)',
            'group' => 'tv',
            'help' => 'A defensive cap on how many already-Settings-filtered, worst-severity-first devices TV Mode renders into the page at all. Never drops a Critical/Warning device ahead of a lower-severity one that fits; the remainder past this ceiling is reported as a count, never silently dropped.',
        ],
        // These can only ever remove something the global severity policy
        // below already allows; there is deliberately no TV setting that
        // can re-enable a severity the global policy has turned off. See
        // Config::visibilityPolicy()'s "$globallyEnabled &&" intersection.
        'tv_hide_healthy' => ['type' => 'bool', 'default' => false, 'label' => 'Additionally hide Healthy in TV Mode', 'group' => 'tv', 'help' => ''],
        'tv_hide_unknown' => ['type' => 'bool', 'default' => false, 'label' => 'Additionally hide Needs Review in TV Mode', 'group' => 'tv', 'help' => ''],
        'tv_hide_stale' => ['type' => 'bool', 'default' => false, 'label' => 'Additionally hide Stale in TV Mode', 'group' => 'tv', 'help' => ''],
        'tv_hide_maintenance' => ['type' => 'bool', 'default' => false, 'label' => 'Additionally hide Maintenance in TV Mode', 'group' => 'tv', 'help' => ''],
        'tv_hide_no_sensor' => ['type' => 'bool', 'default' => false, 'label' => 'Additionally hide "No sensor installed" in TV Mode', 'group' => 'tv', 'help' => ''],

        // TV Presentation Policy (Support\TvPresentationPolicy) — TV Mode
        // is a wall/NOC projection, not the normal dashboard rendered
        // full-screen: its job is showing only what deserves immediate
        // visual attention, a narrower question than "is this
        // technically actionable." These settings are pure PRESENTATION
        // filters over already-computed, already-actionable Critical/
        // Warning issues — they can never change device health, location
        // health, normal Priority Attention, header counters, Alert Rule
        // inclusion, or telemetry (see Support\TvPresentationPolicy's own
        // docblock). Monitor issues (actionable=false) are already
        // excluded from TV by that same actionable check, with no
        // dedicated setting needed — forcing Monitor into a toggle here
        // would mean a second severity engine, which this redesign
        // exists to avoid.
        'tv_show_severity_critical' => ['type' => 'bool', 'default' => true, 'label' => 'Show Critical on TV', 'group' => 'tv', 'help' => ''],
        'tv_show_severity_warning' => ['type' => 'bool', 'default' => true, 'label' => 'Show Warning on TV', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_device_down' => ['type' => 'bool', 'default' => true, 'label' => 'Device Down', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_hardware_state' => ['type' => 'bool', 'default' => true, 'label' => 'Hardware / State Failure', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_voltage' => ['type' => 'bool', 'default' => true, 'label' => 'Voltage', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_battery' => ['type' => 'bool', 'default' => true, 'label' => 'Battery', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_fan' => ['type' => 'bool', 'default' => true, 'label' => 'Fan', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_service_critical' => ['type' => 'bool', 'default' => true, 'label' => 'Service Critical', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_service_warning' => ['type' => 'bool', 'default' => false, 'label' => 'Service Warning', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_temperature' => ['type' => 'bool', 'default' => false, 'label' => 'Temperature', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_humidity' => ['type' => 'bool', 'default' => false, 'label' => 'Humidity', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_cpu' => ['type' => 'bool', 'default' => false, 'label' => 'CPU', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_memory' => ['type' => 'bool', 'default' => false, 'label' => 'Memory', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_storage' => ['type' => 'bool', 'default' => false, 'label' => 'Storage', 'group' => 'tv', 'help' => ''],
        'tv_show_condition_other' => ['type' => 'bool', 'default' => false, 'label' => 'Other', 'group' => 'tv', 'help' => ''],

        // --- Updates --------------------------------------------------
        'update_check_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Periodically check the stable release channel',
            'group' => 'updates',
            'help' => 'Checks GitHub at most every six hours when an administrator opens this Settings page. Installation always requires the CLI command below.',
        ],

        // --- Operational Priority ---------------------------------------
        // Which real LibreNMS Device Group(s) count as Operational
        // Critical is NOT a FIELDS entry — like Alert Rule inclusion, it
        // is a dynamic, DB-driven multi-select (Support\DeviceGroups),
        // not a static field. See resources/views/settings.blade.php's
        // "Operational Critical Device Groups" control and
        // Support\DeviceGroups::resolveEffectiveGroupIds().
        'fallback_suppress_during_maintenance' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Suppress fallback during active LibreNMS maintenance windows',
            'group' => 'policy',
            'help' => 'Does not change LibreNMS\'s own maintenance/schedule suppression of Alert Rules — only this dashboard\'s own fallback safety net.',
        ],

        // --- Advanced -----------------------------------------------------
        // Detailed Operational Priority overrides. Every choice-type
        // setting here governs the operational severity this dashboard
        // assigns to a real technical condition — either Support\
        // OperationalPolicy's Device Down/Service handling (exact,
        // device/service-identity-based — unchanged by this redesign,
        // see Support\AlertRules::DEVICE_DOWN_SETTING_KEY), or the
        // condition-bucket policy (Support\ConditionBucket +
        // Support\OperationalPolicy::resolveConditionOutcome()) applied
        // to whichever sensor/service condition is CURRENTLY violating
        // on a device — never a raw Alert Rule's own severity for a rule
        // marked "Condition policy" Handling (see Support\AlertRules'
        // own docblock). Defaults below intentionally reproduce this
        // project's original policy matrix for Device Down/Service, and
        // the audited noise-reduction target matrix for every other
        // condition, so upgrading from a fresh install changes nothing
        // until an administrator opens this section. Choices are
        // 'critical'/'warning'/'monitor'/'disabled':
        // Severity::UNKNOWN ("Needs Review") is a real, distinct
        // technical state and is never offered here as a stand-in for
        // "Informational". 'monitor' means the issue exists (visible in
        // device telemetry/details) but is never actionable — never
        // enters Priority Attention, never elevates device/location
        // health, never fills TV Mode by default (Severity::worst()'s
        // existing actionable filter is the only mechanism this reuses —
        // see IssueBuilder::make()). 'disabled' means this specific slot
        // never generates an issue at all (the underlying technical
        // telemetry/service state remains visible regardless).
        //
        // There is deliberately no single global "Fallback Safety Net
        // enabled" master switch here: 'disabled' on any one condition
        // already achieves that per-condition, and a second master
        // switch would just be a second, competing authority over the
        // same decision.
        'fallback_device_down_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Enable Device Down fallback',
            'group' => 'advanced',
            'help' => 'When off, this dashboard never synthesizes a Device Down issue on its own — rely entirely on your own Alert Rules for this condition.',
        ],
        'fallback_device_down_critical_group_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Device Down severity — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => 'Applied only when no active, selected Alert Rule tagged "Device Down" already covers this device.',
        ],
        'fallback_device_down_normal_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Device Down severity — every other device',
            'group' => 'advanced',
            'help' => 'Applied only when no active, selected Alert Rule tagged "Device Down" already covers this device.',
        ],
        'fallback_service_critical_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Service severity — status CRITICAL',
            'group' => 'advanced',
            'help' => 'Applies to every device by default (a failing service check is a stronger, more specific signal than infrastructure tier) — no Alert Rule tag can suppress this (no exact per-service identity exists to correlate against).',
        ],
        'fallback_service_warning_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Service severity — status WARNING',
            'group' => 'advanced',
            'help' => '',
        ],
        'fallback_service_unknown_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'disabled' => 'Disabled (no fallback)'],
            'label' => 'Service severity — status UNKNOWN',
            'group' => 'advanced',
            'help' => 'A service check that could not determine its own state — never Critical by default, since that would manufacture an outage signal from a data-quality gap. Deliberately not part of the condition-bucket matrix below, and no "Monitor" choice — this is a data-quality gap, not a real technical condition, and this dashboard\'s existing safe semantics for it are unchanged.',
        ],

        // Condition-bucket policy (Support\ConditionBucket) — replaces
        // the old numeric-sensor/state-sensor split entirely. That split
        // was never the right operational-priority axis: a numeric
        // Critical temperature reading and a numeric Critical voltage
        // reading used to get the exact same severity, which is the
        // root cause the noise-reduction audit identified (a humidity
        // reading counted the same as a failed power supply). Severity
        // is now selected by WHICH CONDITION a sensor represents
        // (Page::metricProblemType(), translated to a bucket by
        // Support\ConditionBucket::forMetricProblemType()), independent
        // of whether the underlying LibreNMS threshold math is numeric
        // or state-decoded — that mechanic is now purely internal to
        // sensor evaluation (Page::sensorState()/IssueBuilder::
        // evaluateNumericSensor()), never an operational severity
        // authority on its own. 'other' covers every sensor_class this
        // dashboard has no dedicated bucket for.
        'condition_policy_hardware_state_critical_group_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Hardware / State Failure — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => 'A decoded state/discrete sensor (e.g. "Power Supply Failed") already showing Critical or Warning.',
        ],
        'condition_policy_hardware_state_normal_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Hardware / State Failure — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_voltage_critical_group_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Voltage — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_voltage_normal_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Voltage — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_battery_critical_group_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Battery — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_battery_normal_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Battery — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_fan_critical_group_severity' => [
            'type' => 'choice', 'default' => 'critical',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Fan — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_fan_normal_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Fan — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_temperature_critical_group_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Temperature — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_temperature_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Temperature — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_humidity_critical_group_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Humidity — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_humidity_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Humidity — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_cpu_critical_group_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'CPU — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_cpu_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'CPU — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_memory_critical_group_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Memory — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_memory_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Memory — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_storage_critical_group_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Storage — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_storage_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Storage — every other device',
            'group' => 'advanced',
            'help' => '',
        ],
        'condition_policy_other_critical_group_severity' => [
            'type' => 'choice', 'default' => 'warning',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Other — Operational Critical Device Groups',
            'group' => 'advanced',
            'help' => 'Any sensor this dashboard has no dedicated condition bucket for.',
        ],
        'condition_policy_other_normal_severity' => [
            'type' => 'choice', 'default' => 'monitor',
            'options' => ['critical' => 'Critical', 'warning' => 'Warning', 'monitor' => 'Monitor', 'disabled' => 'Ignore (no issue)'],
            'label' => 'Other — every other device',
            'group' => 'advanced',
            'help' => '',
        ],

        // Legacy, hidden field — see Support\DeviceGroups::
        // resolveEffectiveGroupIds()'s own docblock. Read ONLY when an
        // administrator has never saved the new multi-select
        // (operational_critical_group_ids); never rendered in Settings,
        // never auto-migrated on a page read. Planned for removal one
        // compatibility release after the multi-select shipped — see
        // CHANGELOG.
        'operational_critical_group_name' => [
            'type' => 'string', 'default' => 'Operational Critical', 'max_length' => 191,
            'label' => 'Operational Critical Device Group name (legacy)',
            'group' => 'advanced', 'hidden' => true,
            'help' => '',
        ],

        // --- Dashboard Display --------------------------------------
        'animations_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Enable card animations (Critical/Warning glow, alert pulse)',
            'group' => 'display',
            'help' => 'Turn off for a fully static display — colors and text still reflect severity, only the motion is disabled.',
        ],
        'default_view' => [
            'type' => 'choice', 'default' => 'overview',
            'options' => ['overview' => 'Overview', 'locations' => 'Locations', 'devices' => 'Devices'],
            'label' => 'Default dashboard view',
            'group' => 'display',
            'help' => 'The first view shown when the URL does not explicitly select one.',
        ],
        'devices_per_page' => [
            'type' => 'choice', 'default' => 25,
            'options' => [25 => '25', 50 => '50', 100 => '100'],
            'label' => 'Devices per page',
            'group' => 'display',
            'help' => 'A defensive maximum of 100 devices is enforced for every request.',
        ],
        'show_healthy_locations' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Show healthy locations',
            'group' => 'display',
            'help' => 'Healthy locations remain available through filters even when hidden by default.',
        ],
        'maximum_priority_issues' => [
            'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50,
            'label' => 'Maximum Priority Attention devices',
            'group' => 'display',
            'help' => 'Priority Attention keeps one primary row per device and reports additional causes.',
        ],
        'default_problems_only' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Show only problems by default',
            'group' => 'display',
            'help' => 'Can be changed per URL without changing the organization-wide default.',
        ],
        'default_severity_critical' => ['type' => 'bool', 'default' => true, 'label' => 'Show Critical', 'group' => 'display', 'help' => ''],
        'default_severity_warning' => ['type' => 'bool', 'default' => true, 'label' => 'Show Warning', 'group' => 'display', 'help' => ''],
        'default_severity_unknown' => ['type' => 'bool', 'default' => true, 'label' => 'Show Needs Review', 'group' => 'display', 'help' => 'A state sensor whose current value has no known translation — never confirmed healthy, never guessed at as a real problem either.'],
        'default_severity_stale' => ['type' => 'bool', 'default' => true, 'label' => 'Show Stale (data quality)', 'group' => 'display', 'help' => 'A device whose worst state is a stale-but-otherwise-healthy curated power reading.'],
        'default_severity_maintenance' => ['type' => 'bool', 'default' => true, 'label' => 'Show Maintenance', 'group' => 'display', 'help' => 'A device currently in a LibreNMS-scheduled maintenance window with no other active issue.'],
        'default_severity_healthy' => ['type' => 'bool', 'default' => false, 'label' => 'Show Healthy', 'group' => 'display', 'help' => ''],
        'default_severity_no_sensor' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Show "No sensor installed"',
            'group' => 'display',
            'help' => 'Controls only the "No sensor installed" counter/callouts, not device severity — a device with no curated sensor is never Critical/Warning by itself.',
        ],
        'default_section_priority' => ['type' => 'bool', 'default' => true, 'label' => 'Priority Attention', 'group' => 'display', 'help' => 'The "what to check first" list at the top of the dashboard.'],
        'default_section_coverage' => ['type' => 'bool', 'default' => true, 'label' => 'Coverage panel', 'group' => 'display', 'help' => ''],
        'default_section_summary' => ['type' => 'bool', 'default' => false, 'label' => 'Summary panel', 'group' => 'display', 'help' => ''],
        'default_section_mdfServers' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Servers', 'group' => 'display', 'help' => ''],
        'default_section_mdfPower' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Power', 'group' => 'display', 'help' => ''],
        'default_section_mdfInfrastructure' => ['type' => 'bool', 'default' => true, 'label' => 'MDF Infrastructure', 'group' => 'display', 'help' => ''],
        'default_section_idf' => ['type' => 'bool', 'default' => true, 'label' => 'IDF Locations', 'group' => 'display', 'help' => ''],
        'default_section_otherLocations' => ['type' => 'bool', 'default' => true, 'label' => 'Other Locations', 'group' => 'display', 'help' => ''],

        // --- Sensor / Data Quality ---------------------------------------
        // Alert Rules are the preferred, explicit source of operational
        // severity; the Operational Priority fallback above is a safety
        // net for technical failures no Alert Rule covers yet. Neither
        // is what these settings are about: this section covers a third,
        // narrower concept — data QUALITY, not severity: whether a
        // *missing* sensor of a type is flagged ("No sensor installed"),
        // whether an *unreadable/untranslated* sensor is flagged ("Needs
        // Review"), and this dashboard's own applied thresholds where
        // LibreNMS has no native critical tier (Battery/Storage/Memory/
        // Processor). None of this is something an Alert Rule condition
        // can express, since a rule can only evaluate a sensor that
        // already exists and already has a decodable value.
        'default_problem_temperature' => ['type' => 'bool', 'default' => true, 'label' => 'Temperature', 'group' => 'problem', 'help' => ''],
        'default_problem_humidity' => ['type' => 'bool', 'default' => true, 'label' => 'Humidity', 'group' => 'problem', 'help' => ''],
        'default_problem_battery' => ['type' => 'bool', 'default' => true, 'label' => 'Battery', 'group' => 'problem', 'help' => ''],
        'default_problem_voltage' => ['type' => 'bool', 'default' => true, 'label' => 'Voltage', 'group' => 'problem', 'help' => ''],
        'default_problem_fan' => ['type' => 'bool', 'default' => true, 'label' => 'Fan', 'group' => 'problem', 'help' => ''],
        'default_problem_state' => ['type' => 'bool', 'default' => true, 'label' => 'State sensor', 'group' => 'problem', 'help' => 'Discrete/enum sensors such as "System Status" or "Battery Status" — decoded via LibreNMS\'s state_translations table, not a numeric threshold.'],
        'default_problem_storage' => ['type' => 'bool', 'default' => true, 'label' => 'Storage', 'group' => 'problem', 'help' => 'Filesystem/flash usage from LibreNMS\'s storage table.'],
        'default_problem_memory' => ['type' => 'bool', 'default' => true, 'label' => 'Memory', 'group' => 'problem', 'help' => 'Memory pool usage from LibreNMS\'s mempools table.'],
        'default_problem_processor' => ['type' => 'bool', 'default' => true, 'label' => 'Processor', 'group' => 'problem', 'help' => 'CPU usage from LibreNMS\'s processors table.'],
        'default_problem_stale' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Stale data',
            'group' => 'problem',
            'help' => 'A curated power (PDU/UPS) reading that has stopped updating but was last seen healthy still elevates the device to "Stale" while this is on.',
        ],
        'default_problem_other' => ['type' => 'bool', 'default' => true, 'label' => 'Other', 'group' => 'problem', 'help' => ''],
        'battery_critical_percent' => [
            'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 100,
            'label' => 'Battery Charge — Critical at or below (%)',
            'group' => 'problem',
            'help' => 'LibreNMS rarely has a configured threshold for UPS battery charge percent, so this dashboard applies its own.',
        ],
        'battery_warning_percent' => [
            'type' => 'int', 'default' => 50, 'min' => 0, 'max' => 100,
            'label' => 'Battery Charge — Warning at or below (%)',
            'group' => 'problem',
            'help' => '',
        ],
        'storage_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Storage — Critical at or above (%)',
            'group' => 'problem',
            'help' => 'LibreNMS only has a single "warning" percent per filesystem, no critical tier — this dashboard applies its own ceiling. A "crashinfo" partition never exceeds Warning here regardless of this setting.',
        ],
        'memory_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Memory — Critical at or above (%)',
            'group' => 'problem',
            'help' => 'Same reasoning as Storage above — LibreNMS\'s mempool warning threshold has no critical counterpart.',
        ],
        'processor_critical_percent' => [
            'type' => 'int', 'default' => 95, 'min' => 1, 'max' => 100,
            'label' => 'Processor — Critical at or above (%)',
            'group' => 'problem',
            'help' => 'Same reasoning as Storage above.',
        ],
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

            if ($field['type'] === 'string') {
                $candidate = $raw === null ? '' : trim((string) $raw);
                $resolved[$key] = $candidate !== ''
                    ? substr($candidate, 0, $field['max_length'])
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
     * The single source of truth for "is this severity/problem-type/section
     * shown at all" — built once here from resolved config instead of being
     * assembled independently in Page.php (server-side collections) and
     * page.blade.php (the JS `defaults`/`$tvDefaults` object), which is
     * exactly the kind of two-source drift that let TV Mode silently start
     * ignoring the Settings-configured severity policy: a bug fixed
     * (removing an unrelated markup block that had accidentally hijacked
     * TV's own rotation-source selector) without yet centralizing the
     * *decision* itself, which is what this method now does.
     *
     * `$context === 'tv'` additionally intersects the global policy with
     * the `tv_hide_*` restriction settings. That intersection can only ever
     * turn a globally-enabled severity OFF for TV specifically — there is
     * no code path here that can turn a globally-disabled severity back ON
     * for TV, by construction (`$enabled && ! $hide`, never `$hide` alone).
     *
     * @return array<string, bool|int>
     */
    public static function visibilityPolicy(array $config, string $context = 'global'): array
    {
        $policy = [
            'critical' => (bool) $config['default_severity_critical'],
            'warning' => (bool) $config['default_severity_warning'],
            'unknown' => (bool) $config['default_severity_unknown'],
            'stale' => (bool) $config['default_severity_stale'],
            'maintenance' => (bool) $config['default_severity_maintenance'],
            'healthy' => (bool) $config['default_severity_healthy'],
            'no_sensor' => (bool) $config['default_severity_no_sensor'],

            'temperature' => (bool) $config['default_problem_temperature'],
            'humidity' => (bool) $config['default_problem_humidity'],
            'battery' => (bool) $config['default_problem_battery'],
            'voltage' => (bool) $config['default_problem_voltage'],
            'fan' => (bool) $config['default_problem_fan'],
            // Deliberately no 'device'/'service' keys anymore — native
            // Device Down/Service Issue detection was removed from
            // Page.php entirely (real LibreNMS Alert Rules cover both),
            // so 'device'/'service' can never appear in a device's
            // problem_types again; keeping dead keys here would be
            // exactly the kind of redundancy this plugin is trying to
            // remove. Deliberately no 'alert' key here either — see the
            // FIELDS comment above. ProblemPolicy::deviceVisible() reads
            // $policy[$key] ?? true, so an issue of type 'alert' (which
            // only ever exists after Support\AlertRules' own per-rule
            // inclusion filter already ran) is never re-gated by a
            // second, redundant boolean here.
            'state' => (bool) $config['default_problem_state'],
            'storage' => (bool) $config['default_problem_storage'],
            'memory' => (bool) $config['default_problem_memory'],
            'processor' => (bool) $config['default_problem_processor'],
            'problem_stale' => (bool) $config['default_problem_stale'],
            'other' => (bool) $config['default_problem_other'],

            'priority' => (bool) $config['default_section_priority'],
            'coverage' => (bool) $config['default_section_coverage'],
            'summary' => (bool) $config['default_section_summary'],
            'mdfServers' => (bool) $config['default_section_mdfServers'],
            'mdfPower' => (bool) $config['default_section_mdfPower'],
            'mdfInfrastructure' => (bool) $config['default_section_mdfInfrastructure'],
            'idf' => (bool) $config['default_section_idf'],
            'otherLocations' => (bool) $config['default_section_otherLocations'],

            'tvSlideSeconds' => (int) $config['tv_default_slide_seconds'],
            'tvMaximumDevicesRendered' => (int) $config['tv_maximum_devices_rendered'],
        ];

        if ($context === 'tv') {
            // Deliberately no tv_hide_critical/tv_hide_warning: an
            // unattended NOC wall display must never be configurable to
            // hide the two severities its entire purpose is to surface.
            $policy['unknown'] = $policy['unknown'] && ! (bool) $config['tv_hide_unknown'];
            $policy['stale'] = $policy['stale'] && ! (bool) $config['tv_hide_stale'];
            $policy['maintenance'] = $policy['maintenance'] && ! (bool) $config['tv_hide_maintenance'];
            $policy['healthy'] = $policy['healthy'] && ! (bool) $config['tv_hide_healthy'];
            $policy['no_sensor'] = $policy['no_sensor'] && ! (bool) $config['tv_hide_no_sensor'];
        }

        return $policy;
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
        // 'device'/'service'/'temperature'/'humidity'/etc. were removed:
        // a device's problem_types array can now only ever contain
        // 'alert', 'stale' or 'other' (see Page.php's normalizeDevice()
        // — native per-sensor-type/device/service issue generation was
        // replaced by administrator-selected LibreNMS Alert Rules).
        $problem = strtolower(trim((string) ($input['problem'] ?? '')));
        $problem = in_array($problem, ['alert', 'stale', 'other'], true)
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
