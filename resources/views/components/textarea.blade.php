@props(['label' => null, 'hint' => null, 'required' => false, 'maxlength' => null, 'autofocus' => false])

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

<div x-data="{ count: 0, limit: {{ $maxlength ?? 'null' }} }" x-init="count = $refs.textarea.value.length; @if($autofocus) $nextTick(() => $refs.textarea.focus()) @endif">
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500">*</span> @endif
        </label>
    @endif

    <div class="relative">
        <textarea
            @if($id) id="{{ $id }}" @endif
            x-ref="textarea"
            @input="count = $el.value.length"
            {{ $attributes->merge(['rows' => 3, 'class' => $textareaClasses]) }}
            @if($maxlength) maxlength="{{ $maxlength }}" @endif
            @if(!$label && $attributes->get('placeholder')) aria-label="{{ $attributes->get('placeholder') }}" @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >{{ $slot }}</textarea>

        @if($modelName)
            <div wire:loading wire:target="{{ $modelName }}" class="absolute top-0 right-0 pt-2 pr-3 flex items-center pointer-events-none">
                <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
        @endif
    </div>

    @if($hint || $maxlength)
        <div class="mt-1 flex justify-between gap-4">
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
            @if($maxlength)
                <p class="text-xs tabular-nums ml-auto transition-colors duration-200"
                   :class="{
                       'text-red-600 font-bold': limit && count >= limit,
                       'text-amber-600 font-medium': limit && count >= (limit * 0.9) && count < limit,
                       'text-gray-400': !limit || count < (limit * 0.9)
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
