@props(['label' => null, 'hint' => null, 'checked' => false])

@php
    $modelName = $attributes->wire('model')->value();
    $isLivewire = (bool) $modelName;
    $id = $attributes->get('id', $modelName
        ? str_replace(['.', ' ', '[', ']'], '-', $modelName)
        : ($label ? \Illuminate\Support\Str::slug($label) : 'toggle-' . \Illuminate\Support\Str::random(8)));
@endphp

<div class="flex items-center gap-3">
    @if($isLivewire)
        <button type="button"
            role="switch"
            @if($id) id="{{ $id }}" @endif
            @if($label) aria-labelledby="{{ $id }}-label" @endif
            x-data="{}"
            :aria-checked="$wire['{{ $modelName }}']"
            @click="$wire['{{ $modelName }}'] = !$wire['{{ $modelName }}']"
            wire:loading.attr="disabled"
            wire:target="{{ $modelName }}"
            {{ $attributes->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed']) }}
            :class="$wire['{{ $modelName }}'] ? 'bg-cbc-teal' : 'bg-gray-200'">
            <span :class="$wire['{{ $modelName }}'] ? 'translate-x-5' : 'translate-x-0'"
                class="pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out">
                <svg wire:loading wire:target="{{ $modelName }}" class="absolute inset-0 h-full w-full animate-spin text-cbc-teal p-1" style="display:none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </span>
        </button>
    @else
        {{-- Plain Blade form mode: renders a hidden checkbox input --}}
        <button type="button"
            role="switch"
            @if($id) id="{{ $id }}" @endif
            @if($label) aria-labelledby="{{ $id }}-label" @endif
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
    @if($label)
        <label @if($id) id="{{ $id }}-label" for="{{ $id }}" @endif class="cursor-pointer">
            <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
            @if($hint)
                <p class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
        </label>
    @endif
</div>
