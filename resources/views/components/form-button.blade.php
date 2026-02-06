@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit', 'icon' => null])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors duration-200';

$sizeClasses = [
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-base',
    'lg' => 'px-6 py-3 text-lg',
    'xl' => 'px-8 py-4 text-xl'
];

$variantClasses = [
    'primary' => 'bg-green-500 hover:bg-green-600 focus:ring-green-500 text-white',
    'secondary' => 'bg-gray-500 hover:bg-gray-600 focus:ring-gray-500 text-white',
    'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 focus:ring-green-500 text-gray-700',
    'danger' => 'bg-red-600 hover:bg-red-700 focus:ring-red-500 text-white',
    'ghost' => 'hover:bg-gray-100 focus:ring-gray-500 text-gray-600',
];

$classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];
@endphp

<button {{ $attributes->merge(['class' => $classes, 'type' => $type]) }}>
    @if($icon)
      <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-4 h-4 {{ $slot->isNotEmpty() ? 'mr-2' : '' }}" />
    @endif
    {{ $slot }}
</button>
