<div>
    <x-mary-header title="Edit Sermon">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.sermons.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="Sermon Details">
                <div class="space-y-4">
                    <x-mary-input label="Title" wire:model.live.debounce="title" required />

                    <x-mary-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier (auto-generated from title)" />

                    <div class="grid grid-cols-2 gap-4">
                        <x-mary-input type="date" label="Date" wire:model="date" required />
                        <x-mary-select label="Service" wire:model="service"
                            :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])"
                            required />
                    </div>

                    <x-mary-input label="Preacher" wire:model="preacher" required />

                    <x-mary-input label="Bible Reference" wire:model="reference"
                        placeholder="e.g., John 3:16-21" />

                    <x-mary-input label="Series" wire:model="series"
                        placeholder="e.g., Gospel of John" />
                </div>
            </x-mary-card>

            <x-mary-card title="AI-Generated Content">
                <div class="space-y-4">
                    <x-mary-textarea label="Summary" wire:model="summary" rows="5"
                        hint="AI-generated sermon summary" />

                    <div>
                        <label class="label">
                            <span class="label-text font-semibold">Points</span>
                        </label>
                        @foreach($points as $index => $point)
                            <div class="flex gap-2 mb-2">
                                <x-mary-input wire:model="points.{{ $index }}" class="flex-1" />
                                <x-mary-button icon="o-trash" wire:click="removePoint({{ $index }})"
                                    class="btn-ghost btn-sm text-error" />
                            </div>
                        @endforeach
                        <x-mary-button label="Add Point" icon="o-plus" wire:click="addPoint"
                            class="btn-sm btn-ghost" />
                    </div>
                </div>
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Display Options">
                <div class="space-y-4">
                    <x-mary-toggle label="Show Summary" wire:model="showSummary"
                        hint="Display AI-generated summary on sermon page" />

                    <x-mary-toggle label="Show Points" wire:model="showPoints"
                        hint="Display AI-generated points on sermon page" />
                </div>
            </x-mary-card>

            <x-mary-card title="Media Files">
                <div class="space-y-3">
                    @if($sermon->audio_file_path)
                        <div class="flex items-center gap-2">
                            <x-mary-icon name="o-musical-note" class="w-5 h-5 text-success" />
                            <span class="text-sm">Audio: Available</span>
                        </div>
                    @endif

                    @if($sermon->video_file_path)
                        <div class="flex items-center gap-2">
                            <x-mary-icon name="o-video-camera" class="w-5 h-5 text-info" />
                            <span class="text-sm">Video: Available</span>
                        </div>
                    @endif

                    @if($sermon->transcript_file_path)
                        <div class="flex items-center gap-2">
                            <x-mary-icon name="o-document-text" class="w-5 h-5 text-warning" />
                            <span class="text-sm">Transcript: Available</span>
                        </div>
                    @endif

                    @if(!$sermon->audio_file_path && !$sermon->video_file_path && !$sermon->transcript_file_path)
                        <p class="text-sm text-base-content/60">No media files</p>
                    @endif
                </div>
            </x-mary-card>
        </div>
    </div>
</div>
