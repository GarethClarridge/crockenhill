@props(['label' => null, 'options' => [], 'placeholder' => null, 'hint' => null, 'required' => false])

@php
$modelName = $attributes->wire('model')?->value();
$wireChange = $attributes->wire('change')?->value();
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);
$selectClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed'
    . ($hasError ? ' border-red-300' : ' border-gray-300');

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
$describedBy = implode(' ', $describedBy);

// Automatically detect wire:model or wire:change to use as wire:target
$target = $attributes->get('wire:target', $modelName ?: $wireChange);

// Except wire:target to avoid duplication in merge
$filteredAttributes = $attributes->except(['wire:target']);
@endphp

<div>
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500" aria-hidden="true">*</span> @endif
        </label>
    @endif

    <div class="relative">
        <select
            {{ $filteredAttributes->merge([
                'class' => $selectClasses,
                'id' => $id,
                'aria-label' => (!$label && $placeholder) ? $placeholder : null
            ]) }}
            @if($required) required aria-required="true" @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($target)
                wire:loading.attr="disabled"
                wire:target="{{ $target }}"
            @endif
        >
            @if($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach($options as $option)
                <option value="{{ $option['id'] }}" @disabled(($option['disabled'] ?? false) === true)>{{ $option['name'] }}</option>
            @endforeach
        </select>

        @if($target)
            <div wire:loading wire:target="{{ $target }}" class="absolute inset-y-0 right-0 pr-8 flex items-center pointer-events-none" role="status">
                <svg class="animate-spin h-4 w-4 text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="sr-only">Loading...</span>
            </div>
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
