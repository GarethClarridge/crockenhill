@props([
    'icon' => null,
    'title' => null,
    'description' => null,
    'colspan',
    'hasFilters' => false,
    'resetAction' => 'resetFilters',
])

@php
    $icon = $icon ?? ($hasFilters ? 'magnifying-glass' : 'inbox');
    $title = $title ?? ($hasFilters ? 'No results found' : 'No items found');
    $description = $description ?? ($hasFilters
        ? "Your search and filters didn't return any results. Try adjusting your search keywords or clearing the filters."
        : "There are currently no items to display. Get started by creating your first entry.");
@endphp

<tr>
    <td colspan="{{ $colspan }}" class="px-4 py-12 text-center">
        <div class="flex flex-col items-center justify-center space-y-3 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-8 transition-colors hover:border-gray-300">
            <div class="rounded-full bg-white shadow-sm ring-1 ring-gray-200 p-3">
                <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-8 w-8 text-cbc-teal/60" aria-hidden="true" />
            </div>
            <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
            <p class="text-sm text-gray-500 max-w-sm mx-auto">
                {{ $description }}
            </p>

            @if($slot->isNotEmpty())
                <div class="mt-4">
                    {{ $slot }}
                </div>
            @endif

            @if($hasFilters)
                <div class="mt-2">
                    <x-form-button variant="outline" size="sm" icon="x-mark" wire:click="{{ $resetAction }}">
                        Clear all filters
                    </x-form-button>
                </div>
            @endif
        </div>
    </td>
</tr>
