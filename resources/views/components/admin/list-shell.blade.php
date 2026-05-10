@props([
    'title',
    'description' => null,
    'items' => null,
])

<x-admin.page :title="$title" :description="$description" {{ $attributes }}>
    <x-slot:actions>
        @isset($actions){{ $actions }}@endisset
    </x-slot:actions>

    @isset($filters)
        <a href="#admin-results" @click.prevent="document.getElementById('admin-results').focus()" class="sr-only focus:not-sr-only focus:absolute focus:z-30 focus:m-4 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-cbc-teal-dark focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-cbc-teal">
            Skip to results
        </a>

        <div class="mb-4">
            {{ $filters }}
        </div>
    @endisset

    @if($items instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="mb-4 text-sm text-gray-500" aria-live="polite">
            @if($items->count() > 0)
                @if($items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    @if($items->hasPages())
                        Showing <span class="font-medium text-gray-700">{{ $items->firstItem() }}</span> to <span class="font-medium text-gray-700">{{ $items->lastItem() }}</span> of <span class="font-medium text-gray-700">{{ $items->total() }}</span> results
                    @else
                        Showing <span class="font-medium text-gray-700">{{ $items->total() }}</span> {{ Str::plural('result', $items->total()) }}
                    @endif
                @else
                    {{-- Simple pagination --}}
                    Showing <span class="font-medium text-gray-700">{{ $items->firstItem() }}</span> to <span class="font-medium text-gray-700">{{ $items->lastItem() }}</span> results
                @endif
            @endif
        </div>
    @endif

    <x-card id="admin-results" tabindex="-1" class="focus:outline-none" wire:loading.class.delay.200ms="opacity-50">
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
