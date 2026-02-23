<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">Edit Sermon</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.sermons.index') }}" variant="outline" inline>
                Cancel
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="check">
                Save
            </x-form-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="Sermon Details">
                <div class="space-y-4">
                    <x-input label="Title" wire:model.live.debounce="title" required />

                    <x-input label="Slug" wire:model="slug" required
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
                                    <p class="font-medium mb-1">Preacher review required</p>
                                    @if($sermon->preacher_source === \App\Enums\PreacherSource::SPEAKER_MODEL && $sermon->preacher_confidence !== null)
                                        <p>The AI identified a speaker with {{ round($sermon->preacher_confidence * 100) }}% confidence. Please verify and confirm or correct the assignment below.</p>
                                    @else
                                        <p>No speaker could be automatically identified. Please assign the correct preacher below.</p>
                                    @endif
                                    <p class="mt-1 text-amber-600">Saving this form will clear the review flag.</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <x-select label="Preacher" wire:model.live="preacherId"
                        :options="$preachers->map(fn($name, $id) => ['id' => $id, 'name' => $name])->values()->toArray()"
                        placeholder="Select a preacher..." />

                    <x-input label="Or enter preacher name" wire:model="preacher"
                        hint="Used when preacher is not in the list above" />

                    <x-input label="Bible Reference" wire:model="reference"
                        placeholder="e.g., John 3:16-21" />

                    <x-input label="Series" wire:model="series"
                        placeholder="e.g., Gospel of John" />
                </div>
            </x-card>

            <x-card heading="AI-Generated Content">
                <div class="space-y-4">
                    <x-textarea label="Summary" wire:model="summary" rows="5"
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
                        <x-form-button variant="ghost" size="sm" icon="plus" wire:click="addPoint" aria-label="Add sermon point">
                            Add Point
                        </x-form-button>
                    </div>
                </div>
            </x-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-card heading="Display Options">
                <div class="space-y-4">
                    <x-toggle label="Show Summary" wire:model="showSummary"
                        hint="Display AI-generated summary on sermon page" />

                    <x-toggle label="Show Points" wire:model="showPoints"
                        hint="Display AI-generated points on sermon page" />
                </div>
            </x-card>

            <x-card heading="Media Files">
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
        </div>
    </div>
</div>
