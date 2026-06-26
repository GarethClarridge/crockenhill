@props([
    'label' => 'Add',
    'icon' => 'plus',
])

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.prevent.stop="open = false; $refs.trigger.focus()"
    class="relative"
>
    <button
        type="button"
        x-ref="trigger"
        @click="open = !open"
        :aria-expanded="open"
        aria-haspopup="menu"
        class="no-underline rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 transition-all active:scale-95 inline-flex items-center justify-center gap-2 px-4 py-2 text-base bg-cbc-pattern bg-size-cover text-white hover:brightness-110"
        x-bind:class="{ 'brightness-125': open }"
    >
        @if($icon)
            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" aria-hidden="true" />
        @endif
        {{ $label }}
        <x-heroicon-o-chevron-down
            class="h-4 w-4 transition-transform duration-200"
            x-bind:class="{ 'rotate-180': open }"
            aria-hidden="true"
        />
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        role="menu"
        aria-label="{{ $label }}"
        class="absolute right-0 z-20 mt-2 w-64 rounded-md border border-gray-200 bg-white py-1 shadow-lg"
    >
        {{ $slot }}
    </div>
</div>
