@props(['type' => 'success'])

@php
$types = [
    'success' => [
        'classes' => 'bg-green-50 text-green-800 border-green-200',
        'icon' => 'heroicon-o-check-circle',
        'iconClasses' => 'text-green-500',
        'role' => 'status',
    ],
    'error' => [
        'classes' => 'bg-red-50 text-red-800 border-red-200',
        'icon' => 'heroicon-o-x-circle',
        'iconClasses' => 'text-red-500',
        'role' => 'alert',
    ],
    'warning' => [
        'classes' => 'bg-amber-50 text-amber-800 border-amber-200',
        'icon' => 'heroicon-o-exclamation-triangle',
        'iconClasses' => 'text-amber-500',
        'role' => 'alert',
    ],
    'info' => [
        'classes' => 'bg-blue-50 text-blue-800 border-blue-200',
        'icon' => 'heroicon-o-information-circle',
        'iconClasses' => 'text-blue-500',
        'role' => 'status',
    ],
];

$config = $types[$type] ?? $types['success'];
@endphp

<div x-data="{ show: true }"
     x-show="show"
     x-cloak
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="relative p-4 my-4 border rounded-lg shadow-sm {{ $config['classes'] }} flex items-start gap-3"
     role="{{ $config['role'] }}">

    <x-dynamic-component :component="$config['icon']" class="h-5 w-5 shrink-0 {{ $config['iconClasses'] }}" aria-hidden="true" />

    <div class="flex-1 pr-8">
        {{ $slot }}
    </div>

    <button type="button"
            @click="show = false"
            class="absolute top-2 right-2 p-1.5 text-current opacity-50 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded-md transition-all active:scale-95"
            aria-label="Close notification">
        <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
    </button>
</div>
