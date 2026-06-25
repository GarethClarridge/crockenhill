@extends('layouts.main')

@section('meta_description'){{ $description }}@stop

@section('meta_tags')
<x-meta-tags
    :title="$heading . ' | Songs'"
    :description="$description"
/>
<x-schema.webpage
    :heading="$heading"
    :description="$description"
    :main-entity="route('church.songs.show', $song->slug) . '#song'"
/>
<x-schema.music-composition :$song />
@stop

@section('content')
<x-page.shell
    :heading="$heading"
    :title="$heading . ' | Songs'"
    :description="$description ?? null"
    :metaDescription="$metaDescription ?? $description"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
    :meta-tags="false"
>
    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-8 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div class="space-y-5">
                    <div class="space-y-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">Song</p>
                        <p class="max-w-2xl text-lg text-gray-700">
                            {{ $song->authors->pluck('display_name')->implode(', ') ?: 'Author details are not recorded for this song yet.' }}
                        </p>
                    </div>

                    <dl class="grid gap-4 text-sm text-gray-700 sm:grid-cols-2">
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-musical-note class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Times used</dt>
                                <dd>Used in {{ $usageCount }} {{ \Illuminate\Support\Str::plural('service', $usageCount) }}</dd>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <x-heroicon-s-calendar class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Last sung</dt>
                                <dd>
                                    @if (is_string($lastSungDate))
                                        Last sung {{ \Illuminate\Support\Carbon::parse($lastSungDate)->format('j F Y') }}
                                    @else
                                        No qualifying usage recorded yet
                                    @endif
                                </dd>
                            </div>
                        </div>

                        @if ($song->ccli_number)
                            <div class="flex items-center gap-3">
                                <x-heroicon-o-identification class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                                <div>
                                    <dt class="sr-only">CCLI</dt>
                                    <dd>CCLI {{ $song->ccli_number }}</dd>
                                </div>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="w-full max-w-sm">
                    <div class="w-full rounded-xl bg-gradient-teal p-[1.5px]">
                        <x-button
                            link="{{ route('church.songs.index') }}"
                            variant="secondary"
                            size="lg"
                            class="w-full rounded-[11px]"
                            aria-label="Back to song catalogue"
                        >
                            Back to Songs
                        </x-button>
                    </div>
                </div>
            </div>
        </div>

        @if ($videoUrl)
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center gap-2 border-b border-gray-100 px-6 py-4">
                    <x-heroicon-o-play-circle class="h-4 w-4 shrink-0 text-cbc-teal" aria-hidden="true" />
                    <h2 class="font-display text-xl text-gray-900">Watch</h2>
                </div>
                <div class="p-6">
                    <video src="{{ $videoUrl }}" class="w-full rounded-lg" controls>
                        Your browser does not support the video element.
                    </video>
                </div>
            </div>
        @endif

        <x-card heading="Lyrics">
            @if ($song->lyrics_plain)
                <pre class="not-prose whitespace-pre-wrap font-sans text-sm leading-7 text-gray-800">{{ $song->lyrics_plain }}</pre>
            @else
                <p>No lyrics are available for this song yet.</p>
            @endif
        </x-card>

        <x-card heading="Usage History">
            <div class="not-prose overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Service</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Title in Service</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse ($usageHistory as $usageItem)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    {{ $usageItem->churchService?->date?->format('j M Y') ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    {{ $usageItem->churchService?->service?->label() ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    {{ $usageItem->title }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                                    No qualifying usage history found for this song yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </section>
</x-page.shell>
@endsection
