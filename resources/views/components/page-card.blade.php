@props([
'page',
])

@if ($page)
@php
    /** @var array{area?: mixed, description?: mixed, heading?: mixed, image_url?: mixed, slug?: mixed, url?: mixed}|\App\Models\Page $page */
    $pageArea = data_get($page, 'area');
    if ($pageArea instanceof \App\Enums\PageArea) {
        $pageArea = $pageArea->value;
    }
    $pageHeading = data_get($page, 'heading');
    $pageDescription = data_get($page, 'description');
    $pageImageUrl = data_get($page, 'image_url', '/images/headings/small/default.webp');
    $pageSlug = data_get($page, 'slug');
    $pageUrl = data_get($page, 'url');

    if (! is_string($pageUrl) || $pageUrl === '') {
        $pageUrl = match (true) {
            $pageArea === 'sermons' && $pageSlug === 'all' => route('sermons.index'),
            $pageArea === 'sermons' => '/christ/sermons/'.$pageSlug,
            default => '/'.$pageArea.'/'.$pageSlug,
        };
    }
@endphp
<div class="group relative mb-4 flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
    <a class="relative z-10 block aspect-video overflow-hidden bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 after:absolute after:inset-0" href="{{ $pageUrl }}" wire:navigate>
        <img class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115" src="{{ $pageImageUrl }}" alt="" role="presentation" onerror="this.onerror=null;this.src='/images/headings/small/default.webp';" loading="lazy" width="300" height="169">
        <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
        <h2 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-3xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)] sm:text-4xl">
            {{ $pageHeading }}
        </h2>
    </a>

    <div class="flex flex-1 items-center justify-center px-6 py-5">
        <p class="mx-auto max-w-[30ch] text-center text-slate-700">
            {{ $pageDescription }}
        </p>
    </div>

    <div class="mt-auto">
        <x-button
            :link="$pageUrl"
            variant="feature"
            size="card"
            icon="arrow-right-circle"
            iconStyle="solid"
            iconPosition="trailing"
            iconClass="shrink-0 text-white/90"
            class="w-full justify-between rounded-none text-left font-normal"
            tabindex="-1"
            aria-hidden="true"
        >
            Learn about {{ $pageHeading }}
        </x-button>
    </div>

    <div class="relative z-10">
        <x-page-card-admin-overlay slug="{{ $pageSlug }}" />
    </div>
</div>
@endif
