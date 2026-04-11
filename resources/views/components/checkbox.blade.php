@props(['label' => null, 'hint' => null, 'checked' => false])

@php
$modelName = $attributes->wire('model')->value();
$id = $attributes->get('id', $modelName
    ? str_replace(['.', ' ', '[', ']'], '-', $modelName)
    : ($label ? \Illuminate\Support\Str::slug($label) : 'checkbox-' . \Illuminate\Support\Str::random(8)));
$hasError = $modelName && $errors->has($modelName);

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
$describedBy = implode(' ', $describedBy);
@endphp

<div class="flex items-start gap-3">
    <div class="flex h-5 items-center">
        <input
            type="checkbox"
            @if($id) id="{{ $id }}" @endif
            {{ $attributes->merge(['class' => 'h-4 w-4 rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal' . ($hasError ? ' border-red-300' : '')]) }}
            @checked($checked)
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        />
    </div>
    @if($label)
        <div class="text-sm leading-5">
            <label @if($id) id="{{ $id }}-label" for="{{ $id }}" @endif class="cursor-pointer font-medium text-gray-700">
                {{ $label }}
            </label>
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-gray-500">{{ $hint }}</p>
            @endif
        </div>
    @endif
    @if($modelName)
        @error($modelName)
            <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
