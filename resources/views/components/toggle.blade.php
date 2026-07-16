@props(['label' => null, 'hint' => null, 'checked' => false, 'required' => false])

@php
    $modelName = $attributes->wire('model')->value();
    $isLivewire = (bool) $modelName;
    $id = $attributes->get('id', $modelName
        ? str_replace(['.', ' ', '[', ']'], '-', $modelName)
        : ($label ? \Illuminate\Support\Str::slug($label) : 'toggle-' . \Illuminate\Support\Str::random(8)));
    $hasError = $modelName && $errors->has($modelName);

    $describedBy = [];
    if ($hint) $describedBy[] = $id . '-hint';
    if ($hasError) $describedBy[] = $id . '-error';
    $describedBy = implode(' ', $describedBy);
@endphp

<div class="flex items-start gap-3">
    <div class="flex h-6 items-center">
        @if($isLivewire)
            <button type="button"
                role="switch"
                @if($id) id="{{ $id }}" @endif
                @if($label) aria-labelledby="{{ $id }}-label" @endif
                @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if($required) aria-required="true" @endif
                @if($hasError) aria-invalid="true" @endif
                x-data="{}"
                :aria-checked="$wire['{{ $modelName }}']"
                @click="$wire['{{ $modelName }}'] = !$wire['{{ $modelName }}']"
                wire:loading.attr="disabled"
                wire:loading.attr="aria-disabled"
                aria-disabled="false"
                wire:target="{{ $modelName }}"
                {{ $attributes->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed']) }}
                :class="$wire['{{ $modelName }}'] ? 'bg-cbc-teal' : 'bg-gray-200'">
                <span :class="$wire['{{ $modelName }}'] ? 'translate-x-5' : 'translate-x-0'"
                    class="pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out">
                    <svg wire:loading wire:target="{{ $modelName }}" class="absolute inset-0 h-full w-full animate-spin text-cbc-teal p-1" style="display:none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="sr-only">Loading...</span>
                </span>
            </button>
        @else
            {{-- Plain Blade form mode: renders a hidden checkbox input --}}
            <button type="button"
                role="switch"
                @if($id) id="{{ $id }}" @endif
                @if($label) aria-labelledby="{{ $id }}-label" @endif
                @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if($required) aria-required="true" @endif
                x-data="{ checked: {{ $checked ? 'true' : 'false' }} }"
                :aria-checked="checked"
                @click="checked = !checked"
                {{ $attributes->except(['name', 'checked'])->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed']) }}
                :class="checked ? 'bg-cbc-teal' : 'bg-gray-200'">
                <span :class="checked ? 'translate-x-5' : 'translate-x-0'"
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
            </button>
            @if($attributes->get('name'))
                <input type="hidden"
                    name="{{ $attributes->get('name') }}"
                    :value="checked ? '1' : '0'"
                    x-bind:value="checked ? '1' : '0'"
                />
            @endif
        @endif
    </div>

    @if($label || $hint || $hasError)
        <div class="text-sm leading-6">
            @if($label)
                <label @if($id) id="{{ $id }}-label" for="{{ $id }}" @endif class="cursor-pointer font-medium text-gray-700">
                    {{ $label }}
                    @if($required) <span class="text-red-500" aria-hidden="true">*</span> @endif
                </label>
            @endif
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-gray-500">{{ $hint }}</p>
            @endif
            @if($modelName)
                @error($modelName)
                    <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            @endif
        </div>
    @endif
</div>
