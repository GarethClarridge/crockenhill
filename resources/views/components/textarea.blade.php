@props(['label' => null, 'hint' => null, 'required' => false, 'maxlength' => null])

@php
$modelName = $attributes->wire('model')->value();
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);
$textareaClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-cbc-teal focus:ring-cbc-teal'
    . ($hasError ? ' border-red-300' : ' border-gray-300');

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
$describedBy = implode(' ', $describedBy);
@endphp

<div x-data="{ count: 0, limit: {{ $maxlength ?? 'null' }} }" x-init="count = $refs.textarea.value.length">
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500">*</span> @endif
        </label>
    @endif

    <textarea
        @if($id) id="{{ $id }}" @endif
        x-ref="textarea"
        @input="count = $el.value.length"
        {{ $attributes->merge(['rows' => 3, 'class' => $textareaClasses]) }}
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($hasError) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
    >{{ $slot }}</textarea>

    @if($hint || $maxlength)
        <div class="mt-1 flex justify-between gap-4">
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
            @if($maxlength)
                <p class="text-xs tabular-nums ml-auto transition-colors duration-200"
                   :class="{
                       'text-red-600 font-bold': limit !== null && count >= limit,
                       'text-amber-600 font-medium': limit !== null && count >= (limit * 0.9) && count < limit,
                       'text-gray-400': limit === null || count < (limit * 0.9)
                   }"
                   aria-live="polite">
                    <span x-text="count"></span> / {{ $maxlength }}
                </p>
            @endif
        </div>
    @endif

    @if($modelName)
        @error($modelName)
            <p @if($id) id="{{ $id }}-error" @endif class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
