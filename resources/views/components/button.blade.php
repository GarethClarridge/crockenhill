@props(['link', 'variant' => 'default'])

@php
$baseClasses = 'no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all';
$variantClasses = [
    'default' => 'bg-cbc-pattern bg-cover',
    'primary' => 'bg-green-500 hover:bg-green-600',
    'secondary' => 'bg-gray-500 hover:bg-gray-600',
    'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
    'danger' => 'bg-red-600 hover:bg-red-700'
];
$classes = $baseClasses . ' ' . $variantClasses[$variant];
@endphp

<div>
  <a class="{{ $classes }}" href="{{ $link }}" wire:navigate>
    {{ $slot }}
  </a>
</div>