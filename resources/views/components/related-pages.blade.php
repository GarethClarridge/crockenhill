@props([
    'links' => [],
])

@if (count($links) > 0)
    <x-h2>
        Related pages
    </x-h2>
    <div class="mx-auto mt-6 grid max-w-6xl items-start justify-center gap-6 px-6 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))]">
        @foreach ($links as $link)
            <x-page-card :page="$link" />
        @endforeach
    </div>
@endif
