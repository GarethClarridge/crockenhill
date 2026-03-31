@props([
    'title',
    'description' => null,
])

<x-admin.page :title="$title" :description="$description" {{ $attributes }}>
    <x-slot:actions>
        @isset($actions){{ $actions }}@endisset
    </x-slot:actions>

    @isset($filters)
        <x-admin.filter-bar>
            {{ $filters }}
        </x-admin.filter-bar>
    @endisset

    <x-card wire:loading.class.delay.200ms="opacity-50">
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
