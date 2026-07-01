@props([
    'content' => null,
    'jsContent' => null,
    'url' => null,
    'hideLabel' => false,
    'label' => 'Copy link',
    'copiedLabel' => 'Copied!',
    'icon' => 'link',
    'copiedIcon' => 'check',
    'size' => 'sm',
    // When set, a successful copy fires a GA `share` event carrying this value as
    // its content_type (GA3). Leave null to opt out of analytics for this button.
    'analytics' => null,
])

@php
$copyContent = $content ?? $url;
$baseClasses = 'inline-flex items-center transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2';

$sizeClasses = [
    'xs' => 'p-1 text-xs',
    'sm' => 'gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md',
    'md' => 'gap-2 px-4 py-2 text-sm font-medium rounded-md',
];

$defaultClasses = $hideLabel
    ? ($sizeClasses['xs'] . ' text-gray-500 hover:bg-gray-100 hover:text-gray-700 rounded')
    : (($sizeClasses[$size] ?? $sizeClasses['sm']) . ' text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 shadow-sm');

$classes = $baseClasses . ' ' . $defaultClasses;

$ariaLabel = $attributes->get('aria-label', $label);
$title = $attributes->get('title', $label . ' to clipboard');

$iconComponent = 'heroicon-o-' . $icon;
$copiedIconComponent = 'heroicon-o-' . $copiedIcon;

$iconSizeClasses = [
    'xs' => 'h-3.5 w-3.5',
    'sm' => 'h-4 w-4',
    'md' => 'h-5 w-5',
];
$resolvedIconSize = $iconSizeClasses[$hideLabel ? 'xs' : $size] ?? $iconSizeClasses['sm'];
@endphp

<button
    type="button"
    x-data="{ copied: false }"
    x-show="navigator.clipboard"
    @click="
        if (!navigator.clipboard) return;
        const textToCopy = {{ $jsContent ?? \Illuminate\Support\Js::from($copyContent) }};
        navigator.clipboard.writeText(textToCopy).then(() => {
            copied = true;
            setTimeout(() => copied = false, 2000);
            @if($analytics)
            if (window.gaTrackEvent) {
                window.gaTrackEvent('share', { method: 'clipboard', content_type: {{ \Illuminate\Support\Js::from($analytics) }} });
            }
            @endif
        });
    "
    {{ $attributes->merge(['class' => $classes]) }}
    :aria-label="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($ariaLabel) }}"
    :title="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($title) }}"
    x-cloak
>
    <div class="relative {{ $resolvedIconSize }} shrink-0">
        <x-dynamic-component
            :component="$iconComponent"
            x-show="!copied"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-90"
            class="absolute inset-0 {{ $resolvedIconSize }}"
            aria-hidden="true"
        />
        <x-dynamic-component
            :component="$copiedIconComponent"
            x-show="copied"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-90"
            class="absolute inset-0 {{ $resolvedIconSize }} text-cbc-teal"
            aria-hidden="true"
            x-cloak
        />
    </div>

    <span @if($hideLabel) class="sr-only" @endif
          x-text="copied ? {{ \Illuminate\Support\Js::from($copiedLabel) }} : {{ \Illuminate\Support\Js::from($label) }}"
          aria-live="polite">
    </span>
</button>
