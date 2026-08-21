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
            $groupLabels = [
                'timing' => 'Refresh & Timing',
                'thresholds' => 'Battery Charge Thresholds',
                'visual' => 'Visual Effects',
                'navigation' => 'Navigation & Lists',
                'updates' => 'Updates',
                'policy' => 'Operational Priority Policy',
                'operational_severity_policy' => 'Operational Severity Policy',
                'severity' => 'Default Severity Shown on Load',
                'problem' => 'Sensor Coverage & Data-Quality Checks',
                'section' => 'Default Sections Visible on Load',
                'tv_restrict' => 'TV Mode Additional Restrictions',
            ];
        @endphp

        @foreach ($groupLabels as $groupKey => $groupLabel)
            <fieldset class="idf-settings-group">
                <legend>{{ $groupLabel }}</legend>

                @if ($groupKey === 'policy')
                    <p class="idf-settings-group-intro">
                        A technical failure is not automatically an
                        operationally critical incident — a kitchen printer
                        being down is not the same as a production cluster
                        being unreachable. This dashboard prefers
                        administrator-selected LibreNMS Alert Rules for
                        Critical/Warning severity; the setting below only
                        controls its bounded fallback for a condition no
                        Alert Rule currently covers (a real technical
                        failure must never be silently hidden, but not every
                        failure should page someone). Create a LibreNMS
                        Device Group with this exact name and add your
                        clusters, virtualization hosts, critical servers,
                        core/distribution switches, firewalls and critical
                        UPS/PDUs to it — nothing else needs to change here;
                        membership is managed entirely in LibreNMS's own
                        Device Groups admin page. Leave blank (or point it
                        at a group with no members) and every device
                        defaults to Warning rather than Critical for
                        fallback-covered conditions.
                    </p>
                @endif

                @if ($groupKey === 'operational_severity_policy')
                    <p class="idf-settings-group-intro">
                        Alert Rules remain authoritative whenever they cover a
                        condition with an exact match. Device Down is the one
                        condition tag below ("Included LibreNMS Alert Rules")
                        that can actually suppress its matching fallback,
                        because a Device Down Alert Rule and the fallback it
                        replaces always share the same device. Sensor and
                        Service tags are recorded for documentation and
                        Policy Health only — they are deliberately never used
                        to suppress a sensor/service fallback, since no exact
                        per-sensor/per-service identifier exists to confirm a
                        given Alert Rule actually covers a given sensor or
                        service. An unrelated Alert Rule must never hide a
                        real, different failure; a harmless visual duplicate
                        is always preferred over a hidden incident. These
                        settings control only the safety-net fallback itself
                        — never used to override or compete with a real Alert
                        Rule's own severity.
                    </p>
                @endif

                @if ($groupKey === 'problem')
                    <p class="idf-settings-group-intro">
                        Whether a reading is a real problem is decided only
                        by the LibreNMS Alert Rules checked below under
                        "Included LibreNMS Alert Rules" — this dashboard no
                        longer evaluates sensor thresholds itself. These
                        checkboxes control something narrower: whether a
                        <em>missing</em> sensor of this type is flagged as
                        "No sensor installed", and whether an
                        <em>unreadable/unrecognized</em> reading of this
                        type is flagged as "Needs Review" — neither of
                        those is something an Alert Rule can express, since
                        a rule can only evaluate a sensor that already
                        exists and already has a decodable value.
                    </p>
                @endif

                <div class="idf-settings-field-grid">
                    @foreach ($groups[$groupKey] ?? [] as $key => $field)
                        <div class="idf-settings-field idf-settings-field-{{ $field['type'] }}">
                            @if ($field['type'] === 'bool')
                                <label class="idf-settings-checkbox-label">
                                    <input type="hidden" name="settings[{{ $key }}]" value="0">
                                    <input
                                        type="checkbox"
                                        name="settings[{{ $key }}]"
                                        value="1"
                                        @checked($resolved[$key])
                                    >
                                    {{ $field['label'] }}
                                </label>
                            @elseif ($field['type'] === 'choice')
                                <label class="idf-settings-number-label">
                                    <span>{{ $field['label'] }}</span>
                                    <select name="settings[{{ $key }}]">
                                        @foreach ($field['options'] as $optionValue => $optionLabel)
                                            <option
                                                value="{{ $optionValue }}"
                                                @selected((string) $resolved[$key] === (string) $optionValue)
                                            >{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @elseif ($field['type'] === 'string')
                                <label class="idf-settings-number-label">
                                    <span>{{ $field['label'] }}</span>
                                    <input
                                        type="text"
                                        name="settings[{{ $key }}]"
                                        value="{{ $resolved[$key] }}"
                                        maxlength="{{ $field['max_length'] }}"
                                    >
                                </label>
                            @else
                                <label class="idf-settings-number-label">
                                    <span>{{ $field['label'] }}</span>
                                    <input
                                        type="number"
                                        name="settings[{{ $key }}]"
                                        value="{{ $resolved[$key] }}"
                                        min="{{ $field['min'] }}"
                                        max="{{ $field['max'] }}"
                                        step="1"
                                    >
                                </label>
                            @endif

                            @if ($field['help'] !== '')
                                <div class="idf-settings-help">{{ $field['help'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <fieldset class="idf-settings-group">
            <legend>Included LibreNMS Alert Rules</legend>

            <p class="idf-settings-group-intro">
                Every rule below is a real Alert Rule already configured in LibreNMS
                (Alert Rules admin page). Checked rules feed this dashboard's "Alert"
                issues; unchecked rules are simply not shown here — LibreNMS keeps
                evaluating and notifying on them exactly as configured either way.
                Nothing is hidden automatically anymore: a rule stays included the
                first time you open this page, and stays exactly as you left it after
                that.
            </p>

            @if (empty($availableAlertRules))
                <div class="idf-settings-help">
                    No LibreNMS Alert Rules were found (or this LibreNMS version's
                    schema was not recognized). Every currently active alert is shown
                    on the dashboard; there is nothing to select yet.
                </div>
            @else
                <input type="hidden" name="settings[{{ $alertRuleSettingKey }}][]" value="">

                <div class="idf-settings-field-grid idf-alert-rules-grid">
                    @foreach ($availableAlertRules as $rule)
                        <div class="idf-settings-field idf-settings-field-bool">
                            <label class="idf-settings-checkbox-label">
                                <input
                                    type="checkbox"
                                    class="idf-alert-rule-checkbox"
                                    name="settings[{{ $alertRuleSettingKey }}][]"
                                    value="{{ $rule['id'] }}"
                                    @checked(in_array($rule['id'], $includedAlertRuleIds, true))
                                >
                                <span>
                                    {{ $rule['name'] }}
                                    @if ($rule['severity'] !== '')
                                        <span class="idf-alert-rule-severity">{{ $rule['severity'] }}</span>
                                    @endif
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <p class="idf-settings-group-intro">
                    For each rule above, record which technical condition
                    category it covers. <strong>Tagging "Device Down" is the
                    only tag that actually suppresses its matching
                    fallback</strong> — a Device Down Alert Rule and the
                    fallback it replaces always share the same device, so
                    that correlation is exact. Tagging "Sensors" or
                    "Services" does <strong>not</strong> suppress any
                    sensor/service fallback by itself — it is recorded only
                    for documentation and Policy Health visibility, because
                    no exact per-sensor/per-service identifier exists to
                    confirm a rule really covers a specific sensor or
                    service. Leave every box unchecked for a rule unrelated
                    to this dashboard's own fallback categories.
                </p>

                <div class="idf-settings-field-grid idf-alert-rule-conditions-grid">
                    @foreach ($availableAlertRules as $rule)
                        <div class="idf-settings-field idf-settings-field-bool">
                            <span class="idf-alert-rule-condition-name">{{ $rule['name'] }}</span>
                            <input type="hidden" name="settings[{{ $alertRuleConditionSettingKey }}][{{ $rule['id'] }}][]" value="">
                            @foreach ($alertRuleConditionCategories as $categoryValue => $categoryLabel)
                                <label class="idf-settings-checkbox-label">
                                    <input
                                        type="checkbox"
                                        name="settings[{{ $alertRuleConditionSettingKey }}][{{ $rule['id'] }}][]"
                                        value="{{ $categoryValue }}"
                                        @checked(in_array($categoryValue, $alertRuleConditionCoverage[$rule['id']] ?? [], true))
                                    >
                                    {{ $categoryLabel }}
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </fieldset>

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

    // Condition coverage has no FIELDS default either — "reset to
    // defaults" means "nothing declared", matching
    // Support\AlertRules::resolveConditionCoverage()'s own safe
    // default (the fallback keeps running for every category until an
    // administrator explicitly confirms a rule covers it).
    document.querySelectorAll('.idf-alert-rule-conditions-grid input[type="checkbox"]').forEach(function (input) {
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

    .idf-settings-group legend {
        color: #337ab7;
        font-size: 13px;
        font-weight: 700;
        padding: 0 6px;
        text-transform: uppercase;
        letter-spacing: .03em;
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

    .idf-alert-rules-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
