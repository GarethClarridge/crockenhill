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
            x-data="{ checked: $wire.entangle('{{ $modelName }}') }"
            :aria-checked="checked"
            @click="checked = !checked"
            {{ $attributes->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2']) }}
            :class="checked ? 'bg-green-600' : 'bg-gray-200'">
            <span :class="checked ? 'translate-x-5' : 'translate-x-0'"
                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
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
            {{ $attributes->except(['name', 'checked'])->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2']) }}
            :class="checked ? 'bg-green-600' : 'bg-gray-200'">
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
