<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Sermons</h1>
            <p class="text-gray-600">Manage sermon recordings</p>
        </div>
        <x-button link="{{ route('admin.sermon-upload.create') }}" variant="primary" icon="cloud-arrow-up" inline>
            Upload Sermon
        </x-button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4">
        <x-input placeholder="Search..." wire:model.live.debounce="search"
            icon="magnifying-glass" clearable class="w-64" />

        <x-select placeholder="Service" wire:model.live="serviceFilter"
            :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])->toArray()"
            class="w-40" />

        <x-select placeholder="Preacher" wire:model.live="preacherFilter"
            :options="$preachers->map(fn($name, $id) => ['id' => $id, 'name' => $name])->values()->toArray()"
            class="w-48" />

        <x-select placeholder="Series" wire:model.live="seriesFilter"
            :options="$seriesList->map(fn($s) => ['id' => $s, 'name' => $s])->toArray()"
            class="w-48" />

        <x-toggle label="Has Video" wire:model.live="hasVideoFilter" />
        <x-toggle label="Needs Review" wire:model.live="needsReviewFilter" />
        <x-toggle label="Last 12 Months" wire:model.live="last12Months" />
    </div>

    {{-- Table --}}
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach($headers as $header)
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ $header['label'] }}
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($sermons as $sermon)
                        <tr class="hover:bg-gray-50">
                            {{-- Title --}}
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ Str::limit($sermon->title, 50) }}</p>
                                @if($sermon->reference)
                                    <p class="text-sm text-gray-500">{{ $sermon->reference }}</p>
                                @endif
                            </td>
                            {{-- Date --}}
                            <td class="px-4 py-3">
                                <span>{{ $sermon->date->format('j M Y') }}</span>
                            </td>
                            {{-- Service --}}
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($sermon->service) {
                                    \App\Enums\SermonService::MORNING => 'bg-green-100 text-green-800',
                                    \App\Enums\SermonService::EVENING => 'bg-amber-100 text-amber-800',
                                    default => 'bg-gray-100 text-gray-800',
                                } }}">
                                    {{ $sermon->service->label() }}
                                </span>
                            </td>
                            {{-- Preacher --}}
                            <td class="px-4 py-3">
                                <span class="text-sm">{{ $sermon->preacherProfile->name ?? $sermon->preacher }}</span>
                                @if($sermon->needs_preacher_review)
                                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Review</span>
                                @endif
                            </td>
                            {{-- Series --}}
                            <td class="px-4 py-3">
                                @if($sermon->series)
                                    <span class="text-sm">{{ Str::limit($sermon->series, 30) }}</span>
                                @else
                                    <span class="text-sm text-gray-300">-</span>
                                @endif
                            </td>
                            {{-- Media --}}
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    @if($sermon->audio_file_path)
                                        <x-heroicon-o-musical-note class="w-4 h-4 text-green-500" />
                                    @endif
                                    @if($sermon->video_file_path)
                                        <x-heroicon-o-video-camera class="w-4 h-4 text-blue-500" />
                                    @endif
                                </div>
                            </td>
                            {{-- Actions --}}
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1 justify-end">
                                    <x-button link="{{ route('showSermon', $sermon) }}" variant="ghost" size="xs" icon="eye" inline />
                                    <x-button link="{{ route('admin.sermons.edit', $sermon) }}" variant="ghost" size="xs" icon="pencil" inline />
                                    <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                        wire:click="delete({{ $sermon->id }})"
                                        wire:confirm="Delete this sermon?" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($headers) + 1 }}" class="px-4 py-8 text-center text-gray-500">
                                No sermons found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $sermons->links() }}
        </div>
    </x-card>
</div>
