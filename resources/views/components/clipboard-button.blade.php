@props([
    'content' => null,
    'url' => null,
    'hideLabel' => false,
    'label' => 'Copy link',
    'copiedLabel' => 'Copied!',
    'icon' => 'link',
    'copiedIcon' => 'check',
])

@php
$copyContent = $content ?? $url;
$baseClasses = 'inline-flex items-center transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2';
$defaultClasses = $hideLabel
    ? 'p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 rounded'
    : 'gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm';

$classes = $baseClasses . ' ' . $defaultClasses;

$ariaLabel = $attributes->get('aria-label', $label);
$title = $attributes->get('title', $label . ' to clipboard');

$iconComponent = 'heroicon-o-' . $icon;
$copiedIconComponent = 'heroicon-o-' . $copiedIcon;
@endphp

<button
    type="button"
    x-data="{ copied: false }"
    x-show="navigator.clipboard"
    @click="
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($copyContent) }}).then(() => {
            copied = true;
            setTimeout(() => copied = false, 2000);
        });
    "
    {{ $attributes->merge(['class' => $classes]) }}
    :aria-label="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($ariaLabel) }}"
    :title="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($title) }}"
    x-cloak
>
    <x-dynamic-component :component="$iconComponent" x-show="!copied" class="w-4 h-4" aria-hidden="true" />
    <x-dynamic-component :component="$copiedIconComponent" x-show="copied" class="w-4 h-4 text-cbc-teal" aria-hidden="true" x-cloak />

    <span @if($hideLabel) x-bind:class="{ 'sr-only': !copied }" @endif
          x-text="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($label) }}"
          aria-live="polite">
    </span>
</button>
