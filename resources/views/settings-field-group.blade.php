<fieldset class="idf-settings-group">
    <legend>{{ $groupLabel }}</legend>

    @if ($groupKey === 'problem')
        <p class="idf-settings-group-intro">
            Operational Priority (above) decides Critical/Warning/Monitor/
            Ignore for a real technical condition, from Direct-severity
            Alert Rules and this dashboard's own condition policy alike.
            This section covers a third, narrower concept: data
            <strong>quality</strong>, not severity — whether a
            <em>missing</em> sensor of this type is flagged "No sensor
            installed", and whether an <em>unreadable/untranslated</em>
            reading is flagged "Needs Review". Neither of those is something
            an Alert Rule can express, since a rule can only evaluate a
            sensor that already exists and already has a decodable value.
        </p>
    @endif

    @if ($groupKey === 'tv')
        <p class="idf-settings-group-intro">
            TV Mode is a curated wall/NOC projection, not the normal
            dashboard rendered full-screen — it shows only what deserves
            immediate visual attention. These settings are pure
            presentation filters: they can never change device health,
            location health, normal Priority Attention, header counters, or
            Alert Rule inclusion — only whether TV Mode projects it. A
            device with several simultaneous conditions still appears on TV
            if <strong>any one</strong> of them remains TV-eligible, credited
            to that cause. Monitor-tier issues never appear on TV, with no
            separate setting needed — they are already excluded everywhere
            actionable issues are counted.
        </p>
    @endif

    <div class="idf-settings-field-grid">
        @foreach ($groups[$groupKey] ?? [] as $key => $field)
            @continue(($field['hidden'] ?? false))
            @include('IdfDashboard::resources.views.settings-field', ['key' => $key, 'field' => $field])
        @endforeach
    </div>
</fieldset>
