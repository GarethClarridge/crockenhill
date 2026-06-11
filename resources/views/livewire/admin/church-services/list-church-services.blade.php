<x-admin.list-shell
    title="Services"
    description="Plan, recording, and review status for every service"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
            Song catalogue
        </x-button>
        <x-admin.action-menu label="Add">
            <x-admin.action-menu-item link="{{ route('admin.services.upload-recording') }}" icon="film">
                Upload recording
            </x-admin.action-menu-item>
            <x-admin.action-menu-item link="{{ route('admin.services.upload') }}" icon="arrow-up-tray">
                Upload order of service
            </x-admin.action-menu-item>
            <x-admin.action-menu-item link="{{ route('admin.services.submit-email') }}" icon="envelope">
                Paste email text
            </x-admin.action-menu-item>
            <x-admin.action-menu-item link="{{ route('admin.services.create') }}" icon="pencil-square">
                Create manually
            </x-admin.action-menu-item>
        </x-admin.action-menu>
    </x-slot:actions>

    <x-slot:filters>
        <div class="space-y-4">
            <x-admin.attention-strip :chips="$attentionChips" />

            @if($heroService !== null)
                @include('livewire.admin.church-services.partials.services-hub-hero')
            @endif

            <x-admin.filter-bar loading-target="search, serviceFilter, needsReviewFilter, resetFilters">
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
            </x-admin.filter-bar>
        </div>
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
                @php $rollup = $rollups[$churchService->id] ?? null; @endphp
                <tr class="hover:bg-gray-50" wire:loading.class.delay.200ms="opacity-50" wire:key="service-row-{{ $churchService->id }}">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $churchService->date->format('j M Y') }}</p>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
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
                        @if($rollup !== null)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $rollup['status']->badgeClasses() }}">
                                {{ $rollup['status']->label() . ($rollup['status'] === \App\Enums\ChurchServiceRollupStatus::NeedsReview ? " ({$rollup['attention_count']})" : '') }}
                            </span>
                        @endif
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
