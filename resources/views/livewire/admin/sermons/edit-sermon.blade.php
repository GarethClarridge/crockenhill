<div
    x-data="{
        title: $wire.entangle('title').live,
        slug: $wire.entangle('slug').live,
        lastGeneratedSlug: '',
        slugify(value) {
            return value
                .toLowerCase()
                .trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },
        init() {
            this.lastGeneratedSlug = this.slugify(this.title);

            this.$watch('title', (value) => {
                const generatedSlug = this.slugify(value);

                if (this.slug === '' || this.slug === this.lastGeneratedSlug) {
                    this.slug = generatedSlug;
                }

                this.lastGeneratedSlug = generatedSlug;
            });
        },
    }"
>
<x-admin.form-shell
    :title="'Edit ' . $contentTypeLabel"
    :description="$isChildrensTalk
        ? 'Update the published children\'s-talk details. Sermon-only fields stay hidden on this form.'
        : 'Update sermon details, metadata, and any AI-assisted content shown publicly.'"
>
    <x-slot:actions>
        @php
            $publicUrl = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk
                ? route('childrens-corner.show', ['sermon' => $sermon->slug])
                : route('sermons.show', ['sermon' => $sermon->slug]);
        @endphp
        <x-button :link="$publicUrl" variant="ghost" icon="eye" inline>
            View Public
        </x-button>
        <x-button link="{{ route('admin.sermons.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check">
            Save
        </x-form-button>
    </x-slot:actions>

    {{-- Main content (default slot = lg:col-span-2) --}}
            <x-card heading="{{ $contentTypeLabel }} details">
                <div class="space-y-4">
                    <x-input label="Title" wire:model.live.debounce="title" required maxlength="255" />

                    <x-input label="Slug" wire:model="slug" required maxlength="255"
                        hint="URL-friendly identifier (auto-generated from title)" />

                    <div class="grid grid-cols-2 gap-4">
                        <x-input type="date" label="Date" wire:model="date" required />
                        <x-select label="Service" wire:model="service"
                            :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])"
                            required />
                    </div>

                    @if($sermon->needs_preacher_review)
                        <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                            <div class="flex gap-3">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                                <div class="text-sm text-amber-800">
                                    <p class="font-medium mb-1">{{ $isChildrensTalk ? 'Speaker review required' : 'Preacher review required' }}</p>
                                    @if($sermon->preacher_source === \App\Enums\PreacherSource::SPEAKER_MODEL && $sermon->preacher_confidence !== null)
                                        <p>The AI identified a {{ $isChildrensTalk ? 'speaker' : 'preacher' }} with {{ round($sermon->preacher_confidence * 100) }}% confidence. Please verify and confirm or correct the assignment below.</p>
                                    @else
                                        <p>
                                            @if($isChildrensTalk)
                                                No speaker could be automatically identified. Please assign the correct speaker below.
                                            @else
                                                No speaker could be automatically identified. Please assign the correct preacher below.
                                            @endif
                                        </p>
                                    @endif
                                    <p class="mt-1 text-amber-600">Saving this form will clear the review flag.</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <x-select label="{{ $isChildrensTalk ? 'Speaker' : 'Preacher' }}" wire:model.live="preacherId"
                        :options="$preachers->map(fn($name, $id) => ['id' => $id, 'name' => $name])->values()->toArray()"
                        placeholder="Select a {{ $isChildrensTalk ? 'speaker' : 'preacher' }}..." />

                    <x-input label="Or enter {{ $isChildrensTalk ? 'speaker' : 'preacher' }} name" wire:model="preacher" maxlength="255"
                        hint="Used when the {{ $isChildrensTalk ? 'speaker' : 'preacher' }} is not in the list above" />

                    @unless($isChildrensTalk)
                        <x-input label="Bible Reference" wire:model="reference" maxlength="255"
                            placeholder="e.g., John 3:16-21" />
                    @endunless

                    <x-input label="Series" wire:model="series" maxlength="255"
                        placeholder="e.g., Gospel of John" />
                </div>

                <x-slot:footer>
                    <div class="flex justify-end gap-2">
                        <x-form-button variant="primary" wire:click="save" icon="check">
                            Save details
                        </x-form-button>
                    </div>
                </x-slot:footer>
            </x-card>

            @if($isChildrensTalk)
                <x-card heading="Children's talk notes">
                    <p class="text-sm text-gray-600">
                        Passage references, AI summaries, and sermon outline points are hidden here because Children's Corner uses a simplified public presentation.
                    </p>
                </x-card>
            @else
                <x-card heading="AI-Generated Content">
                    <div class="space-y-4">
                        <x-textarea label="Summary" wire:model="summary" rows="5" maxlength="1000"
                            hint="AI-generated sermon summary" />

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Points</label>
                            @foreach($points as $index => $point)
                                <div class="flex gap-2 mb-2">
                                    <x-input wire:model="points.{{ $index }}" class="flex-1" />
                                    <x-form-button variant="ghost" size="sm" icon="trash" class="text-red-600"
                                        wire:click="removePoint({{ $index }})"
                                        aria-label="Remove point" />
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <x-slot:footer>
                        <div class="flex justify-between items-center">
                            <x-form-button variant="ghost" size="sm" icon="plus" wire:click="addPoint" aria-label="Add sermon point">
                                Add point
                            </x-form-button>
                            <x-form-button variant="primary" wire:click="save" icon="check">
                                Save all
                            </x-form-button>
                        </div>
                    </x-slot:footer>
                </x-card>
            @endif

    <x-slot:sidebar>
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
                                class="rounded-lg border p-3 {{ $candidate['is_selected'] ? 'border-cbc-teal bg-cbc-teal/5' : 'border-gray-200 bg-white' }}"
                            >
                                <div class="space-y-3">
                                    @if($candidate['preview_url'])
                                        <img
                                            src="{{ $candidate['preview_url'] }}"
                                            alt="Thumbnail candidate {{ $loop->iteration }}"
                                            class="h-32 w-full rounded-lg border border-gray-200 object-cover"
                                        >
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
                                                wire:click="selectThumbnailCandidate('{{ $candidate['id'] }}')"
                                                wire:loading.attr="disabled"
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
                        >
                        <p class="text-sm text-gray-600">
                            This sermon has an existing thumbnail but no saved alternatives yet. Regenerate thumbnails to create five selectable options.
                        </p>
                    </div>
                @else
                    <p class="text-sm text-gray-500">
                        No saved sermon thumbnails yet.
                    </p>
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

        @unless($isChildrensTalk)
            <x-card heading="Display options">
                <div class="space-y-4">
                    <x-toggle label="Show Summary" wire:model="showSummary"
                        hint="Display AI-generated summary on sermon page" />

                    <x-toggle label="Show Points" wire:model="showPoints"
                        hint="Display AI-generated points on sermon page" />
                </div>
            </x-card>
        @endunless

        @if($sermon->hasVideo())
            <x-card heading="Video quality" wire:poll.10s.visible="refreshVideoQualityAssessment">
                <div class="space-y-4">
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-gray-500">Status</dt>
                            <dd class="font-medium text-gray-900">{{ $sermon->videoQualityStatus()->label() }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-gray-500">Reason</dt>
                            <dd class="font-medium text-gray-900">{{ $sermon->video_quality_reason ?: 'None' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-gray-500">Assessed</dt>
                            <dd class="font-medium text-gray-900">{{ $sermon->video_quality_assessed_at?->format('j M Y H:i') ?? 'Not assessed' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-gray-500">Override</dt>
                            <dd class="font-medium text-gray-900">{{ $sermon->videoVisibilityOverride()->label() }}</dd>
                        </div>
                    </dl>

                    <div class="grid gap-2">
                        <x-form-button
                            type="button"
                            variant="outline"
                            size="sm"
                            wire:click="setVideoVisibilityOverride('default')"
                        >
                            Use automatic verdict
                        </x-form-button>
                        <x-form-button
                            type="button"
                            variant="outline"
                            size="sm"
                            wire:click="setVideoVisibilityOverride('force_show')"
                        >
                            Force show video
                        </x-form-button>
                        <x-form-button
                            type="button"
                            variant="danger"
                            size="sm"
                            wire:click="setVideoVisibilityOverride('force_hide')"
                        >
                            Force hide video
                        </x-form-button>
                        <x-form-button
                            type="button"
                            variant="outline"
                            size="sm"
                            icon="arrow-path"
                            wire:click="rerunVideoQualityAssessment"
                        >
                            Re-run assessment
                        </x-form-button>
                    </div>

                    <p wire:loading wire:target="rerunVideoQualityAssessment" class="text-sm text-gray-500">
                        Queueing the sermon video assessment...
                    </p>
                </div>
            </x-card>
        @endif

        <x-card heading="{{ $isChildrensTalk ? 'Published Media' : 'Media Files' }}">
            <div class="space-y-3">
                @if($sermon->audio_file_path)
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-musical-note class="w-5 h-5 text-green-500" />
                        <span class="text-sm">Audio: Available</span>
                    </div>
                @endif

                @if($sermon->video_file_path)
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-video-camera class="w-5 h-5 text-blue-500" />
                        <span class="text-sm">Video: Available</span>
                    </div>
                @endif

                @if($sermon->transcript_file_path)
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-document-text class="w-5 h-5 text-amber-500" />
                        <span class="text-sm">Transcript: Available</span>
                    </div>
                @endif

                @if(!$sermon->audio_file_path && !$sermon->video_file_path && !$sermon->transcript_file_path)
                    <p class="text-sm text-gray-500">No media files</p>
                @endif
            </div>
        </x-card>
    </x-slot:sidebar>
</x-admin.form-shell>
</div>
