@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit', 'icon' => null, 'loadingLabel' => null])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 transition-all active:scale-95 duration-200';

$sizeClasses = [
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-base',
    'lg' => 'px-6 py-3 text-lg',
    'xl' => 'px-8 py-4 text-xl',
];

$variantClasses = [
    'primary'   => 'bg-cbc-pattern bg-size-cover text-white hover:brightness-110',
    'secondary' => 'border border-transparent bg-slate-100/90 text-[#145557] hover:bg-white shadow-none',
    'outline'   => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
    'danger'    => 'bg-cbc-crimson hover:bg-[#590d16] text-white',
    'warning'   => 'bg-amber-600 hover:bg-amber-700 text-white',
    'success'   => 'bg-green-600 hover:bg-green-700 text-white',
    'ghost'     => 'hover:bg-gray-100 text-gray-600',
];

$classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];

// Automatically detect wire:click or wire:submit to use as wire:target
$wireClick = $attributes->wire('click')->value();
$wireSubmit = $attributes->wire('submit')->value();
$target = $attributes->get('wire:target', $wireClick ?: $wireSubmit);

// Except wire:target to avoid duplication in merge
$filteredAttributes = $attributes->except(['wire:target']);

if ($slot->isEmpty() && $attributes->has('aria-label') && !$attributes->has('title')) {
    $filteredAttributes = $filteredAttributes->merge(['title' => $attributes->get('aria-label')]);
}
@endphp

<button {{ $filteredAttributes->merge(['class' => $classes, 'type' => $type]) }} wire:loading.attr="disabled" wire:offline.attr="disabled" aria-disabled="false" wire:loading.attr="aria-disabled" @if($target) wire:target="{{ $target }}" @endif>
    @if($icon)
      <span wire:loading.remove @if($target) wire:target="{{ $target }}" @endif class="contents">
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-4 h-4 {{ $slot->isNotEmpty() ? 'mr-2' : '' }}" aria-hidden="true" />
      </span>
    @endif
    <span wire:loading @if($target) wire:target="{{ $target }}" @endif role="status" aria-live="polite" class="{{ ($slot->isNotEmpty() || $loadingLabel) ? 'mr-2' : '' }}">
        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="sr-only">Loading...</span>
    </span>

    @if($loadingLabel)
        <span wire:loading @if($target) wire:target="{{ $target }}" @endif>
            {{ $loadingLabel }}
        </span>
        <span wire:loading.remove @if($target) wire:target="{{ $target }}" @endif>
            {{ $slot }}
        </span>
    @else
        {{ $slot }}
    @endif
</button>
