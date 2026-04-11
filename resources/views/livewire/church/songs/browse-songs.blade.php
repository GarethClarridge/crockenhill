<div>
    <style>
        mark { background-color: theme('colors.yellow.200'); padding: 0 0.1em; border-radius: 0.15em; font-style: normal; }
    </style>
    {{-- Hero / filter bar --}}
    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-8 shadow-sm">
            <div class="space-y-4 text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">Congregational worship</p>
                <div class="space-y-3">
                    <p class="mx-auto max-w-2xl font-sans text-lg text-gray-700">
                        Browse the songs we sing together most often at Crockenhill Baptist Church.
                    </p>
                    <p class="mx-auto max-w-2xl text-sm text-gray-600">
                        This page is currently available to logged-in users while the public rollout is still in progress.
                    </p>
                </div>

                {{-- Search input --}}
                <div class="mx-auto max-w-md pt-1">
                    <x-input
                        wire:model.live.debounce.500ms="search"
                        placeholder="Search songs, authors, lyrics…"
                        aria-label="Search songs"
                        icon="magnifying-glass"
                        clearable
                        autofocus
                    />
                </div>

                {{-- Range filter buttons --}}
                <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
                    <button
                        wire:click="$set('range', '{{ \App\Services\PublicSongCatalogService::RANGE_ALL }}')"
                        type="button"
                        @class([
                            'inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2',
                            'bg-cbc-teal-dark text-white' => $selectedRange === \App\Services\PublicSongCatalogService::RANGE_ALL,
                            'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $selectedRange !== \App\Services\PublicSongCatalogService::RANGE_ALL,
                        ])
                        aria-pressed="{{ $selectedRange === \App\Services\PublicSongCatalogService::RANGE_ALL ? 'true' : 'false' }}"
                    >
                        All time
                    </button>
                    <button
                        wire:click="$set('range', '{{ \App\Services\PublicSongCatalogService::RANGE_THIS_YEAR }}')"
                        type="button"
                        @class([
                            'inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2',
                            'bg-cbc-teal-dark text-white' => $selectedRange === \App\Services\PublicSongCatalogService::RANGE_THIS_YEAR,
                            'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $selectedRange !== \App\Services\PublicSongCatalogService::RANGE_THIS_YEAR,
                        ])
                        aria-pressed="{{ $selectedRange === \App\Services\PublicSongCatalogService::RANGE_THIS_YEAR ? 'true' : 'false' }}"
                    >
                        This year
                    </button>
                </div>
            </div>
        </div>

        {{-- Empty state --}}
        @if ($songs->isEmpty())
            @if ($search !== '')
                <x-card heading="No songs match your search">
                    <p>Try different words, or clear the search to browse the full catalogue.</p>
                </x-card>
            @else
                <x-card heading="No songs sung this year yet">
                    <p>We do not have any worship song usage to show for this year yet. Switch to <strong>All time</strong> to browse the full catalogue.</p>
                </x-card>
            @endif
        @endif
    </section>

    {{-- Song grid --}}
    @if ($songs->isNotEmpty())
        <section class="px-6 pb-10 pt-8" wire:loading.class="opacity-60" wire:target="search, range">
            <div class="mx-auto grid max-w-2xl grid-cols-1 gap-6 sm:max-w-5xl sm:grid-cols-2 xl:max-w-7xl xl:grid-cols-3">
                @foreach ($songs as $song)
                    <article wire:key="song-{{ $song->id }}" class="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-cbc-teal-dark/75">Worship song</p>
                                <h2 class="font-display text-3xl leading-tight text-cbc-teal-dark">
                                    <a href="{{ route('church.songs.show', $song->slug) }}" wire:navigate class="rounded-sm transition-colors hover:text-cbc-teal focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2">
                                        {{ $song->title }}
                                    </a>
                                </h2>
                            </div>

                            <div class="rounded-2xl bg-cbc-teal-dark px-4 py-3 text-center text-white shadow-sm">
                                <p class="text-xs uppercase tracking-[0.2em] text-white/75">Uses</p>
                                <p class="font-display text-4xl leading-none">{{ (int) ($song->usage_count ?? 0) }}</p>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl bg-slate-100 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Authors</p>
                                <p class="mt-2 text-sm text-gray-700">
                                    {{ $song->authors->pluck('display_name')->implode(', ') ?: 'Unknown or not recorded' }}
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-100 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Last sung</p>
                                <p class="mt-2 text-sm text-gray-700">
                                    @if ($song->last_sung_date)
                                        {{ \Illuminate\Support\Carbon::parse($song->last_sung_date)->format('j F Y') }}
                                    @else
                                        Not yet sung
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($song->ccli_number)
                            <p class="mt-4 text-xs uppercase tracking-[0.2em] text-gray-500">
                                CCLI {{ $song->ccli_number }}
                            </p>
                        @endif

                        @if (!empty($snippetsBySongId[$song->id]))
                            <div class="mt-4 rounded-xl border border-cbc-teal/20 bg-cbc-teal/5 p-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark/75">Matching lyrics</p>
                                <ul class="space-y-1" aria-label="Matching lyric lines">
                                    @foreach ($snippetsBySongId[$song->id] as $snippet)
                                        <li class="text-sm text-gray-700">{!! $snippet !!}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mt-6">
                            <x-public-cta :link="route('church.songs.show', $song->slug)" label="View song" max-width="" />
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($songs->hasPages())
                <div class="mx-auto mt-8 max-w-2xl">
                    {{ $songs->links() }}
                </div>
            @endif
        </section>
    @endif
</div>
