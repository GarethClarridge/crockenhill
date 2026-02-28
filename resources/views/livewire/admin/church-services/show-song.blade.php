<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl">{{ $song->title }}</h1>
            <p class="text-gray-600">{{ $song->canonical_key }}</p>
        </div>

        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" inline>
                Back to Songs
            </x-button>
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Services
            </x-button>
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

            <x-card heading="Recent Usage">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Service</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Item Title</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse($usageHistory as $usageItem)
                                <tr>
                                    <td class="px-4 py-3 text-sm">
                                        {{ $usageItem->churchService?->date?->format('j M Y') ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        {{ $usageItem->churchService?->service?->label() ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        {{ $usageItem->title }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($usageItem->churchService)
                                            <x-button
                                                link="{{ route('admin.services.show', $usageItem->churchService) }}"
                                                variant="ghost"
                                                size="xs"
                                                icon="eye"
                                                inline
                                                aria-label="View service for song usage" />
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                        No usage history found for this song.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card heading="Song Metadata">
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-gray-500">Title</p>
                        <p class="font-medium">{{ $song->title }}</p>
                    </div>
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
                        <p class="text-gray-500">Distinct services</p>
                        <p class="font-medium">{{ $serviceCount }}</p>
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
        </div>
    </div>
</div>
