<div>
    <x-mary-header title="Sermons" subtitle="Manage sermon recordings">
        <x-slot:actions>
            <x-mary-button label="Upload Sermon" icon="o-cloud-arrow-up"
                link="{{ route('sermon.upload') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search..." wire:model.live.debounce="search"
            icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select placeholder="Service" wire:model.live="serviceFilter"
            :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])"
            class="w-40" />

        <x-mary-select placeholder="Preacher" wire:model.live="preacherFilter"
            :options="$preachers->map(fn($p) => ['id' => $p, 'name' => $p])"
            class="w-48" />

        <x-mary-select placeholder="Series" wire:model.live="seriesFilter"
            :options="$seriesList->map(fn($s) => ['id' => $s, 'name' => $s])"
            class="w-48" />

        <x-mary-toggle label="Has Video" wire:model.live="hasVideoFilter" />
        <x-mary-toggle label="Last 12 Months" wire:model.live="last12Months" />
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$sermons" striped>
            @scope('cell_title', $sermon)
                <div>
                    <p class="font-medium">{{ Str::limit($sermon->title, 50) }}</p>
                    @if($sermon->reference)
                        <p class="text-sm text-base-content/60">{{ $sermon->reference }}</p>
                    @endif
                </div>
            @endscope

            @scope('cell_date', $sermon)
                <span>{{ $sermon->date->format('j M Y') }}</span>
            @endscope

            @scope('cell_service', $sermon)
                <x-mary-badge :value="$sermon->service->label()"
                    class="{{ match($sermon->service) {
                        \App\Enums\SermonService::MORNING => 'badge-primary',
                        \App\Enums\SermonService::EVENING => 'badge-warning',
                        default => 'badge-ghost',
                    } }}" />
            @endscope

            @scope('cell_preacher', $sermon)
                <span class="text-sm">{{ $sermon->preacher }}</span>
            @endscope

            @scope('cell_series', $sermon)
                @if($sermon->series)
                    <span class="text-sm">{{ Str::limit($sermon->series, 30) }}</span>
                @else
                    <span class="text-sm text-base-content/30">-</span>
                @endif
            @endscope

            @scope('cell_media', $sermon)
                <div class="flex gap-1">
                    @if($sermon->audio_file_path)
                        <x-mary-icon name="o-musical-note" class="w-4 h-4 text-success" />
                    @endif
                    @if($sermon->video_file_path)
                        <x-mary-icon name="o-video-camera" class="w-4 h-4 text-info" />
                    @endif
                </div>
            @endscope

            @scope('actions', $sermon)
                <div class="flex gap-1">
                    <x-mary-button icon="o-eye" link="{{ route('showSermon', $sermon) }}" external class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-pencil" link="{{ route('admin.sermons.edit', $sermon) }}" class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-trash" wire:click="delete({{ $sermon->id }})"
                        wire:confirm="Delete this sermon?" class="btn-ghost btn-xs text-error" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $sermons->links() }}
        </div>
    </x-mary-card>
</div>
