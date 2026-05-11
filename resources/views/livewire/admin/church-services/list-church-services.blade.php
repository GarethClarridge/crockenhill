<x-admin.list-shell
    title="Services"
    description="View imported order-of-service records"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.review') }}" variant="outline" inline>
            Review dashboard
        </x-button>
        <x-button link="{{ route('admin.services.processing.review.index') }}" variant="outline" icon="video-camera" inline>
            Livestream review
        </x-button>
        <x-button link="{{ route('admin.services.inbound-emails') }}" variant="outline" icon="envelope" inline>
            Review emails
        </x-button>
        <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
            Song catalog
        </x-button>
        <x-button link="{{ route('admin.services.create') }}" variant="outline" icon="plus" inline>
            Create service
        </x-button>
        <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
            Upload service
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar>
            <x-input
                placeholder="Search by filename, date, or service..."
                wire:model.live.debounce="search"
                icon="magnifying-glass"
                clearable
                class="w-72"
                shortcut="slash" />

            <x-select
                placeholder="Service"
                wire:model.live="serviceFilter"
                :options="collect($services)->map(fn($service) => ['id' => $service->value, 'name' => $service->label()])->toArray()"
                class="w-40" />

            <x-select
                placeholder="Review"
                wire:model.live="needsReviewFilter"
                :options="[
                    ['id' => '1', 'name' => 'Needs review'],
                    ['id' => '0', 'name' => 'Ready'],
                ]"
                class="w-44" />

            <x-slot:actions>
                <div x-show="$wire.hasFilters" x-transition x-cloak>
                    <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                        Clear Filters
                    </x-form-button>
                </div>
            </x-slot:actions>
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $churchServices->links(data: ['scrollTo' => '#admin-list-results']) }}
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
            @forelse($churchServices as $churchService)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $churchService->date->format('j M Y') }}</p>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($churchService->service) {
                            \App\Enums\SermonService::Morning => 'bg-green-100 text-green-800',
                            \App\Enums\SermonService::Evening => 'bg-amber-100 text-amber-800',
                            default => 'bg-gray-100 text-gray-800',
                        } }}">
                            {{ $churchService->service->label() }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm">{{ $churchService->items_count }} {{ \Illuminate\Support\Str::plural('item', $churchService->items_count) }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-sm uppercase">{{ $churchService->source }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @if($churchService->needs_review)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                    Needs review
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                                    Ready
                                </span>
                            @endif
                            @if($churchService->pending_structure_merge_source !== null)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                    Pending merge
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-medium">{{ $churchService->original_filename ?: '-' }}</p>
                        <p class="text-xs text-gray-500">{{ $churchService->updated_at?->format('j M Y H:i') }}</p>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $churchService->date->format('j M Y') }} {{ $churchService->service->label() }} service">
                            <x-button
                                link="{{ route('admin.services.edit', $churchService) }}"
                                variant="ghost"
                                size="xs"
                                icon="pencil-square"
                                inline
                                aria-label="Edit service for {{ $churchService->date->format('j M Y') }}" />
                            <x-button
                                link="{{ route('admin.services.show', $churchService) }}"
                                variant="ghost"
                                size="xs"
                                icon="eye"
                                inline
                                aria-label="View service for {{ $churchService->date->format('j M Y') }}" />
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="{{ count($headers) + 1 }}"
                    title="No services found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <div class="flex gap-2 justify-center">
                            <x-button link="{{ route('admin.services.create') }}" variant="outline" icon="plus" inline>
                                Create service
                            </x-button>
                            <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
                                Upload service
                            </x-button>
                        </div>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
