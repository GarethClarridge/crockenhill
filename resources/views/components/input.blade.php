@props(['label' => null, 'hint' => null, 'required' => false, 'icon' => null, 'clearable' => false])

@php
$modelName = $attributes->wire('model')->value();
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);
$inputClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500'
    . ($icon ? ' pl-10' : '')
    . ($hasError ? ' border-red-300' : ' border-gray-300');

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
$describedBy = implode(' ', $describedBy);
@endphp

<div>
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500">*</span> @endif
        </label>
    @endif

    <div class="relative">
        @if($icon)
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-5 w-5 text-gray-400" />
            </div>
        @endif

        <input
            @if($id) id="{{ $id }}" @endif
            {{ $attributes->merge(['type' => 'text', 'class' => $inputClasses]) }}
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        />

        @if($clearable && $modelName)
            <button type="button"
                aria-label="Clear input"
                wire:click="$set('{{ $modelName }}', '')"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                x-show="$wire.{{ $modelName }}">
                <x-heroicon-o-x-mark class="h-4 w-4" />
            </button>
        @endif
    </div>

    @if($hint)
        <p @if($id) id="{{ $id }}-hint" @endif class="mt-1 text-sm text-gray-500">{{ $hint }}</p>
    @endif

    @if($modelName)
        @error($modelName)
            <p @if($id) id="{{ $id }}-error" @endif class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
