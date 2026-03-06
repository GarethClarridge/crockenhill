@props([
'page',
])

@if ($page)
@php
    $pageArea = $page->area instanceof \App\Enums\PageArea
        ? $page->area->value
        : (string) $page->area;

    $pageUrl = $pageArea === 'sermons'
        ? '/christ/sermons/'.$page->slug
        : '/'.$pageArea.'/'.$page->slug;
@endphp
<div class="group mb-4 flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-lg">
    <a class="relative block aspect-video overflow-hidden bg-slate-200" href="{{ $pageUrl }}" wire:navigate tabindex="-1" aria-hidden="true">
        <img class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115" src="{{ $page->heading_image_small_url ?? '/images/headings/small/default.webp' }}" alt="{{ $page->heading }}" onerror="this.onerror=null;this.src='/images/headings/small/default.webp';" loading="lazy" width="300" height="169">
        <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
        <h5 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-3xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)] sm:text-4xl">
            {{ $page->heading }}
        </h5>
    </a>

    <div class="flex flex-1 items-center justify-center px-6 py-5">
        <p class="mx-auto max-w-[30ch] text-center text-slate-700">
            {{ $page->description }}
        </p>
    </div>

    <a class="mt-auto flex w-full items-center justify-between gap-3 bg-[linear-gradient(120deg,theme(colors.cbc-teal.DEFAULT)_0%,theme(colors.cbc-teal.dark)_55%,#0e3a3c_100%)] px-6 py-3.5 text-left font-normal text-white no-underline transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2" href="{{ $pageUrl }}" wire:navigate aria-label="Learn about {{ $page->heading }}">
        <span>Learn about {{ $page->heading }}</span>
        <x-heroicon-s-arrow-right-circle class="h-6 w-6 shrink-0 text-white/90" aria-hidden="true" />
    </a>

    <x-edit-buttons slug="{{ $page->slug }}" />
</div>
@endif
