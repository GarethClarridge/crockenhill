@props([
    'resetAction' => 'resetFilters',
])

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-4']) }}>
    {{ $slot }}

    <div x-show="$wire.hasFilters" x-transition x-cloak class="ml-auto">
        <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="{{ $resetAction }}">
            Clear Filters
        </x-form-button>
    </div>
</div>
