@props([
    'link',
    'icon' => null,
])

<a
    href="{{ $link }}"
    wire:navigate
    role="menuitem"
    {{ $attributes->merge(['class' => 'flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none transition-transform active:scale-[0.98]']) }}
>
    @if($icon)
        <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
    @endif
    {{ $slot }}
</a>
