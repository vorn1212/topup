@php
    $adding = $field === null;
    $cell = 'rounded-md border-gray-300 text-sm shadow-sm w-full';
    $label = old('label', $field?->label);
@endphp

<div class="rounded-md border border-gray-200 p-3">
    <form method="POST"
          action="{{ $adding ? route('admin.games.fields.store', $game) : route('admin.games.fields.update', [$game, $field]) }}"
          class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
        @csrf
        @unless ($adding) @method('PATCH') @endunless

        <div>
            <label class="text-xs text-gray-500">Label</label>
            <input name="label" type="text" required class="{{ $cell }}" value="{{ $adding ? old('label') : $field->label }}" placeholder="Player ID" />
        </div>
        <div>
            <label class="text-xs text-gray-500">Field name</label>
            <input name="provider_field_name" type="text" required class="{{ $cell }}" value="{{ $adding ? old('provider_field_name') : $field->provider_field_name }}" placeholder="player_id" />
        </div>
        <div>
            <label class="text-xs text-gray-500">Type</label>
            <select name="type" class="{{ $cell }}">
                @foreach (['text', 'number', 'select'] as $type)
                    <option value="{{ $type }}" @selected(($adding ? old('type', 'text') : $field->type) === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-500">Rules</label>
            <input name="validation_rules" type="text" class="{{ $cell }}" value="{{ $adding ? old('validation_rules') : $field->validation_rules }}" placeholder="digits_between:5,15" />
        </div>
        <div>
            <label class="text-xs text-gray-500">Options (select)</label>
            <input name="options" type="text" class="{{ $cell }}" value="{{ $adding ? old('options') : implode(', ', $field->options ?? []) }}" placeholder="Asia, Europe" />
        </div>
        <div class="flex items-end gap-2">
            <div class="w-16">
                <label class="text-xs text-gray-500">Sort</label>
                <input name="sort_order" type="number" min="0" class="{{ $cell }}" value="{{ $adding ? old('sort_order', 0) : $field->sort_order }}" />
            </div>
            <label class="pb-2 text-xs text-gray-600 whitespace-nowrap">
                <input type="checkbox" name="required" value="1" class="rounded border-gray-300" @checked($adding ? old('required', '1') : $field->required)> Required
            </label>
        </div>

        <div class="col-span-2 md:col-span-6 flex gap-3">
            <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500">{{ $adding ? 'Add field' : 'Save field' }}</button>
        </div>
    </form>

    @unless ($adding)
        <form method="POST" action="{{ route('admin.games.fields.destroy', [$game, $field]) }}" class="mt-1"
              onsubmit="return confirm('Remove this field?')">
            @csrf @method('DELETE')
            <button class="text-xs text-red-600 hover:underline">Remove</button>
        </form>
    @endunless
</div>