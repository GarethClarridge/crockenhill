@props(['link', 'variant' => 'default', 'size' => 'md', 'icon' => null, 'inline' => false])

@php
$baseClasses = 'no-underline rounded-md focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-all inline-flex items-center justify-center';

$sizeClasses = [
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-base',
    'lg' => 'p-4 text-base',
];

$variantClasses = [
    'default' => 'bg-cbc-pattern bg-cover text-white',
    'primary' => 'bg-green-500 hover:bg-green-600 text-white',
    'secondary' => 'bg-gray-500 hover:bg-gray-600 text-white',
    'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
    'danger' => 'bg-red-600 hover:bg-red-700 text-white',
    'ghost' => 'hover:bg-gray-100 text-gray-600',
];

$wrapperClasses = $inline ? 'inline-block' : 'block';
$classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];
@endphp

<div class="{{ $wrapperClasses }}">
  <a {{ $attributes->merge(['class' => $classes]) }} href="{{ $link }}" @if(!str_starts_with($link, '#')) wire:navigate @endif>
    @if($icon)
      <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-4 h-4 {{ $slot->isNotEmpty() ? 'mr-2' : '' }}" />
    @endif
    {{ $slot }}
  </a>
</div>
