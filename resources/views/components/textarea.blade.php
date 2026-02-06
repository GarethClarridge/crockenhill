@props(['label' => null, 'hint' => null, 'required' => false])

@php
$modelName = $attributes->wire('model')->value();
$hasError = $modelName && $errors->has($modelName);
$textareaClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500'
    . ($hasError ? ' border-red-300' : ' border-gray-300');
@endphp

<div>
    @if($label)
        <label class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500">*</span> @endif
        </label>
    @endif

    <textarea {{ $attributes->merge(['rows' => 3, 'class' => $textareaClasses]) }}></textarea>

    @if($hint)
        <p class="mt-1 text-sm text-gray-500">{{ $hint }}</p>
    @endif

    @if($modelName)
        @error($modelName)
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    @endif
</div>
