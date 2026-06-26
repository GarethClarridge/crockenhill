<div
    x-on:seo-title-updated.window="document.title = $event.detail.title + ' | Crockenhill Baptist Church'"
    class="[&_mark]:bg-transparent [&_mark]:font-bold [&_mark]:not-italic [&_mark]:p-0"
>
    <a href="#song-results" @click.prevent="document.getElementById('song-results').focus()" class="sr-only focus:not-sr-only focus:absolute focus:z-30 focus:m-4 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-cbc-teal-dark focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-cbc-teal">
        Skip to results
    </a>

    {{-- Search and filter bar --}}
    <section class="space-y-4 pb-8">
        <div class="mx-auto max-w-2xl">
            <x-input
                wire:model.live.debounce.500ms="search"
                placeholder="Search songs, authors, lyrics…"
                aria-label="Search songs"
                icon="magnifying-glass"
                clearable
                autofocus
                shortcut="slash"
                class="text-lg py-4"
            />
        </div>

        {{-- Range filter buttons --}}
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button
                wire:click="$set('range', '{{ \App\Services\Public\PublicSongCatalogService::RANGE_ALL }}')"
                wire:loading.attr="disabled"
                aria-disabled="false"
                wire:loading.attr="aria-disabled"
                wire:loading.class="opacity-50"
                wire:target="$set('range', '{{ \App\Services\Public\PublicSongCatalogService::RANGE_ALL }}')"
                type="button"
                @class([
                    'inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2',
                    'bg-cbc-teal-dark text-white' => $selectedRange === \App\Services\Public\PublicSongCatalogService::RANGE_ALL,
                    'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $selectedRange !== \App\Services\Public\PublicSongCatalogService::RANGE_ALL,
                ])
                aria-pressed="{{ $selectedRange === \App\Services\Public\PublicSongCatalogService::RANGE_ALL ? 'true' : 'false' }}"
            >
                All time
            </button>
            <button
                wire:click="$set('range', '{{ \App\Services\Public\PublicSongCatalogService::RANGE_RECENT }}')"
                wire:loading.attr="disabled"
                aria-disabled="false"
                wire:loading.attr="aria-disabled"
                wire:loading.class="opacity-50"
                wire:target="$set('range', '{{ \App\Services\Public\PublicSongCatalogService::RANGE_RECENT }}')"
                type="button"
                @class([
                    'inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2',
                    'bg-cbc-teal-dark text-white' => $selectedRange === \App\Services\Public\PublicSongCatalogService::RANGE_RECENT,
                    'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $selectedRange !== \App\Services\Public\PublicSongCatalogService::RANGE_RECENT,
                ])
                aria-pressed="{{ $selectedRange === \App\Services\Public\PublicSongCatalogService::RANGE_RECENT ? 'true' : 'false' }}"
            >
                Last 3 years
            </button>
        </div>
    </section>

    {{-- Results area --}}
    <div id="song-results" tabindex="-1" class="focus:outline-none" wire:loading.class="opacity-60 pointer-events-none" wire:target="search, range" aria-busy="false" wire:loading.attr="aria-busy">

        {{-- Empty state --}}
        @if ($songs->isEmpty())
            <section class="pt-2">
                @if ($search !== '')
                    <x-empty-state
                        icon="magnifying-glass"
                        title="No songs match your search"
                        description="Try different words, or clear the search to browse the full catalogue."
                    >
                        <x-form-button type="button" variant="outline" size="sm" icon="x-mark" wire:click="$set('search', '')">
                            Clear search
                        </x-form-button>
                    </x-empty-state>
                @else
                    <x-empty-state
                        icon="clock"
                        title="No songs sung in the last 3 years"
                        description="We do not have any worship song usage to show for the last 3 years. Switch to All time to browse the full catalogue."
                    >
                        <x-form-button type="button" variant="outline" size="sm" icon="clock" wire:click="$set('range', '{{ \App\Services\Public\PublicSongCatalogService::RANGE_ALL }}')">
                            Show all time
                        </x-form-button>
                    </x-empty-state>
                @endif
            </section>
        @endif

        {{-- Song list --}}
        @if ($songs->isNotEmpty())
            <section class="pb-10">
                <div class="space-y-6">
                @foreach ($songs as $song)
                    @php
                        $songUrl = route('church.songs.show', $song->slug);
                        $authorNames = $song->authors->pluck('display_name')->implode(', ') ?: null;
                        $bookEntries = $song->books->map(fn ($book) => $book->name . ($book->pivot->entry ? ' #' . $book->pivot->entry : ''))->implode(', ') ?: null;
                    @endphp
                    <article wire:key="song-{{ $song->id }}" class="group relative flex h-full flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">

                        <div class="flex flex-col flex-1 p-6 @if (!empty($snippetsBySongId[$song->id])) pb-0 @endif">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex flex-col gap-2">
                                    <a class="relative z-10 group rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2" href="{{ $songUrl }}" wire:navigate aria-label="{{ $song->title }}">
                                        <h2 class="font-display text-3xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
                                            {{ $song->title }}
                                        </h2>
                                    </a>
                                    <div class="relative z-10">
                                        <x-clipboard-button :url="$songUrl" hideLabel label="Copy link to {{ $song->title }}" title="Copy link to clipboard" />
                                    </div>
                                </div>

                                <div class="shrink-0 rounded-2xl bg-cbc-teal-dark px-4 py-3 text-center text-white shadow-sm">
                                    <p class="text-xs uppercase tracking-[0.2em] text-white/75">Uses</p>
                                    <p class="font-display text-4xl leading-none">{{ (int) ($song->usage_count ?? 0) }}</p>
                                </div>
                            </div>

                            <ul class="mt-4 space-y-2 prose">
                                @if ($bookEntries)
                                <li class="flex items-center">
                                    <x-heroicon-o-book-open class="h-5 w-5 mr-2 text-gray-500 shrink-0" aria-hidden="true" />
                                    <span>{{ $bookEntries }}</span>
                                </li>
                                @endif

                                @if ($authorNames)
                                <li class="flex items-center">
                                    <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500 shrink-0" aria-hidden="true" />
                                    <span>{{ $authorNames }}</span>
                                </li>
                                @endif

                                <li class="flex items-center">
                                    <x-heroicon-s-calendar class="h-5 w-5 mr-2 text-gray-500 shrink-0" aria-hidden="true" />
                                    @if ($song->last_sung_date)
                                        <time datetime="{{ \Illuminate\Support\Carbon::parse($song->last_sung_date)->toDateString() }}">
                                            Last sung {{ \Illuminate\Support\Carbon::parse($song->last_sung_date)->format('j F Y') }}
                                        </time>
                                    @else
                                        <span class="text-gray-500">Not yet sung</span>
                                    @endif
                                </li>

                                @if ($song->ccli_number)
                                <li class="flex items-center">
                                    <x-heroicon-o-hashtag class="h-5 w-5 mr-2 text-gray-500 shrink-0" aria-hidden="true" />
                                    <span>CCLI {{ $song->ccli_number }}</span>
                                </li>
                                @endif

                            </ul>

                            @if (!empty($snippetsBySongId[$song->id]))
                                <div class="-mx-6 -mb-px mt-3 border-t border-gray-100 bg-gray-50 px-10 py-6 flex items-center">
                                    <x-heroicon-s-chat-bubble-left class="h-5 w-5 mr-3 text-gray-400 shrink-0" aria-hidden="true" />
                                    <div class="not-prose" aria-label="Matching lyric lines">
                                        @foreach ($snippetsBySongId[$song->id] as $snippet)
                                            <p class="italic text-gray-600 leading-snug">{!! $snippet !!}</p>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <x-button
                            :link="$songUrl"
                            variant="feature"
                            size="card"
                            icon="arrow-right-circle"
                            iconStyle="solid"
                            iconPosition="trailing"
                            iconClass="shrink-0 text-white/90"
                            class="w-full justify-between rounded-none text-left font-normal after:absolute after:inset-0"
                            aria-label="View song: {{ $song->title }}"
                        >
                            View Song
                        </x-button>
                    </article>
                @endforeach
                </div>

                @if ($songs->hasPages())
                    <nav class="mx-auto mt-8 max-w-2xl" aria-label="Pagination">
                        {{ $songs->links(data: ['scrollTo' => '#song-results']) }}
                    </nav>
                @endif
            </section>
        @endif

    </div>{{-- end wire:loading wrapper --}}

    <script type="application/ld+json">
    {!! json_encode($this->jsonLdData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
    </script>
</div>
