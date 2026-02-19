@props(['label' => null, 'options' => [], 'placeholder' => null, 'hint' => null, 'required' => false])

@php
$modelName = $attributes->wire('model')->value();
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);
$selectClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500'
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

    <select
        @if($id) id="{{ $id }}" @endif
        {{ $attributes->merge(['class' => $selectClasses]) }}
        @if($hasError) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
    >
        @if($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach($options as $option)
            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
        @endforeach
    </select>

    @if($hint)
        <p @if($id) id="{{ $id }}-hint" @endif class="mt-1 text-sm text-gray-500">{{ $hint }}</p>
    @endif

    @if($modelName)
        @error($modelName)
            <p @if($id) id="{{ $id }}-error" @endif class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
