<div class="idf-settings">
    <header class="idf-settings-header">
        <h2>Infrastructure Health Dashboard — Settings</h2>
        <p>
            These are the organization-wide defaults every viewer's dashboard
            loads with. A NOC operator can still temporarily switch to
            "Problems only" or "View all" from the dashboard itself for their
            own session — that never changes what's saved here.
        </p>
    </header>

    <section class="idf-update-panel" aria-labelledby="idf-update-title">
        <div>
            <h3 id="idf-update-title">Plugin Updates</h3>
            <div class="idf-update-versions">
                Installed: <strong>v{{ $pluginVersion }}</strong>
                · Stable channel:
                <strong>
                    {{ $updateStatus['latest'] ? 'v' . $updateStatus['latest'] : 'No published release' }}
                </strong>
            </div>

            @if($updateStatus['error'])
                <div class="idf-update-error">{{ $updateStatus['error'] }}</div>
            @elseif($updateStatus['update_available'])
                <div class="idf-update-available">A verified stable release is available.</div>
            @elseif($updateStatus['ahead_of_stable'])
                <div class="idf-update-current">Running ahead of the latest published stable release (development build) — v{{ $updateStatus['installed'] }} installed, v{{ $updateStatus['latest'] }} published.</div>
            @elseif($updateStatus['checked_at'])
                <div class="idf-update-current">The installed version is current.</div>
            @elseif(! $updateStatus['enabled'])
                <div class="idf-update-current">Periodic checks are disabled.</div>
            @endif

            <div class="idf-update-note">
                Installation from the web process is intentionally disabled. Run as the
                LibreNMS operating-system user; the CLI validates the release, checksum,
                package structure and PHP syntax, then performs an atomic update with rollback.
            </div>
            <div class="idf-update-command-label">Validate without activation:</div>
            <code class="idf-update-command">{{ $updateDryRunCommand }}</code>
            <div class="idf-update-command-label">Install after validation:</div>
            <code class="idf-update-command">{{ $updateCommand }}</code>
        </div>

        <div class="idf-update-actions">
            <a class="idf-update-button" href="{{ $updateCheckUrl }}">Check for updates</a>
            @if($updateStatus['release_url'])
                <a
                    class="idf-update-link"
                    href="{{ $updateStatus['release_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                >View release</a>
            @endif
            <span class="idf-update-disabled" title="Use the displayed CLI command">Update now (CLI)</span>
        </div>
    </section>

    <form method="post">
        @csrf

        @php
            $mainGroupLabels = [
                'timing' => 'Refresh & Timing',
                'display' => 'Dashboard Display',
            ];
            $tailGroupLabels = [
                'problem' => 'Sensor/Data Quality',
                'tv' => 'TV Mode',
                'updates' => 'Updates',
            ];
        @endphp

        @foreach ($mainGroupLabels as $groupKey => $groupLabel)
            @include('IdfDashboard::resources.views.settings-field-group', ['groupKey' => $groupKey, 'groupLabel' => $groupLabel])
        @endforeach

        {{-- Operational Priority: real Device Groups multi-select, the
             Maintenance suppression toggle, and a read-only effective
             policy summary sourced from Support\OperationalPolicy so it
             can never drift from actual runtime behavior. Detailed
             per-condition severity overrides live in Advanced below. --}}
        <fieldset class="idf-settings-group">
            <legend>Operational Priority</legend>

            <p class="idf-settings-group-intro">
                A technical failure is not automatically an operationally
                critical incident — a kitchen printer being down is not the
                same as a production cluster being unreachable. This policy
                decides Critical/Warning/Monitor/Ignore per condition type
                (device down, voltage, temperature, humidity, …) and
                infrastructure tier — the sole severity source for a
                Condition-policy Alert Rule's firing, and for every sensor/
                service condition regardless of whether any rule exists at
                all. A Direct-severity Alert Rule (see Alert Rules below)
                adds its own separate device/hardware-level signal on top of
                this, never replaced by it.
            </p>

            <h4 class="idf-settings-subheading">Operational Critical Device Groups</h4>
            <p class="idf-settings-group-intro">
                Select every real LibreNMS Device Group (static or dynamic)
                whose members should use the Critical-tier condition
                severities below. A device belongs to Operational Critical if
                it is a member of <strong>any</strong> selected group.
                Membership itself is still managed entirely on LibreNMS's own
                Device Groups admin page — nothing here creates, renames, or
                deletes a group.
            </p>

            @if (empty($availableDeviceGroups))
                <div class="idf-settings-help">
                    No LibreNMS Device Groups were found. Create one under
                    Devices &gt; Device Groups, then return here to select it.
                </div>
            @else
                <input type="hidden" name="settings[{{ $deviceGroupSettingKey }}][]" value="">

                <div class="idf-settings-field-grid idf-device-groups-grid">
                    @foreach ($availableDeviceGroups as $group)
                        <div class="idf-settings-field idf-settings-field-bool">
                            <label class="idf-settings-checkbox-label">
                                <input
                                    type="checkbox"
                                    class="idf-device-group-checkbox"
                                    name="settings[{{ $deviceGroupSettingKey }}][]"
                                    value="{{ $group['id'] }}"
                                    @checked(in_array($group['id'], $selectedDeviceGroupIds, true))
                                >
                                <span>
                                    {{ $group['name'] }}
                                    @if ($group['type'] !== '')
                                        <span class="idf-alert-rule-severity">{{ $group['type'] }}</span>
                                    @endif
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif

            <h4 class="idf-settings-subheading">Maintenance</h4>
            <div class="idf-settings-field-grid">
                <div class="idf-settings-field idf-settings-field-bool">
                    <label class="idf-settings-checkbox-label">
                        <input type="hidden" name="settings[fallback_suppress_during_maintenance]" value="0">
                        <input
                            type="checkbox"
                            name="settings[fallback_suppress_during_maintenance]"
                            value="1"
                            @checked($resolved['fallback_suppress_during_maintenance'])
                        >
                        Suppress fallback during active LibreNMS maintenance
                    </label>
                    <div class="idf-settings-help">Does not change LibreNMS's own maintenance/schedule suppression of Alert Rules — only this dashboard's own fallback safety net.</div>
                </div>
            </div>

            <h4 class="idf-settings-subheading">Effective policy</h4>
            <p class="idf-settings-group-intro">
                Read-only — reflects the resolved configuration exactly,
                including any Advanced overrides below. Never hand-edited
                text, so it can never drift from actual behavior.
            </p>
            <table class="idf-policy-summary-table">
                <thead>
                    <tr>
                        <th>Condition</th>
                        <th>Critical Groups</th>
                        <th>Other Devices</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($effectivePolicySummary as $row)
                        <tr>
                            <td>{{ $row['condition'] }}</td>
                            <td>{{ $row['critical_groups'] }}</td>
                            <td>{{ $row['other_devices'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </fieldset>

        {{-- Alert Rules: one table only — real LibreNMS Alert Rules,
             whether each feeds this dashboard, how its firing translates
             into operational severity (Handling), and the exact Device
             Down correlation hint. No Sensors/Services tagging — see
             Support\AlertRules' own docblock for why no such tag could
             ever safely suppress anything. --}}
        <fieldset class="idf-settings-group">
            <legend>Alert Rules</legend>

            <p class="idf-settings-group-intro">
                Every rule below is a real Alert Rule already configured in
                LibreNMS (Alert Rules admin page). <strong>Include</strong>
                controls whether the rule feeds this dashboard at all —
                LibreNMS keeps evaluating and notifying on it exactly as
                configured either way. <strong>Handling</strong> controls
                HOW an included rule's firing becomes operational severity:
                <strong>Direct severity</strong> uses the rule's own
                severity as-is (for rules whose condition already is the
                device/hardware state itself — Device Down, Cluster/SNMP
                Down, Firewall rules); <strong>Condition policy</strong>
                treats the firing only as proof a real condition exists —
                actual severity comes from the Operational Priority
                condition matrix below, applied to whichever sensor/service
                is currently violating (use this for generic rules like
                "Sensor over/under limit"); <strong>Monitor</strong> keeps
                the rule's firing visible without ever becoming Critical/
                Warning (for informational/noisy rules like "Device
                rebooted"). <strong>Device Down</strong> is the only
                exact-correlation hint this dashboard offers: tagging a
                rule Device Down lets it suppress this dashboard's own
                Device Down condition for the same device, because both
                share a real, exact device_id.
            </p>

            @if (empty($availableAlertRules))
                <div class="idf-settings-help">
                    No LibreNMS Alert Rules were found (or this LibreNMS version's
                    schema was not recognized). Every currently active alert is shown
                    on the dashboard; there is nothing to select yet.
                </div>
            @else
                <input type="hidden" name="settings[{{ $alertRuleSettingKey }}][]" value="">
                <input type="hidden" name="settings[{{ $deviceDownSettingKey }}][]" value="">

                <table class="idf-alert-rules-table">
                    <thead>
                        <tr>
                            <th>Alert Rule</th>
                            <th>Severity</th>
                            <th>Include</th>
                            <th>Handling</th>
                            <th>Device Down</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($availableAlertRules as $rule)
                            <tr>
                                <td>{{ $rule['name'] }}</td>
                                <td>
                                    @if ($rule['severity'] !== '')
                                        <span class="idf-alert-rule-severity">{{ $rule['severity'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="idf-alert-rule-checkbox"
                                        name="settings[{{ $alertRuleSettingKey }}][]"
                                        value="{{ $rule['id'] }}"
                                        @checked(in_array($rule['id'], $includedAlertRuleIds, true))
                                    >
                                </td>
                                <td>
                                    <select name="settings[{{ $handlingSettingKey }}][{{ $rule['id'] }}]" class="idf-alert-rule-handling">
                                        @foreach ($handlingOptions as $handlingValue => $handlingLabel)
                                            <option
                                                value="{{ $handlingValue }}"
                                                @selected(($handlingByRuleId[$rule['id']] ?? 'direct') === $handlingValue)
                                            >{{ $handlingLabel }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="idf-device-down-checkbox"
                                        name="settings[{{ $deviceDownSettingKey }}][]"
                                        value="{{ $rule['id'] }}"
                                        @checked(in_array($rule['id'], $deviceDownTaggedIds, true))
                                    >
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </fieldset>

        @foreach ($tailGroupLabels as $groupKey => $groupLabel)
            @include('IdfDashboard::resources.views.settings-field-group', ['groupKey' => $groupKey, 'groupLabel' => $groupLabel])
        @endforeach

        <details class="idf-settings-group idf-settings-advanced">
            <summary>Advanced</summary>
            <p class="idf-settings-group-intro">
                Detailed per-condition severity overrides — one Critical-tier
                and one standard-tier choice per condition (Critical/Warning/
                Monitor/Ignore). Defaults here reproduce the audited
                noise-reduction target matrix, so opening this section
                changes nothing until you edit a value. There is deliberately
                no single global "Fallback Safety Net enabled" switch —
                setting a condition to "Ignore (no issue)" already disables
                it individually, and a second master switch would just be a
                second, competing authority over the same decision.
            </p>
            <div class="idf-settings-field-grid">
                @foreach ($groups['advanced'] ?? [] as $key => $field)
                    @continue(($field['hidden'] ?? false))
                    @include('IdfDashboard::resources.views.settings-field', ['key' => $key, 'field' => $field])
                @endforeach
            </div>
        </details>

        <div class="idf-settings-actions">
            <button type="submit" class="idf-settings-save">Save Settings</button>
            <button type="button" class="idf-settings-reset" data-idf-reset-defaults>
                Reset all fields to defaults (not saved until you click Save)
            </button>
        </div>
    </form>
</div>

<script>
document.querySelector('[data-idf-reset-defaults]').addEventListener('click', function () {
    var defaults = @json(collect(\App\Plugins\IdfDashboard\Support\Config::FIELDS)->map(fn ($f) => $f['default']));

    Object.keys(defaults).forEach(function (key) {
        var value = defaults[key];
        var inputs = document.getElementsByName('settings[' + key + ']');

        inputs.forEach(function (input) {
            if (input.type === 'checkbox') {
                input.checked = Boolean(value);
            } else if (input.type !== 'hidden') {
                input.value = value;
            }
        });
    });

    // Included LibreNMS Alert Rules has no FIELDS default of its own
    // (it is a dynamic, DB-driven list, not a static bool/choice/int
    // field) — "reset to defaults" for it means every rule included,
    // matching Support\AlertRules::resolveIncludedIds()'s own default
    // for "no explicit choice has ever been saved".
    document.querySelectorAll('.idf-alert-rule-checkbox').forEach(function (input) {
        input.checked = true;
    });

    // Device Down tagging has no FIELDS default either — "reset to
    // defaults" means "nothing tagged", matching
    // Support\AlertRules::resolveDeviceDownTaggedIds()'s own safe
    // default (the condition policy keeps running until an
    // administrator explicitly tags a rule as covering it).
    document.querySelectorAll('.idf-device-down-checkbox').forEach(function (input) {
        input.checked = false;
    });

    // Alert Rule Handling has no FIELDS default either — "reset to
    // defaults" means "Direct severity" for every rule, matching
    // Support\AlertRules::resolveHandling()'s own fail-safe default
    // (reproduces this plugin's pre-redesign behavior exactly).
    document.querySelectorAll('.idf-alert-rule-handling').forEach(function (select) {
        select.value = 'direct';
    });

    // Operational Critical Device Groups likewise has no FIELDS
    // default — "reset to defaults" means "nothing selected", matching
    // the fail-safe default of Support\DeviceGroups::resolveSelectedIds().
    document.querySelectorAll('.idf-device-group-checkbox').forEach(function (input) {
        input.checked = false;
    });
});
</script>

<style>
    .idf-settings {
        color: #333;
        font-size: 13px;
        max-width: 960px;
    }

    .idf-settings-header h2 {
        font-size: 20px;
        margin: 0 0 6px;
    }

    .idf-settings-header p {
        color: #666;
        margin: 0 0 18px;
        max-width: 720px;
    }

    .idf-update-panel {
        align-items: flex-start;
        background: #f7f9fb;
        border: 1px solid #d8e1ea;
        border-radius: 4px;
        display: flex;
        gap: 20px;
        justify-content: space-between;
        margin-bottom: 18px;
        padding: 14px 16px;
    }

    .idf-update-panel h3 {
        font-size: 15px;
        margin: 0 0 6px;
    }

    .idf-update-note,
    .idf-update-versions {
        color: #5f6b76;
        margin-top: 4px;
    }

    .idf-update-command {
        background: #eef2f5;
        border: 1px solid #d4dce3;
        display: block;
        margin-top: 8px;
        max-width: 680px;
        overflow-wrap: anywhere;
        padding: 6px 8px;
    }

    .idf-update-command-label {
        color: #5f6b76;
        font-size: 12px;
        margin-top: 8px;
    }

    .idf-update-available {
        color: #2f7d32;
        font-weight: 700;
        margin-top: 4px;
    }

    .idf-update-current {
        color: #5f6b76;
        margin-top: 4px;
    }

    .idf-update-error {
        color: #a94442;
        margin-top: 4px;
    }

    .idf-update-actions {
        align-items: stretch;
        display: flex;
        flex: 0 0 170px;
        flex-direction: column;
        gap: 7px;
    }

    .idf-update-button,
    .idf-update-disabled,
    .idf-update-link {
        border-radius: 3px;
        display: block;
        padding: 7px 10px;
        text-align: center;
    }

    .idf-update-button {
        background: #337ab7;
        color: #fff;
    }

    .idf-update-link {
        border: 1px solid #337ab7;
    }

    .idf-update-disabled {
        background: #e8ebee;
        color: #78828c;
        cursor: not-allowed;
    }

    @media (max-width: 720px) {
        .idf-update-panel {
            flex-direction: column;
        }

        .idf-update-actions {
            flex-basis: auto;
            width: 100%;
        }
    }

    .idf-settings-group {
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 16px;
        padding: 12px 16px 16px;
    }

    .idf-settings-group legend,
    .idf-settings-advanced summary {
        color: #337ab7;
        font-size: 13px;
        font-weight: 700;
        padding: 0 6px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .idf-settings-advanced summary {
        cursor: pointer;
        padding: 4px 6px;
    }

    .idf-settings-subheading {
        color: #444;
        font-size: 12px;
        letter-spacing: .02em;
        margin: 14px 0 6px;
        text-transform: uppercase;
    }

    .idf-settings-subheading:first-of-type {
        margin-top: 4px;
    }

    .idf-settings-field-grid {
        display: grid;
        gap: 10px 24px;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    }

    .idf-settings-group-intro {
        color: #666;
        font-size: 12px;
        margin: 0 0 12px;
        max-width: 720px;
    }

    .idf-alert-rules-table,
    .idf-policy-summary-table {
        border-collapse: collapse;
        margin-bottom: 8px;
        width: 100%;
    }

    .idf-alert-rules-table th,
    .idf-alert-rules-table td,
    .idf-policy-summary-table th,
    .idf-policy-summary-table td {
        border-bottom: 1px solid #e6e9ec;
        padding: 6px 10px;
        text-align: left;
    }

    .idf-alert-rules-table th,
    .idf-policy-summary-table th {
        color: #5f6b76;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .idf-device-groups-grid {
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        margin-bottom: 8px;
    }

    .idf-alert-rule-severity {
        background: #eef2f5;
        border-radius: 3px;
        color: #5f6b76;
        font-size: 10px;
        margin-left: 6px;
        padding: 1px 6px;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .idf-settings-field {
        padding: 4px 0;
    }

    .idf-settings-checkbox-label {
        align-items: center;
        cursor: pointer;
        display: flex;
        gap: 6px;
    }

    .idf-settings-number-label {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .idf-settings-number-label input,
    .idf-settings-number-label select {
        border: 1px solid #ccc;
        border-radius: 3px;
        padding: 4px 6px;
        width: 100px;
    }

    .idf-settings-help {
        color: #888;
        font-size: 11px;
        margin-top: 2px;
    }

    .idf-settings-actions {
        align-items: center;
        display: flex;
        gap: 12px;
        margin-top: 8px;
    }

    .idf-settings-save {
        background: #337ab7;
        border: 1px solid #2e6da4;
        border-radius: 3px;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        padding: 8px 18px;
    }

    .idf-settings-save:hover {
        background: #2e6da4;
    }

    .idf-settings-reset {
        background: none;
        border: none;
        color: #888;
        cursor: pointer;
        font-size: 11px;
        text-decoration: underline;
    }

    .idf-settings-reset:hover {
        color: #555;
    }
</style>
