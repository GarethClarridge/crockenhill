@props([
    'title',
    'description' => null,
])

<x-admin.page :title="$title" :description="$description" {{ $attributes }}>
    <x-slot:actions>
        @isset($actions){{ $actions }}@endisset
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{ $slot }}
        </div>

        @isset($sidebar)
            <div class="space-y-6">
                {{ $sidebar }}
            </div>
        @endisset
    </div>
</x-admin.page>
