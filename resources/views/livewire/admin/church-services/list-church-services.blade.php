<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Services</h1>
            <p class="text-gray-600">View imported order-of-service records</p>
        </div>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.review') }}" variant="outline" inline>
                Review Dashboard
            </x-button>
            <x-button link="{{ route('admin.services.processing.review.index') }}" variant="outline" icon="video-camera" inline>
                Livestream Review
            </x-button>
            <x-button link="{{ route('admin.services.inbound-emails') }}" variant="outline" icon="envelope" inline>
                Review Emails
            </x-button>
            <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
                Song Catalog
            </x-button>
            <x-button link="{{ route('admin.services.create') }}" variant="outline" icon="plus" inline>
                Create Service
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
                Upload Service
            </x-button>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
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

        <div x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                Clear Filters
            </x-form-button>
        </div>
    </div>

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
                    @forelse($churchServices as $churchService)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $churchService->date->format('j M Y') }}</p>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($churchService->service) {
                                    \App\Enums\SermonService::MORNING => 'bg-green-100 text-green-800',
                                    \App\Enums\SermonService::EVENING => 'bg-amber-100 text-amber-800',
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
                                @if($churchService->needs_review)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                        Needs review
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                                        Ready
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium">{{ $churchService->original_filename ?: '-' }}</p>
                                <p class="text-xs text-gray-500">{{ $churchService->updated_at?->format('j M Y H:i') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1 justify-end">
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
                        />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $churchServices->links() }}
        </div>
    </x-card>
</div>
