<x-admin.form-shell
    :title="'Edit ' . $contentTypeLabel"
    :description="$isChildrensTalk
        ? 'Update the published children\'s talk details. Sermon-only fields stay hidden on this form.'
        : 'Update sermon details, metadata, and any AI-assisted content shown publicly.'"
    save-action="save"
>
    <x-slot:actions>
        @php
            $publicUrl = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk
                ? route('childrens-corner.show', ['sermon' => $sermon->slug])
                : route('sermons.show', ['sermon' => $sermon->slug]);
        @endphp
        <x-button :link="$publicUrl" variant="ghost" icon="eye" inline>
            View {{ strtolower($contentTypeLabel) }}
        </x-button>
        <x-clipboard-button :content="$publicUrl" label="Copy link" title="Copy public link to clipboard" class="text-gray-500 hover:text-gray-700" />
        <x-button link="{{ route('admin.sermons.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check" loading-label="Saving {{ strtolower($contentTypeLabel) }}...">
            Save {{ strtolower($contentTypeLabel) }}
        </x-form-button>
    </x-slot:actions>

    {{-- Main content (default slot = lg:col-span-2) --}}
    <x-card heading="{{ $contentTypeLabel }} details">
        <div class="space-y-4">
            <x-input label="Title" wire:model.live.debounce="form.title" required maxlength="255" autofocus />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex items-start gap-2">
                    <div class="flex-1">
                        <x-input label="Slug" wire:model="form.slug" required maxlength="255"
                            hint="URL-friendly identifier (auto-generated from title)" />
                    </div>
                    <x-clipboard-button js-content="$wire.form.slug" hideLabel label="Copy slug" title="Copy slug to clipboard" class="mt-7" />
                </div>

                <x-input type="number" label="Download count" wire:model="form.downloadCount"
                    hint="Incremented automatically when the audio is downloaded" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-input type="date" label="Date" wire:model="form.date" required />
                <x-select label="Service" wire:model="form.service"
                    :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])"
                    required />
            </div>

            @if($sermon->needs_preacher_review)
                <x-alert type="warning" :title="$isChildrensTalk ? 'Speaker review required' : 'Preacher review required'">
                    @if($sermon->preacher_source === \App\Enums\PreacherSource::SpeakerModel && $sermon->preacher_confidence !== null)
                        <p>The AI identified a {{ $isChildrensTalk ? 'speaker' : 'preacher' }} with {{ round($sermon->preacher_confidence * 100) }}% confidence. Please verify and confirm or correct the assignment below.</p>
                    @else
                        <p>No {{ $isChildrensTalk ? 'speaker' : 'preacher' }} could be automatically identified. Please assign the correct {{ $isChildrensTalk ? 'speaker' : 'preacher' }} below.</p>
                    @endif
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <x-form-button type="button" variant="secondary" size="sm" icon="user-plus" @click="document.getElementById('form-preacherId').focus()">
                            Assign {{ $isChildrensTalk ? 'speaker' : 'preacher' }}
                        </x-form-button>
                        <p class="text-xs text-amber-700">Saving this form will clear the review flag.</p>
                    </div>
                </x-alert>
            @endif

            <x-select label="{{ $isChildrensTalk ? 'Speaker' : 'Preacher' }}" wire:model.live="form.preacherId"
                :options="$preachers->map(fn($name, $id) => ['id' => $id, 'name' => $name])->values()->toArray()"
                placeholder="Select a {{ $isChildrensTalk ? 'speaker' : 'preacher' }}..." />

            <x-input label="Or enter {{ $isChildrensTalk ? 'speaker' : 'preacher' }} name" wire:model="form.preacher" maxlength="255"
                hint="Used when the {{ $isChildrensTalk ? 'speaker' : 'preacher' }} is not in the list above" />

            @unless($isChildrensTalk)
                <x-input label="Bible reference" wire:model="form.reference" maxlength="255"
                    placeholder="e.g., John 3:16-21" />
            @endunless

            <x-input label="Series" wire:model="form.series" maxlength="255"
                placeholder="e.g., Gospel of John" />
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-form-button variant="primary" wire:click="save" icon="check" loading-label="Saving details...">
                    Save {{ strtolower($contentTypeLabel) }} details
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
        <x-card heading="AI-generated content">
            <div class="space-y-4">
                <x-textarea label="Summary" wire:model="form.summary" rows="5" maxlength="1000"
                    hint="A brief overview shown on the public sermon page." autogrow />

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Points</label>
                    <div class="space-y-2">
                        @forelse($points as $index => $point)
                            <div wire:key="point-{{ $index }}"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 -translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-150"
                                 x-transition:leave-start="opacity-100 translate-y-0"
                                 x-transition:leave-end="opacity-0 -translate-y-2"
                                 class="group flex items-center gap-2">
                                <span class="text-xs font-medium text-gray-400 tabular-nums w-6">{{ $index + 1 }}.</span>
                                <x-input wire:model="form.points.{{ $index }}" class="flex-1" maxlength="255" />
                                <x-form-button variant="ghost" size="sm" icon="trash" class="text-red-600 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity focus-visible:opacity-100"
                                    wire:click="removePoint({{ $index }})"
                                    wire:confirm="Remove this sermon point?"
                                    aria-label="Remove point: {{ $index + 1 }}" />
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 italic py-2">
                                No points defined for this sermon yet. Click "Add point" to start.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-between items-center">
                    <x-form-button variant="ghost" size="sm" icon="plus" wire:click="addPoint" aria-label="Add sermon point">
                        Add point
                    </x-form-button>
                    <x-form-button variant="primary" wire:click="save" icon="check" loading-label="Saving content...">
                        Save all content
                    </x-form-button>
                </div>
            </x-slot:footer>
        </x-card>
    @endif

    <x-slot:sidebar>
        <livewire:admin.sermons.edit-sermon-thumbnails :sermon="$sermon" defer />

        @unless($isChildrensTalk)
            <x-card heading="Display options">
                <div class="space-y-4">
                    <x-toggle label="Show summary" wire:model="form.showSummary"
                        hint="Display the summary on the public sermon page." />

                    <x-toggle label="Show points" wire:model="form.showPoints"
                        hint="Display the sermon outline points on the public sermon page." />
                </div>
            </x-card>
        @endunless

        @island(name: 'video-quality')
            @if($sermon->hasVideo())
                <x-card heading="Video quality" wire:poll.10s.visible="refreshVideoQualityAssessment">
                    <div class="space-y-4">
                        <x-metadata-list :items="[
                            ['label' => 'Status',   'value' => $sermon->videoQualityStatus()->label()],
                            ['label' => 'Reason',   'value' => $sermon->video_quality_reason ?: 'None'],
                            ['label' => 'Assessed', 'value' => $sermon->video_quality_assessed_at?->format('j M Y H:i') ?? 'Not assessed'],
                            ['label' => 'Override', 'value' => $sermon->videoVisibilityOverride()->label()],
                        ]" />

                        <div class="grid gap-2">
                            <x-form-button
                                type="button"
                                :variant="$sermon->videoVisibilityOverride() === \App\Enums\SermonVideoVisibilityOverride::Default ? 'primary' : 'outline'"
                                size="sm"
                                wire:click="setVideoVisibilityOverride('default')"
                            >
                                Use automatic verdict
                            </x-form-button>
                            <x-form-button
                                type="button"
                                :variant="$sermon->videoVisibilityOverride() === \App\Enums\SermonVideoVisibilityOverride::ForceShow ? 'primary' : 'outline'"
                                size="sm"
                                wire:click="setVideoVisibilityOverride('force_show')"
                            >
                                Force show video
                            </x-form-button>
                            <x-form-button
                                type="button"
                                :variant="$sermon->videoVisibilityOverride() === \App\Enums\SermonVideoVisibilityOverride::ForceHide ? 'danger' : 'outline'"
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
        @endisland

        <x-card heading="{{ $isChildrensTalk ? 'Published media' : 'Media files' }}">
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
