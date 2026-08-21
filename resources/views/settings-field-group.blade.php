<fieldset class="idf-settings-group">
    <legend>{{ $groupLabel }}</legend>

    @if ($groupKey === 'problem')
        <p class="idf-settings-group-intro">
            Alert Rules are the preferred, explicit source of operational
            severity; the Operational Priority fallback is a safety net for
            technical failures no included Alert Rule covers yet — it still
            evaluates sensor/service thresholds on your behalf when enabled.
            This section covers a third, narrower concept: data
            <strong>quality</strong>, not severity — whether a
            <em>missing</em> sensor of this type is flagged "No sensor
            installed", and whether an <em>unreadable/untranslated</em>
            reading is flagged "Needs Review". Neither of those is something
            an Alert Rule can express, since a rule can only evaluate a
            sensor that already exists and already has a decodable value.
        </p>
    @endif

    <div class="idf-settings-field-grid">
        @foreach ($groups[$groupKey] ?? [] as $key => $field)
            @continue(($field['hidden'] ?? false))
            @include('IdfDashboard::resources.views.settings-field', ['key' => $key, 'field' => $field])
        @endforeach
    </div>
</fieldset>
