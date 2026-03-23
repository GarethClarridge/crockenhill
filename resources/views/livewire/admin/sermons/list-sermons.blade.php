<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="font-display text-3xl text-gray-900">Sermons &amp; Talks</h2>
            <p class="text-gray-600">Manage sermon recordings and published children's talks.</p>
        </div>
        <x-button link="{{ route('admin.sermon-upload.create') }}" variant="primary" icon="cloud-arrow-up" inline>
            Upload Sermon
        </x-button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4">
        <x-input placeholder="Search..." wire:model.live.debounce="search"
            icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

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

        <div x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                Clear Filters
            </x-form-button>
        </div>
    </div>

    {{-- Table --}}
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach($headers as $header)
                            <x-admin.sortable-header
                                :column="$header['key']"
                                :label="$header['label']"
                                :sortable="$header['sortable']"
                                :sortBy="$sortBy"
                                :sortDirection="$sortDirection"
                            />
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($sermons as $sermon)
                        @php
                            $publicUrl = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk
                                ? route('childrens-corner.show', ['sermon' => $sermon->slug])
                                : route('showSermon', ['sermon' => $sermon->slug]);
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- Title --}}
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ Str::limit($sermon->title, 50) }}</p>
                                <p class="mt-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk ? 'bg-sky-100 text-sky-800' : 'bg-gray-100 text-gray-700' }}">
                                        {{ $sermon->content_type->label() }}
                                    </span>
                                </p>
                                @if($sermon->displayReference())
                                    <p class="text-sm text-gray-500">{{ $sermon->displayReference() }}</p>
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
                                <span class="text-sm">{{ $sermon->displayPreacherName() }}</span>
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
                                    <x-button link="{{ $publicUrl }}" variant="ghost" size="xs" icon="eye" inline aria-label="View {{ strtolower($sermon->content_type->label()) }}: {{ $sermon->title }}" />
                                    <x-button link="{{ route('admin.sermons.edit', $sermon) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit {{ strtolower($sermon->content_type->label()) }}: {{ $sermon->title }}" />
                                    <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                        wire:click="delete({{ $sermon->id }})"
                                        wire:confirm="Delete this {{ strtolower($sermon->content_type->label()) }}?"
                                        aria-label="Delete {{ strtolower($sermon->content_type->label()) }}: {{ $sermon->title }}" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-admin.empty-state
                            colspan="{{ count($headers) + 1 }}"
                            title="No sermons found"
                            :hasFilters="$hasFilters"
                        />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $sermons->links() }}
        </div>
    </x-card>
</div>
