<x-admin.list-shell
    title="Services"
    description="Plan, recording, and review status for every service"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
            Song catalogue
        </x-button>
        <x-button link="{{ route('admin.services.add') }}" variant="primary" icon="plus" inline>
            Add
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <div class="space-y-4">
            <section class="space-y-3" aria-labelledby="needs-attention-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="needs-attention-heading" class="font-display text-xl text-gray-900">
                        Needs attention
                        <span class="font-sans text-sm font-medium text-gray-500">{{ $attentionTotal }}</span>
                    </h2>
                    @if($attentionShown < $attentionTotal)
                        <p class="text-sm text-gray-500">
                            Showing the newest {{ $attentionShown }} of {{ $attentionTotal }} — resolve items to see older ones.
                        </p>
                    @endif
                </div>

                <div class="space-y-3" wire:loading.class.delay.200ms="opacity-50" aria-busy="false" wire:loading.attr="aria-busy">
                    @forelse($attentionGroups as $group)
                        <x-card wire:key="attention-group-{{ $group['key'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <h3 class="font-medium text-gray-900">
                                        {{ $group['date_label'] }}{{ $group['service_label'] !== '' ? ' — '.$group['service_label'] : '' }}
                                    </h3>
                                    @if($group['summary'] !== '')
                                        <p class="text-sm text-gray-600">{{ $group['summary'] }}</p>
                                    @endif
                                </div>
                                @if($group['service'] !== null && $group['summary'] !== '')
                                    <x-button :link="route('admin.services.show', $group['service'])" variant="outline" size="xs" icon="arrow-right" iconPosition="trailing" inline>
                                        Review service
                                    </x-button>
                                @elseif($group['service'] === null && $group['date'] !== null && $group['service_value'] !== null && $group['summary'] !== '')
                                    <x-button :link="route('admin.services.create', ['date' => $group['date'], 'service' => $group['service_value']])" variant="outline" size="xs" icon="plus" inline>
                                        Create this service
                                    </x-button>
                                @endif
                            </div>

                            @if($group['emails'] !== [])
                                <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-200">
                                    @foreach($group['emails'] as $index => $item)
                                        <li class="py-4" wire:key="attention-email-{{ $item['email']->id }}" wire:loading.class="opacity-50 pointer-events-none" wire:target="approveEmail({{ $item['email']->id }}), rejectEmail({{ $item['email']->id }}), reparseEmail({{ $item['email']->id }}), editAndApproveEmail({{ $item['email']->id }})">
                                            @include('livewire.admin.church-services.partials.inbound-email-attention-row', ['item' => $item])
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-card>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-center">
                            <p class="font-medium text-gray-900">All caught up</p>
                            <p class="mt-1 text-sm text-gray-500">Nothing currently needs your attention.</p>
                        </div>
                    @endforelse
                </div>
            </section>

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
                @php
                    $rollup = $rollups[$churchService->id] ?? null;
                    $needsReview = $rollup !== null && $rollup['status'] === \App\Enums\ChurchServiceRollupStatus::NeedsReview;
                @endphp
                <tr class="hover:bg-gray-50 {{ $needsReview ? 'border-l-4 border-amber-400 bg-amber-50/30' : '' }}" wire:loading.class.delay.200ms="opacity-50 pointer-events-none" wire:key="service-row-{{ $churchService->id }}">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $churchService->date->format('j M Y') }}</p>
                        <x-badge variant="default" size="xs">
                            {{ $churchService->service->label() }}
                        </x-badge>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm">{{ $churchService->items_count }} {{ \Illuminate\Support\Str::plural('item', $churchService->items_count) }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-sm uppercase">{{ $churchService->source }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if($rollup !== null)
                            <x-badge
                                :variant="match($rollup['status']) {
                                    \App\Enums\ChurchServiceRollupStatus::NeedsReview => 'warning',
                                    \App\Enums\ChurchServiceRollupStatus::Processing => 'sky',
                                    \App\Enums\ChurchServiceRollupStatus::Ready => 'teal',
                                    \App\Enums\ChurchServiceRollupStatus::Published => 'success',
                                    default => 'default',
                                }"
                                size="xs"
                                :pulse="$needsReview"
                            >
                                {{ $rollup['status']->label() . ($needsReview ? " ({$rollup['attention_count']})" : '') }}
                            </x-badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-medium">{{ $churchService->original_filename ?: '-' }}</p>
                        <p class="text-xs text-gray-500">{{ $churchService->updated_at?->diffForHumans() ?? '-' }}</p>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $churchService->date->format('j M Y') }} {{ $churchService->service->label() }} service">
                            <x-button
                                link="{{ route('admin.services.show', $churchService) }}?edit=1"
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
                        <x-button link="{{ route('admin.services.add') }}" variant="primary" icon="plus" inline>
                            Add to service
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
