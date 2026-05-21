@props([
    'link',
    'variant' => 'primary',
    'size' => 'md',
    'icon' => null,
    'iconPosition' => 'leading',
    'iconStyle' => 'outline',
    'iconClass' => '',
    'inline' => false,
    'navigate' => true,
])

@php
$baseClasses = 'no-underline rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 transition-all active:scale-95 inline-flex items-center justify-center gap-2';

$sizeClasses = [
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-base',
    'card' => 'px-6 py-3.5 text-base',
    'lg' => 'p-4 text-base',
    'xl' => 'px-8 py-3.5 text-lg',
];

$variantClasses = [
    'primary'   => 'bg-cbc-pattern bg-cover text-white hover:brightness-110',
    'secondary' => 'border border-transparent bg-slate-100/90 text-[#145557] hover:bg-white shadow-none focus:ring-cbc-teal',
    'feature'   => 'bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)] text-white hover:brightness-110',
    'featureOutline' => 'border border-transparent bg-slate-100/90 text-[#145557] hover:bg-white shadow-none focus:ring-cbc-teal',
    'outline'   => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
    'danger'    => 'bg-cbc-crimson hover:bg-[#590d16] text-white',
    'ghost'     => 'hover:bg-gray-100 text-gray-600',
];

$iconComponent = $icon
    ? 'heroicon-'.($iconStyle === 'solid' ? 's' : 'o').'-'.$icon
    : null;

$iconSizeClasses = [
    'xs' => 'h-3.5 w-3.5',
    'sm' => 'h-4 w-4',
    'md' => 'h-4 w-4',
    'card' => 'h-6 w-6',
    'lg' => 'h-5 w-5',
    'xl' => 'h-5 w-5',
];

$wrapperClasses = $inline ? 'inline-block' : 'block';
$classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];
$resolvedIconClass = trim(($iconSizeClasses[$size] ?? 'h-4 w-4').' '.$iconClass);
@endphp

<div class="{{ $wrapperClasses }}">
  <a {{ $attributes->merge(['class' => $classes]) }} href="{{ $link }}" @if($navigate && !str_starts_with($link, '#')) wire:navigate @endif>
    @if($icon && $iconPosition !== 'trailing')
      <x-dynamic-component :component="$iconComponent" class="{{ $resolvedIconClass }}" />
    @endif
    {{ $slot }}
    @if($icon && $iconPosition === 'trailing')
      <x-dynamic-component :component="$iconComponent" class="{{ $resolvedIconClass }}" />
    @endif
  </a>
</div>
