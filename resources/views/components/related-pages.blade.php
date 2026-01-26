@props([
    'links' => [],
])

@if (count($links) > 0)
    <x-h2>
        Related pages
    </x-h2>
    <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mt-6">
        @foreach ($links as $link)
            <x-page-card :page="$link" />
        @endforeach
    </div>
@endif
