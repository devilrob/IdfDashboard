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

    <form method="post">
        @csrf

        @php
            $groupLabels = [
                'timing' => 'Refresh & Timing',
                'thresholds' => 'Battery Charge Thresholds',
                'visual' => 'Visual Effects',
                'severity' => 'Default Severity Shown on Load',
                'problem' => 'Default Problem Types Shown on Load',
                'section' => 'Default Sections Visible on Load',
            ];
        @endphp

        @foreach ($groupLabels as $groupKey => $groupLabel)
            <fieldset class="idf-settings-group">
                <legend>{{ $groupLabel }}</legend>

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

    .idf-settings-number-label input {
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
