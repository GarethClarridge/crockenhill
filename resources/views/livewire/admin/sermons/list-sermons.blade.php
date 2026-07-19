<x-admin.list-shell
    title="Sermons &amp; Talks"
    description="Manage sermon recordings and published children's talks"
    :paginator="$sermons"
    itemsName="sermon"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.upload-recording') }}" variant="primary" icon="cloud-arrow-up" inline>
            Upload recording
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, serviceFilter, preacherFilter, seriesFilter, hasVideoFilter, needsReviewFilter, last12Months, resetFilters">
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

            <x-toggle label="Has video" wire:model.live="hasVideoFilter" />
            <x-toggle label="Needs review" wire:model.live="needsReviewFilter" />
            <x-toggle label="Last 12 months" wire:model.live="last12Months" />
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $sermons->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

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
                        : route('sermons.show', ['sermon' => $sermon->slug]);
                @endphp
                <tr wire:loading.class="opacity-50 pointer-events-none" wire:target="delete({{ $sermon->id }})" class="hover:bg-gray-50 {{ $sermon->needs_preacher_review ? 'border-l-4 border-amber-400 bg-amber-50/30' : '' }}">
                    {{-- Title --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ Str::limit($sermon->title, 50) }}</p>
                        <p class="mt-2">
                            <x-badge :variant="$sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk ? 'sky' : 'default'" size="xs">
                                {{ $sermon->content_type->label() }}
                            </x-badge>
                        </p>
                        @if($sermonViewPresenter->displayReference($sermon))
                            <p class="text-sm text-gray-500">{{ $sermonViewPresenter->displayReference($sermon) }}</p>
                        @endif
                    </td>
                    {{-- Date --}}
                    <td class="px-4 py-3">
                        <time datetime="{{ $sermon->date->toDateString() }}" class="text-sm">
                            {{ $sermon->date->format('j M Y') }}
                        </time>
                    </td>
                    {{-- Service --}}
                    <td class="px-4 py-3">
                        <x-badge :variant="match($sermon->service) {
                            \App\Enums\SermonService::Morning => 'success',
                            \App\Enums\SermonService::Evening => 'amber',
                            default => 'default',
                        }" size="xs">
                            {{ $sermon->service->label() }}
                        </x-badge>
                    </td>
                    {{-- Preacher --}}
                    <td class="px-4 py-3">
                        @if($preacherName = $sermonViewPresenter->displayPreacherName($sermon))
                            <span class="text-sm">{{ $preacherName }}</span>
                        @else
                            <span class="text-sm text-gray-300">-</span>
                        @endif
                        @if($sermon->needs_preacher_review)
                            <x-badge variant="warning" size="xs" class="ml-1" pulse>
                                Review
                            </x-badge>
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
                                <x-heroicon-o-musical-note class="w-4 h-4 text-green-500" title="Audio available" aria-label="Audio available" />
                            @endif
                            @if($sermon->video_file_path)
                                <x-heroicon-o-video-camera class="w-4 h-4 text-blue-500" title="Video available" aria-label="Video available" />
                            @endif
                        </div>
                    </td>
                    {{-- Actions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $sermon->title }}">
                            <x-clipboard-button :content="$publicUrl" hideLabel label="Copy public link" title="Copy public link to clipboard" />
                            <x-button link="{{ $publicUrl }}" variant="ghost" size="xs" icon="eye" inline aria-label="View {{ strtolower($sermon->content_type->label()) }}: {{ $sermon->title }}" />
                            <x-button link="{{ route('admin.sermons.edit', $sermon) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit {{ strtolower($sermon->content_type->label()) }}: {{ $sermon->title }}" />
                            <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                wire:click="delete({{ $sermon->id }})"
                                wire:target="delete({{ $sermon->id }})"
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
                >
                    @if(!$hasFilters)
                        <x-button link="{{ route('admin.services.upload-recording') }}" variant="primary" icon="cloud-arrow-up" inline>
                            Upload recording
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
