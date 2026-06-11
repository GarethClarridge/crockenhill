<x-card heading="Thumbnail options">
    <div class="space-y-4">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="text-sm text-gray-700">
                Choose the saved sermon thumbnail pair used for the main sermon image and the card image.
                The branded main thumbnail and card thumbnail are generated for the selected option. Changes here save immediately.
            </p>
        </div>

        @if($thumbnailCandidates !== [])
            <div class="space-y-4">
                @foreach($thumbnailCandidates as $candidate)
                    <div
                        wire:key="thumbnail-candidate-{{ $candidate['id'] }}"
                        wire:loading.class="opacity-50"
                        wire:target="selectThumbnailCandidate('{{ $candidate['id'] }}')"
                        @unless($candidate['is_selected'])
                            wire:click="selectThumbnailCandidate('{{ $candidate['id'] }}')"
                        @endunless
                        class="group rounded-lg border p-3 transition-all duration-200 {{ $candidate['is_selected'] ? 'border-cbc-teal bg-cbc-teal/5 ring-1 ring-cbc-teal/20' : 'border-gray-200 bg-white cursor-pointer hover:border-cbc-teal/50 hover:shadow-md active:scale-[0.98]' }}"
                    >
                        <div class="space-y-3">
                            @if($candidate['preview_url'])
                                <div class="relative">
                                    <img
                                        src="{{ $candidate['preview_url'] }}"
                                        alt="Thumbnail candidate {{ $loop->iteration }}"
                                        class="h-32 w-full rounded-lg border border-gray-200 object-cover transition-opacity duration-200 {{ $candidate['is_selected'] ? '' : 'group-hover:opacity-90' }}"
                                        loading="lazy"
                                    >
                                    @if($candidate['is_selected'])
                                        <div class="absolute top-2 right-2 rounded-full bg-cbc-teal p-1 shadow-lg ring-2 ring-white">
                                            <x-heroicon-s-check class="h-3 w-3 text-white" aria-hidden="true" />
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="grid grid-cols-[1fr_auto] items-center gap-3">
                                <div class="space-y-1 text-xs text-gray-600">
                                    <p class="font-medium text-gray-800">Frame {{ $loop->iteration }}</p>
                                    <p>Timestamp: {{ $candidate['timestamp_label'] }}</p>
                                    <p>Score: {{ number_format($candidate['score'], 2) }}</p>
                                </div>

                                @if($candidate['card_url'])
                                    <img
                                        src="{{ $candidate['card_url'] }}"
                                        alt="Card thumbnail candidate {{ $loop->iteration }}"
                                        class="h-16 w-24 rounded-md border border-gray-200 object-cover"
                                        loading="lazy"
                                    >
                                @endif
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                @if($candidate['is_selected'])
                                    <span class="rounded-full bg-cbc-teal px-3 py-1 text-xs font-medium text-white">Selected</span>
                                @else
                                    <span class="text-xs text-gray-500">Also updates the card thumbnail and generates the layered version if needed</span>
                                @endif

                                @unless($candidate['is_selected'])
                                    <x-form-button
                                        variant="outline"
                                        size="sm"
                                        wire:click.stop="selectThumbnailCandidate('{{ $candidate['id'] }}')"
                                        wire:loading.attr="disabled"
                                        class="group-hover:bg-cbc-teal group-hover:text-white group-hover:border-cbc-teal transition-colors"
                                    >
                                        Use this
                                    </x-form-button>
                                @endunless
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif($sermon->hasThumbnail())
            <div class="space-y-3">
                <img
                    src="{{ route('sermons.thumbnail', $sermon->slug) }}"
                    alt="Current sermon thumbnail"
                    class="h-32 w-full rounded-lg border border-gray-200 object-cover"
                    loading="lazy"
                >
                <p class="text-sm text-gray-600">
                    This sermon has an existing thumbnail but no saved alternatives yet. Regenerate thumbnails to create five selectable options.
                </p>
            </div>
        @else
            <div class="flex flex-col items-center justify-center space-y-2 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-6 text-center">
                <x-heroicon-o-photo class="h-8 w-8 text-gray-300" aria-hidden="true" />
                <p class="text-sm font-medium text-gray-500">
                    No saved sermon thumbnails yet.
                </p>
            </div>
        @endif

        <div class="space-y-2">
            @if($sermon->hasVideo())
                <x-form-button
                    variant="outline"
                    wire:click="regenerateThumbnails"
                    wire:loading.attr="disabled"
                    wire:target="regenerateThumbnails"
                    icon="arrow-path"
                    class="w-full justify-center"
                >
                    Regenerate 5 options
                </x-form-button>
                <p wire:loading wire:target="regenerateThumbnails" class="text-sm text-gray-500">
                    Generating fresh thumbnails from the sermon video...
                </p>
            @else
                <p class="text-sm text-gray-500">
                    A video file is required before thumbnail options can be generated.
                </p>
            @endif
        </div>
    </div>
</x-card>
