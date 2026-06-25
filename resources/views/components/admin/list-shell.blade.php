@props([
    'title',
    'description' => null,
    'paginator' => null,
    'itemsName' => 'item',
])

<x-admin.page :title="$title" :description="$description" {{ $attributes->except('paginator') }}>
    <x-slot:actions>
        @isset($actions){{ $actions }}@endisset
    </x-slot:actions>

    @if($paginator && method_exists($paginator, 'total') && $paginator->total() > 0)
        <a href="#admin-list-results" @click.prevent="document.getElementById('admin-list-results').focus()" class="sr-only focus:not-sr-only focus:absolute focus:z-30 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-cbc-teal-dark focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-cbc-teal">
            Skip to results
        </a>
    @endif

    @isset($filters)
        <div class="mb-4">
            {{ $filters }}
        </div>
    @endisset

    @if($paginator && method_exists($paginator, 'total') && $paginator->total() > 0)
        <div class="mb-4 text-sm text-gray-500" aria-live="polite">
            @if (method_exists($paginator, 'hasPages') && $paginator->hasPages())
                Showing <span class="font-medium text-gray-700">{{ $paginator->firstItem() }}</span> to <span class="font-medium text-gray-700">{{ $paginator->lastItem() }}</span> of <span class="font-medium text-gray-700">{{ $paginator->total() }}</span> {{ Str::plural($itemsName, $paginator->total()) }}
            @else
                Showing <span class="font-medium text-gray-700">{{ $paginator->total() }}</span> {{ Str::plural($itemsName, $paginator->total()) }}
            @endif
        </div>
    @endif

    <x-card id="admin-list-results" tabindex="-1" wire:loading.class.delay.200ms="opacity-50" class="focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2">
        <div class="overflow-x-auto">
            {{ $slot }}
        </div>

        @isset($pagination)
            <div class="mt-4">
                {{ $pagination }}
            </div>
        @endisset
    </x-card>
</x-admin.page>
