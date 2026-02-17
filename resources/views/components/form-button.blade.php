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

<button {{ $attributes->merge(['class' => $classes, 'type' => $type]) }} wire:loading.attr="disabled">
    @if($icon)
      <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-4 h-4 {{ $slot->isNotEmpty() ? 'mr-2' : '' }}" wire:loading.remove />
    @endif
    <svg wire:loading class="animate-spin h-4 w-4 {{ $slot->isNotEmpty() ? 'mr-2' : '' }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
    {{ $slot }}
</button>
