@props(['label' => null, 'hint' => null, 'required' => false, 'maxlength' => null, 'autofocus' => false, 'autogrow' => false])

@php
$modelName = $attributes->wire('model')?->value();
// Numeric segments (form.items.0.title) are invalid JS property access; bracketise for $wire expressions.
$jsModelPath = $modelName ? preg_replace('/\.(\d+)(?=\.|$)/', '[$1]', $modelName) : null;
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);
$textareaClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed'
    . ($hasError ? ' border-red-300' : ' border-gray-300')
    . ($modelName ? ' pr-10' : '')
    . ($autogrow ? ' resize-none overflow-hidden' : '');

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
if ($maxlength) $describedBy[] = $id . '-counter';
$describedBy = implode(' ', $describedBy);
@endphp

<div x-data="{
    count: 0,
    limit: {{ $maxlength ?? 'null' }},
    autogrow: {{ $autogrow ? 'true' : 'false' }},
    resizeObserver: null,
    resize(el) {
        if (!this.autogrow || !el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }
}" x-init="
    count = $refs.textarea.value.length;
    @if($autofocus) $nextTick(() => $refs.textarea.focus()) @endif
    if (autogrow) {
        $nextTick(() => resize($refs.textarea));
        if (typeof ResizeObserver !== 'undefined') {
            resizeObserver = new ResizeObserver(() => resize($refs.textarea));
            resizeObserver.observe($refs.textarea);
        }
    }
    @if($modelName)
        $watch('$wire.{{ $jsModelPath }}', () => {
            count = $refs.textarea.value.length;
            if (autogrow) resize($refs.textarea);
        });
    @endif
" x-destroy="resizeObserver?.disconnect()">
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500" aria-hidden="true">*</span> @endif
        </label>
    @endif

    <div class="relative">
        <textarea
            x-ref="textarea"
            @input="count = $el.value.length; if (autogrow) resize($el);"
            {{ $attributes->merge([
                'rows' => 3,
                'class' => $textareaClasses,
                'id' => $id,
                'aria-label' => (!$label && $attributes->get('placeholder')) ? $attributes->get('placeholder') : null
            ]) }}
            @if($maxlength) maxlength="{{ $maxlength }}" @endif
            @if($required) required aria-required="true" @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >{{ $slot }}</textarea>

        @if($modelName)
            <div wire:loading wire:target="{{ $modelName }}" class="absolute top-0 right-0 pt-2 pr-3 flex items-center pointer-events-none" role="status">
                <svg class="animate-spin h-4 w-4 text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="sr-only">Loading...</span>
            </div>
        @endif
    </div>

    @if($hint || $maxlength)
        <div class="mt-1 flex justify-between gap-4">
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
            @if($maxlength)
                <div class="flex items-center gap-1.5 ml-auto">
                    <svg class="h-4 w-4 -rotate-90 transform" viewBox="0 0 20 20" aria-hidden="true">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2.5" fill="none" class="text-gray-100" />
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2.5" fill="none"
                            stroke-dasharray="50.27"
                            :stroke-dashoffset="50.27 - (Math.min(count, limit) / limit) * 50.27"
                            stroke-linecap="round"
                            class="transition-all duration-300"
                            :class="{
                                'text-red-500': count >= limit,
                                'text-amber-500': count >= (limit * 0.9) && count < limit,
                                'text-cbc-teal': count < (limit * 0.9)
                            }" />
                    </svg>
                    <p @if($id) id="{{ $id }}-counter" @endif class="text-xs tabular-nums transition-colors duration-200"
                       :class="{
                           'text-red-600 font-bold': limit && count >= limit,
                           'text-amber-600 font-medium': limit && count >= (limit * 0.9) && count < limit,
                           'text-gray-400': !limit || count < (limit * 0.9)
                       }"
                       aria-live="polite">
                        <span x-text="count"></span> / {{ $maxlength }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    @if($modelName)
        @error($modelName)
            <p @if($id) id="{{ $id }}-error" @endif class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
