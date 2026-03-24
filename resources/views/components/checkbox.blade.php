@props(['label' => null, 'hint' => null, 'checked' => false])

@php
$modelName = $attributes->wire('model')->value();
$id = $attributes->get('id', $modelName
    ? str_replace(['.', ' ', '[', ']'], '-', $modelName)
    : ($label ? \Illuminate\Support\Str::slug($label) : 'checkbox-' . \Illuminate\Support\Str::random(8)));
$hasError = $modelName && $errors->has($modelName);
@endphp

<div class="flex items-start gap-3">
    <div class="flex h-5 items-center">
        <input
            type="checkbox"
            @if($id) id="{{ $id }}" @endif
            {{ $attributes->merge(['class' => 'h-4 w-4 rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal' . ($hasError ? ' border-red-300' : '')]) }}
            @checked($checked)
            @if($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        />
    </div>
    @if($label)
        <div class="text-sm leading-5">
            <label @if($id) for="{{ $id }}" @endif class="cursor-pointer font-medium text-gray-700">
                {{ $label }}
            </label>
            @if($hint)
                <p class="text-gray-500">{{ $hint }}</p>
            @endif
        </div>
    @endif
    @if($modelName)
        @error($modelName)
            <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
