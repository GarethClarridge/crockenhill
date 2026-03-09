@extends('layouts.page')

@section('dynamic_content')
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
                <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
                    <x-button
                        link="{{ route('church.songs.index') }}"
                        variant="{{ $selectedRange === \App\Services\PublicSongUsageService::RANGE_ALL ? 'secondary' : 'outline' }}"
                        size="sm"
                        inline>
                        All time
                    </x-button>
                    <x-button
                        link="{{ route('church.songs.index', ['range' => \App\Services\PublicSongUsageService::RANGE_THIS_YEAR]) }}"
                        variant="{{ $selectedRange === \App\Services\PublicSongUsageService::RANGE_THIS_YEAR ? 'secondary' : 'outline' }}"
                        size="sm"
                        inline>
                        This year
                    </x-button>
                </div>
            </div>
        </div>

        @if ($songs->isEmpty())
            <x-card heading="No qualifying songs yet">
                <p>
                    We do not have any worship song usage to show for this time period yet.
                </p>
            </x-card>
        @endif
    </section>
@endsection

@section('full_width_content')
    @if ($songs->isNotEmpty())
        <section class="px-6 pb-10 pt-2">
            <div class="mx-auto grid max-w-2xl grid-cols-1 gap-6 sm:max-w-5xl sm:grid-cols-2 xl:max-w-7xl xl:grid-cols-3">
                @foreach ($songs as $song)
                    <article class="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-cbc-teal-dark/75">Worship song</p>
                                <h2 class="font-display text-3xl leading-tight text-cbc-teal-dark">
                                    <a href="{{ route('church.songs.show', $song->slug) }}" wire:navigate class="transition-colors hover:text-cbc-teal focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 rounded-sm">
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
                                        Not available
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($song->ccli_number)
                            <p class="mt-4 text-xs uppercase tracking-[0.2em] text-gray-500">
                                CCLI {{ $song->ccli_number }}
                            </p>
                        @endif

                        <div class="mt-6">
                            <div class="w-full rounded-xl bg-[linear-gradient(120deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)] p-[1.5px]">
                                <x-button link="{{ route('church.songs.show', $song->slug) }}" variant="secondary" class="w-full rounded-[11px]">
                                    View song
                                </x-button>
                            </div>
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
@endsection
