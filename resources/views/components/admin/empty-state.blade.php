@props([
    'icon' => 'magnifying-glass',
    'title' => 'No results found',
    'description' => "Your search and filters didn't return any results. Try adjusting your search keywords or clearing the filters.",
    'colspan',
    'hasFilters' => false,
])

<tr>
    <td colspan="{{ $colspan }}" class="px-4 py-12 text-center">
        <div class="flex flex-col items-center justify-center space-y-3">
            <div class="rounded-full bg-gray-100 p-3">
                <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-8 w-8 text-gray-400" aria-hidden="true" />
            </div>
            <h3 class="text-sm font-medium text-gray-900">{{ $title }}</h3>
            <p class="text-sm text-gray-500 max-w-xs mx-auto">
                {{ $description }}
            </p>
            @if($hasFilters)
                <div class="mt-2">
                    <x-form-button variant="outline" size="sm" icon="x-mark" wire:click="resetFilters">
                        Clear all filters
                    </x-form-button>
                </div>
            @endif
        </div>
    </td>
</tr>
