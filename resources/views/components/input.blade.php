@props(['label' => null, 'hint' => null, 'required' => false, 'icon' => null, 'clearable' => false, 'maxlength' => null, 'shortcut' => null, 'autofocus' => false])

@php
$modelName = $attributes->wire('model')?->value();
// Numeric segments (form.items.0.title) are invalid JS property access; bracketise for $wire expressions.
$jsModelPath = $modelName ? preg_replace('/\.(\d+)(?=\.|$)/', '[$1]', $modelName) : null;
$id = $attributes->get('id', $modelName ? str_replace(['.', ' ', '[', ']'], '-', $modelName) : ($label ? \Illuminate\Support\Str::slug($label) : null));
$hasError = $modelName && $errors->has($modelName);

$type = $attributes->get('type', 'text');
$isPassword = $type === 'password';

$inputClasses = 'block w-full rounded-md shadow-sm sm:text-sm focus:border-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed'
    . ($icon ? ' pl-10' : '')
    . ($hasError ? ' border-red-300' : ' border-gray-300')
    . ($clearable || $isPassword || $modelName ? ' pr-10' : '');

$describedBy = [];
if ($hint) $describedBy[] = $id . '-hint';
if ($hasError) $describedBy[] = $id . '-error';
if ($maxlength) $describedBy[] = $id . '-counter';
$describedBy = implode(' ', $describedBy);

$clearLabel = 'Clear ' . ($label ?: ($attributes->get('placeholder') ?: 'input'));
@endphp

<div x-data="{ count: 0, limit: {{ $maxlength ?? 'null' }}, focused: false, showPassword: false }"
     x-init="
        count = $refs.input.value.length;
        @if($autofocus) $nextTick(() => $refs.input.focus()); @endif
        @if($modelName)
            $watch('$wire.{{ $jsModelPath }}', value => {
                count = (value ?? '').toString().length;
            });
        @endif
     "
     @if($shortcut === 'slash') @keydown.window.slash="if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && !document.activeElement.isContentEditable) { $event.preventDefault(); $refs.input.focus(); }" @endif>
    @if($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required) <span class="text-red-500" aria-hidden="true">*</span> @endif
        </label>
    @endif

    <div class="relative">
        @if($icon)
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-5 w-5 text-gray-400" aria-hidden="true" />
            </div>
        @endif

        <input
            x-ref="input"
            @input="count = $el.value.length"
            @focus="focused = true"
            @blur="focused = false"
            {{ $attributes->merge([
                'type' => 'text',
                'class' => $inputClasses,
                'id' => $id,
                'aria-label' => (!$label && $attributes->get('placeholder')) ? $attributes->get('placeholder') : null,
                'title' => ($shortcut === 'slash') ? 'Press / to focus' : ($attributes->get('title') ?: null)
            ]) }}
            @if($isPassword)
                :type="showPassword ? 'text' : 'password'"
            @endif
            @if($maxlength) maxlength="{{ $maxlength }}" @endif
            @if($required) required aria-required="true" @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($clearable && $modelName)
                @keydown.escape="$wire.set('{{ $modelName }}', ''); count = 0; $refs.input.focus()"
            @endif
        />

        @if($modelName)
            <div wire:loading wire:target="{{ $modelName }}" class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none" role="status">
                <svg class="animate-spin h-4 w-4 text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="sr-only">Loading...</span>
            </div>
        @endif

        @if($clearable && $modelName)
            <button type="button"
                aria-label="{{ $clearLabel }}"
                title="{{ $clearLabel }}"
                wire:click="$set('{{ $modelName }}', '')"
                @click="count = 0; $nextTick(() => $refs.input.focus())"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded"
                x-show="$wire.{{ $jsModelPath }}"
                x-transition
                wire:loading.remove wire:target="{{ $modelName }}">
                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
            </button>
        @endif

        @if($isPassword)
            <button type="button"
                @click="showPassword = !showPassword"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :title="showPassword ? 'Hide password' : 'Show password'"
                @if($modelName) wire:loading.remove wire:target="{{ $modelName }}" @endif
                x-cloak>
                <x-heroicon-o-eye x-show="!showPassword" class="h-5 w-5" aria-hidden="true" />
                <x-heroicon-o-eye-slash x-show="showPassword" class="h-5 w-5" aria-hidden="true" />
            </button>
        @endif

        @if($shortcut === 'slash')
            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none"
                 x-show="!focused && count === 0"
                 x-transition
                 wire:loading.remove @if($modelName) wire:target="{{ $modelName }}" @endif>
                <kbd class="inline-flex items-center rounded border border-gray-300 px-1.5 font-sans text-xs font-medium text-gray-500 bg-gray-100">
                    /
                </kbd>
            </div>
        @endif
    </div>

    @if($hint || $maxlength)
        <div class="mt-1 flex justify-between gap-4">
            @if($hint)
                <p @if($id) id="{{ $id }}-hint" @endif class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
            @if($maxlength)
                <p @if($id) id="{{ $id }}-counter" @endif class="text-xs tabular-nums ml-auto transition-colors duration-200"
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
