@props(['url', 'hideLabel' => false])

@php
$baseClasses = 'inline-flex items-center transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1';
$defaultClasses = $hideLabel
    ? 'p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 rounded'
    : 'gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm';

$classes = $baseClasses . ' ' . $defaultClasses;
@endphp

<button
    type="button"
    x-data="{ copied: false }"
    x-show="navigator.clipboard"
    @click="
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText('{{ $url }}').then(() => {
            copied = true;
            setTimeout(() => copied = false, 2000);
        });
    "
    {{ $attributes->merge(['class' => $classes]) }}
    aria-label="{{ $attributes->get('aria-label', 'Copy link') }}"
    title="{{ $attributes->get('title', 'Copy link to clipboard') }}"
    x-cloak
>
    <x-heroicon-o-link x-show="!copied" class="w-4 h-4" aria-hidden="true" />
    <x-heroicon-o-check x-show="copied" class="w-4 h-4 text-cbc-teal" aria-hidden="true" x-cloak />
    @if(!$hideLabel)
        <span x-text="copied ? 'Copied!' : 'Copy link'" aria-live="polite"></span>
    @endif
</button>
