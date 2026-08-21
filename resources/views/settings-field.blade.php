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
