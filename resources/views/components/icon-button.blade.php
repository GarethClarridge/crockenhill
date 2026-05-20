@props([
    'icon',
    'label',
    'variant' => 'ghost',
    'size' => 'sm',
    'iconStyle' => 'outline',
    'type' => 'button',
])

@php
$sizeClasses = [
    'xs' => 'p-1',
    'sm' => 'p-1.5',
    'md' => 'p-2',
    'lg' => 'p-2.5',
];

$variantClasses = [
    'ghost'   => 'text-gray-500 hover:bg-gray-100 hover:text-gray-700',
    'danger'  => 'text-red-500 hover:bg-red-50 hover:text-red-700',
    'teal'    => 'text-cbc-teal hover:bg-cbc-teal/10',
    'outline' => 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50',
];

$iconSizes = [
    'xs' => 'h-3.5 w-3.5',
    'sm' => 'h-4 w-4',
    'md' => 'h-5 w-5',
    'lg' => 'h-5 w-5',
];

$iconComponent = 'heroicon-' . ($iconStyle === 'solid' ? 's' : 'o') . '-' . $icon;
$classes = 'inline-flex items-center justify-center rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1 active:scale-95 transition-all '
    . ($sizeClasses[$size] ?? $sizeClasses['sm']) . ' '
    . ($variantClasses[$variant] ?? $variantClasses['ghost']);
@endphp

<button
    type="{{ $type }}"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->merge(['class' => $classes]) }}
>
    <x-dynamic-component :component="$iconComponent" class="{{ $iconSizes[$size] ?? $iconSizes['sm'] }}" aria-hidden="true" />
</button>
