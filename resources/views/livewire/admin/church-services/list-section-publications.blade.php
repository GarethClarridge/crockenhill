<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl">Section Publications</h1>
            <p class="text-gray-600">Review and publish extracted service sections</p>
        </div>

        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.review') }}" variant="outline" inline>
                Review dashboard
            </x-button>
            <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
                Song catalog
            </x-button>
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to services
            </x-button>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
        <x-input
            placeholder="Search title, section type, or run ID..."
            wire:model.live.debounce="search"
            icon="magnifying-glass"
            clearable
            class="w-80" />

        <x-select
            placeholder="Status"
            wire:model.live="publicationStatus"
            :options="collect($statuses)->map(fn($status) => ['id' => $status->value, 'name' => $status->label()])->toArray()"
            class="w-56" />
    </div>

    <x-card id="admin-list-results" tabindex="-1" class="focus:outline-none">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Service</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Section</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Confidence</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($sections as $section)
                        @php
                            $processingMetadata = is_array($section->processingLog->processing_metadata ?? null) ? $section->processingLog->processing_metadata : [];
                            $serviceDate = $section->processingLog->extracted_date?->toDateString() ?? $processingMetadata['extracted_date'] ?? 'Unknown';
                            $serviceType = $section->processingLog->extracted_service?->label() ?? \Illuminate\Support\Str::title((string) ($processingMetadata['extracted_service'] ?? 'unknown'));
                            $confidence = $section->metadata['confidence_level'] ?? 'unknown';
                            $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
                        @endphp
                        <tr wire:key="section-publication-{{ $section->id }}" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium">{{ $section->processingLog->processing_id }}</p>
                                <p class="text-xs text-gray-500">#{{ $section->id }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium">{{ $serviceDate }}</p>
                                <p class="text-xs text-gray-500">{{ $serviceType }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium">{{ $section->section_type->label() }}</p>
                                <p class="text-xs text-gray-500">{{ $section->title ?: '-' }}</p>
                                @if($publicationSpeaker)
                                    <p class="text-xs text-gray-500">Speaker: {{ $publicationSpeaker['preacher_name'] }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                {{ number_format($section->start_time, 1) }}s - {{ number_format($section->end_time, 1) }}s
                            </td>
                            <td class="px-4 py-3 text-sm">{{ ucfirst((string) $confidence) }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($section->publication_status) {
                                    \App\Enums\ServiceSectionPublicationStatus::PENDING_APPROVAL => 'bg-amber-100 text-amber-800',
                                    \App\Enums\ServiceSectionPublicationStatus::APPROVED => 'bg-sky-100 text-sky-800',
                                    \App\Enums\ServiceSectionPublicationStatus::REJECTED => 'bg-rose-100 text-rose-800',
                                    \App\Enums\ServiceSectionPublicationStatus::PUBLISHED => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                                    default => 'bg-gray-100 text-gray-700',
                                } }}">
                                    {{ $section->publication_status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PENDING_APPROVAL)
                                        <x-form-button
                                            type="button"
                                            size="xs"
                                            variant="primary"
                                            wire:click="approve({{ $section->id }})"
                                            wire:target="approve({{ $section->id }})"
                                        >
                                            Approve
                                        </x-form-button>
                                        <x-form-button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            wire:click="reject({{ $section->id }})"
                                            wire:target="reject({{ $section->id }})"
                                        >
                                            Reject
                                        </x-form-button>
                                    @elseif($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::APPROVED)
                                        <x-form-button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            wire:click="reject({{ $section->id }})"
                                            wire:target="reject({{ $section->id }})"
                                        >
                                            Reject
                                        </x-form-button>
                                    @elseif($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::REJECTED)
                                        <x-form-button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            wire:click="requeue({{ $section->id }})"
                                            wire:target="requeue({{ $section->id }})"
                                        >
                                            Requeue
                                        </x-form-button>
                                    @elseif($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PUBLISHED && $section->publishedSermon)
                                        @php
                                            $publishedSermonUrl = $section->publishedSermon->content_type === \App\Enums\SermonContentType::ChildrensTalk
                                                ? route('childrens-corner.show', ['sermon' => $section->publishedSermon->slug])
                                                : route('sermons.show', ['sermon' => $section->publishedSermon->slug]);
                                        @endphp
                                        <x-button
                                            link="{{ $publishedSermonUrl }}"
                                            size="xs"
                                            variant="ghost"
                                            icon="eye"
                                            inline
                                        >
                                            View
                                        </x-button>
                                    @else
                                        <span class="text-xs text-gray-400">-</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                No section publications found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $sections->links(data: ['scrollTo' => '#admin-list-results']) }}
        </div>
    </x-card>
</div>
