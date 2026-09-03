@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description"
    :meta-description="$description"
    :area="$area"
    :slug="$slug"
    :links="$links"
    :canonical="$canonical_url"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            type="article"
            :canonical="$canonical_url"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :canonical="$canonical_url"
            :date-published="$churchService->date"
            :date-modified="$churchService->updated_at"
        />
    @endpush

    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-6 shadow-sm sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div class="space-y-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">Service history</p>
                    <dl class="grid gap-4 text-gray-700 sm:grid-cols-2">
                        <div class="flex items-center gap-3">
                            <x-heroicon-s-calendar class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Date</dt>
                                <dd>{{ $churchService->date->format('j F Y') }}</dd>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-clock class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Service</dt>
                                <dd>{{ $churchService->service->label() }} service</dd>
                            </div>
                        </div>

                        {{-- Only a confirmed occasion is shown: an unconfirmed one is still a model proposal. --}}
                        @if ($churchService->confirmedOccasion())
                            <div class="flex items-center gap-3">
                                <x-heroicon-o-tag class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                                <div>
                                    <dt class="sr-only">Occasion</dt>
                                    <dd>{{ $churchService->confirmedOccasion()->label() }}</dd>
                                </div>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="w-full max-w-sm">
                    <div class="w-full rounded-xl bg-gradient-teal p-[1.5px]">
                        <x-button
                            :link="route('church.services.index')"
                            variant="secondary"
                            size="lg"
                            class="w-full rounded-[11px]"
                        >
                            Back to services
                        </x-button>
                    </div>
                </div>
            </div>
        </div>

        @if ($publicItems->isNotEmpty())
            <x-card heading="Public service order">
                <ol class="not-prose space-y-4" aria-label="Public service order">
                    @foreach ($publicItems as $item)
                        <li class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5">
                            <div class="flex items-start gap-4">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cbc-teal/10 font-display text-lg text-cbc-teal-dark" aria-hidden="true">
                                    {{ $loop->iteration }}
                                </span>
                                <div class="min-w-0 flex-1 space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($item['kind'] === 'sermon' || $item['kind'] === 'childrens_talk')
                                            @php($sermonView = $item['sermon_view'])
                                            <a
                                                href="{{ $sermonView['canonical_url'] }}"
                                                wire:navigate
                                                class="rounded-md font-display text-2xl text-gray-900 underline decoration-cbc-teal-light underline-offset-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                                            >
                                                {{ $item['title'] }}
                                            </a>
                                        @elseif ($item['kind'] === 'song' && $item['song_url'])
                                            <a
                                                href="{{ $item['song_url'] }}"
                                                wire:navigate
                                                class="rounded-md font-display text-2xl text-gray-900 underline decoration-cbc-teal-light underline-offset-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                                            >
                                                {{ $item['title'] }}
                                            </a>
                                        @else
                                            <h2 class="font-display text-2xl text-gray-900">{{ $item['title'] }}</h2>
                                        @endif

                                        @if ($item['planned_only'])
                                            <span class="rounded-full bg-cbc-teal/10 px-3 py-1 text-xs font-semibold text-cbc-teal-dark">From the service plan</span>
                                        @endif
                                    </div>

                                    @if ($item['kind'] === 'sermon' || $item['kind'] === 'childrens_talk')
                                        <div class="flex flex-wrap gap-2 text-sm text-gray-600">
                                            @if ($item['kind'] === 'childrens_talk')
                                                <span>Children's talk</span>
                                            @else
                                                <span>Sermon</span>
                                            @endif
                                            @if (filled($sermonView['display_reference']))
                                                <span aria-hidden="true">·</span>
                                                <span>{{ $sermonView['display_reference'] }}</span>
                                            @endif
                                        </div>

                                        @if ($sermonView['audio_url'] || $sermonView['video_url'])
                                            <div class="flex flex-wrap gap-2 text-sm font-semibold text-cbc-teal-dark">
                                                @if ($sermonView['audio_url'])
                                                    <span>Audio available</span>
                                                @endif
                                                @if ($sermonView['video_url'])
                                                    <span>Video available</span>
                                                @endif
                                            </div>
                                        @else
                                            <p class="text-sm text-gray-600">Published media is not available yet.</p>
                                        @endif
                                    @elseif ($item['kind'] === 'song')
                                        <p class="text-sm text-gray-600">
                                            {{ $item['planned_only'] ? 'Listed in the service plan.' : 'Song in the service.' }}
                                        </p>
                                        @if ($item['song_video_url'])
                                            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white p-3">
                                                <p class="mb-3 text-sm font-semibold text-cbc-teal-dark">Performance video</p>
                                                <video src="{{ $item['song_video_url'] }}" class="w-full rounded-md bg-slate-950" controls>
                                                    Your browser does not support the video element.
                                                </video>
                                            </div>
                                        @endif
                                    @elseif ($item['kind'] === 'scripture')
                                        <p class="text-sm text-gray-600">Bible reading</p>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-card>
        @else
            <x-card heading="History not available">
                <p>This service is recorded, but no publication-safe items are available yet.</p>
            </x-card>
        @endif

        <x-card heading="About this page">
            <p class="text-sm text-gray-600">
                This page lists the songs, readings and teaching we can publish for this service.
                It is not a complete order of service — items such as notices, prayers and
                announcements are kept off the public record.
            </p>
        </x-card>
    </section>
</x-page.shell>
@endsection
