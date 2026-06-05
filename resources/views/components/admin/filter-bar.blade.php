@props([
    'resetAction' => 'resetFilters',
    'loadingTarget' => null,
])

<div class="space-y-2">
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

    @if($loadingTarget)
        <div wire:loading.flex wire:target="{{ $loadingTarget }}" class="items-center gap-2 text-sm text-gray-500" role="status">
            <svg class="h-4 w-4 animate-spin text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Updating results…</span>
        </div>
    @endif
</div>
