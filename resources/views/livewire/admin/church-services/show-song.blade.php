<div class="space-y-6">
    <div class="space-y-2">
        <a href="{{ route('admin.services.songs.index') }}" wire:navigate class="inline-flex items-center text-sm font-medium text-cbc-teal hover:text-cbc-teal-dark">
            Back to songs
        </a>
        <div>
            <h1 class="font-display text-3xl">{{ $song->title }}</h1>
            @if($song->alternate_title)
                <p class="text-sm text-gray-600">{{ $song->alternate_title }}</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card heading="Lyrics">
                @if($song->lyrics_plain)
                    <pre class="whitespace-pre-wrap text-sm text-gray-800">{{ $song->lyrics_plain }}</pre>
                @else
                    <p class="text-sm text-gray-500">No parsed plain-text lyrics available for this song.</p>
                @endif

                @if($parseWarnings !== [])
                    <div class="mt-4 space-y-2">
                        @foreach($parseWarnings as $warning)
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {{ $warning }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card heading="Song videos">
                @if($videos !== [])
                    <div class="space-y-6">
                        @foreach($videos as $video)
                            <div wire:key="song-video-{{ $video['id'] }}" class="rounded-lg border border-gray-200 overflow-hidden">
                                <video
                                    src="{{ $video['url'] }}"
                                    class="w-full rounded-t-lg bg-black"
                                    controls
                                    preload="none"
                                >
                                    Your browser does not support the video element.
                                </video>
                                <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
                                    <div class="flex items-center gap-3 text-sm">
                                        <span class="font-medium text-gray-900">{{ $video['recorded_date'] ?? '—' }}</span>
                                        @if($video['duration'] !== null)
                                            <span class="text-gray-500">{{ gmdate('i:s', (int) $video['duration']) }}</span>
                                        @endif
                                        @if($video['is_featured'])
                                            <span class="inline-flex items-center rounded-full bg-cbc-teal-light/15 px-2 py-0.5 text-xs font-medium text-cbc-teal-dark">
                                                Featured
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        @if($video['is_featured'])
                                            <x-form-button type="button" variant="outline" size="xs" wire:click="unfeatureVideo({{ $video['id'] }})">
                                                Unfeature
                                            </x-form-button>
                                        @else
                                            <x-form-button type="button" variant="outline" size="xs" wire:click="featureVideo({{ $video['id'] }})">
                                                Feature
                                            </x-form-button>
                                        @endif
                                        <x-form-button type="button" variant="danger" size="xs"
                                            wire:click="deleteVideo({{ $video['id'] }})"
                                            wire:confirm="Delete this video recording?"
                                            aria-label="Delete video recording from {{ $video['recorded_date'] ?? 'unknown date' }}"
                                        >
                                            Delete
                                        </x-form-button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500">No video recordings saved for this song yet.</p>
                @endif
            </x-card>

            <x-card heading="Recent usage">
                @if($usageByYear !== [])
                    <p class="text-sm text-gray-500">Usage by Year</p>
                    <div
                        x-data="{ bars: @json($usageByYear) }"
                        class="mt-6 w-full"
                        aria-label="Bar chart of song usage by year">
                        @php $maxCount = max(array_column($usageByYear, 'count')); @endphp
                        <div
                            class="overflow-x-auto"
                            x-init="$el.scrollLeft = $el.scrollWidth">
                            <div class="relative flex items-end gap-2" style="height: 120px; min-width: max-content;">
                                @foreach($usageByYear as $entry)
                                    @php $heightPx = $maxCount > 0 ? round(($entry['count'] / $maxCount) * 120) : 0; @endphp
                                    <div class="group relative flex flex-col items-center justify-end" style="height: 120px; width: 36px;">
                                        <span class="absolute -top-5 text-xs font-medium text-gray-600 opacity-0 transition-opacity group-hover:opacity-100">{{ $entry['count'] }}</span>
                                        <div
                                            class="w-full rounded-t bg-cbc-teal transition-colors group-hover:bg-cbc-teal-dark"
                                            style="height: {{ $heightPx }}px;"
                                            title="{{ $entry['year'] }}: {{ $entry['count'] }} uses"></div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-1 flex gap-2" style="min-width: max-content;">
                                @foreach($usageByYear as $entry)
                                    <div class="text-center text-xs text-gray-500" style="width: 36px;">{{ $entry['year'] }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-500">No usage history found for this song.</p>
                @endif
            </x-card>

        </div>

        <div class="space-y-6">
            <x-card heading="Authors">
                <div class="space-y-2 text-sm">
                    @forelse($song->authors as $author)
                        <div>
                            <p class="font-medium">{{ $author->display_name }}</p>
                            <p class="text-xs text-gray-500">{{ $author->pivot->author_type ?: 'Unknown role' }}</p>
                        </div>
                    @empty
                        <p class="text-gray-500">No authors linked.</p>
                    @endforelse
                </div>
            </x-card>

            <x-card heading="Songbooks">
                <div class="space-y-2 text-sm">
                    @forelse($song->books as $book)
                        <div>
                            <p class="font-medium">{{ $book->name }}</p>
                            <p class="text-xs text-gray-500">
                                Entry {{ $book->pivot->entry }}@if($book->publisher), {{ $book->publisher }}@endif
                            </p>
                        </div>
                    @empty
                        <p class="text-gray-500">No songbook entries linked.</p>
                    @endforelse
                </div>
            </x-card>

            <x-card heading="Song Metadata">
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-gray-500">Alternate title</p>
                        <p class="font-medium">{{ $song->alternate_title ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">CCLI</p>
                        <p class="font-medium">{{ $song->ccli_number ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Verse order</p>
                        <p class="font-medium">{{ $song->verse_order ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Usage count</p>
                        <p class="font-medium">{{ $usageCount }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Last used</p>
                        <p class="font-medium">
                            @if(is_string($lastUsedDate))
                                {{ \Illuminate\Support\Carbon::parse($lastUsedDate)->format('j M Y') }}
                            @else
                                -
                            @endif
                        </p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
