@props([
    'resetAction' => 'resetFilters',
])

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-4']) }}>
    {{ $slot }}

    @isset($actions)
        <div class="flex items-center gap-4 ml-auto">
            <div x-show="$wire.hasFilters" x-transition x-cloak>
                <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="{{ $resetAction }}">
                    Clear Filters
                </x-form-button>
            </div>
            {{ $actions }}
        </div>
    @else
        <div class="ml-auto" x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="{{ $resetAction }}">
                Clear Filters
            </x-form-button>
        </div>
    @endisset
</div>
