@props([
    'type' => 'info',
    'title' => null,
    'dismissible' => false,
])

@php
$variants = [
    'success' => [
        'wrapper' => 'bg-green-50 border-green-200 text-green-800',
        'icon'    => 'heroicon-o-check-circle',
        'iconClass' => 'text-green-500',
    ],
    'error' => [
        'wrapper' => 'bg-red-50 border-red-200 text-red-800',
        'icon'    => 'heroicon-o-x-circle',
        'iconClass' => 'text-red-500',
    ],
    'warning' => [
        'wrapper' => 'bg-yellow-50 border-yellow-200 text-yellow-800',
        'icon'    => 'heroicon-o-exclamation-triangle',
        'iconClass' => 'text-yellow-500',
    ],
    'info' => [
        'wrapper' => 'bg-blue-50 border-blue-200 text-blue-800',
        'icon'    => 'heroicon-o-information-circle',
        'iconClass' => 'text-blue-500',
    ],
];

$v = $variants[$type] ?? $variants['info'];
@endphp

<div
    role="{{ $type === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->merge(['class' => 'rounded-md border p-4 ' . $v['wrapper']]) }}
    @if($dismissible) x-data="{ visible: true }" x-show="visible" x-transition @endif
>
    <div class="flex gap-3">
        <x-dynamic-component :component="$v['icon']" class="h-5 w-5 shrink-0 {{ $v['iconClass'] }}" aria-hidden="true" />
        <div class="flex-1 text-sm">
            @if($title)
                <p class="font-semibold">{{ $title }}</p>
            @endif
            {{ $slot }}
        </div>
        @if($dismissible)
            <button
                type="button"
                @click="visible = false"
                class="shrink-0 rounded transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1"
                aria-label="Dismiss"
            >
                <x-heroicon-o-x-mark class="h-4 w-4 opacity-60 hover:opacity-100" aria-hidden="true" />
            </button>
        @endif
    </div>
</div>
