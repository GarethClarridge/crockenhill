<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">
            {{ $churchService->date->format('j M Y') }} ({{ $churchService->service->label() }})
        </h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
            <x-button link="{{ route('admin.services.review') }}" variant="outline" inline>
                Review Dashboard
            </x-button>
            <x-button link="{{ route('admin.services.edit', $churchService) }}" variant="outline" icon="pencil-square" inline>
                Edit Service
            </x-button>
            <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
                Song Catalog
            </x-button>
            <x-button link="{{ route('admin.services.section-publications') }}" variant="outline" inline>
                Section Queue
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
                Upload Another
            </x-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="Service Details">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Date</p>
                        <p class="font-medium">{{ $churchService->date->format('l j F Y') }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Service</p>
                        <p class="font-medium">{{ $churchService->service->label() }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Source</p>
                        <p class="font-medium uppercase">{{ $churchService->source }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Original filename</p>
                        <p class="font-medium">{{ $churchService->original_filename ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Imported at</p>
                        <p class="font-medium">{{ $churchService->updated_at?->format('j M Y H:i') }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Review status</p>
                        @if($churchService->needs_review)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                Needs review
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                                Ready
                            </span>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card heading="Service Items">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pos</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OpenLP Match Key</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($churchService->items as $item)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium">{{ $item->position }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($item->type) {
                                            'songs' => 'bg-green-100 text-green-800',
                                            'bibles' => 'bg-blue-100 text-blue-800',
                                            'presentations' => 'bg-slate-100 text-slate-800',
                                            default => 'bg-gray-100 text-gray-800',
                                        } }}">
                                            {{ ucfirst($item->type) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-medium">{{ $item->title }}</p>
                                        @if($item->song)
                                            <a
                                                href="{{ route('admin.services.songs.show', $item->song) }}"
                                                class="text-xs text-green-700 hover:text-green-800 no-underline"
                                                wire:navigate>
                                                View linked song
                                            </a>
                                        @endif
                                        @if($item->source_title && $item->source_title !== $item->title)
                                            <p class="text-xs text-gray-500">Source: {{ $item->source_title }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        {{ $item->openlp_search_title ?: '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                        This service has no items.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>

            <x-card heading="Classified Livestream Runs">
                <div class="space-y-4">
                    @forelse($processingRuns as $processingRun)
                        <div class="rounded-lg border border-gray-200 p-4" wire:key="processing-run-{{ $processingRun->id }}">
                            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium">Run {{ $processingRun->processing_id }}</p>
                                    <p class="text-xs text-gray-500">
                                        Status: {{ $processingRun->status->label() }} · Updated {{ $processingRun->updated_at?->diffForHumans() ?? 'Unknown' }}
                                    </p>
                                </div>

                                <x-form-button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    icon="arrow-path"
                                    wire:click="reclassify({{ $processingRun->id }})"
                                    wire:target="reclassify({{ $processingRun->id }})"
                                >
                                    Reclassify
                                </x-form-button>
                            </div>

                            @if($processingRun->serviceSections->isEmpty())
                                <p class="text-sm text-gray-500">
                                    No classified sections available for this run yet.
                                </p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Order</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Title</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Confidence</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Review</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Publication</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 bg-white">
                                            @foreach($processingRun->serviceSections as $section)
                                                @php
                                                    $confidence = $section->metadata['confidence_level'] ?? 'unknown';
                                                    $reviewReason = $section->metadata['review_reason'] ?? null;
                                                @endphp
                                                <tr wire:key="section-{{ $section->id }}">
                                                    <td class="px-3 py-2 text-sm font-medium">{{ $section->section_order }}</td>
                                                    <td class="px-3 py-2 text-sm">{{ $section->section_type->label() }}</td>
                                                    <td class="px-3 py-2 text-sm">{{ $section->title ?: '-' }}</td>
                                                    <td class="px-3 py-2 text-xs text-gray-600">
                                                        {{ number_format($section->start_time, 1) }}s - {{ number_format($section->end_time, 1) }}s
                                                    </td>
                                                    <td class="px-3 py-2 text-sm">{{ ucfirst((string) $confidence) }}</td>
                                                    <td class="px-3 py-2 text-sm">
                                                        @if($section->needs_manual_review)
                                                            <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">
                                                                Needs review
                                                            </span>
                                                            @if(is_string($reviewReason) && $reviewReason !== '')
                                                                <p class="mt-1 text-xs text-gray-500">{{ str_replace('_', ' ', $reviewReason) }}</p>
                                                            @endif
                                                        @else
                                                            <span class="inline-flex items-center rounded-full bg-cbc-teal-light/15 px-2.5 py-0.5 text-xs font-medium text-cbc-teal-dark">
                                                                Clear
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2 text-sm">
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($section->publication_status) {
                                                            \App\Enums\ServiceSectionPublicationStatus::PENDING_APPROVAL => 'bg-amber-100 text-amber-800',
                                                            \App\Enums\ServiceSectionPublicationStatus::APPROVED => 'bg-sky-100 text-sky-800',
                                                            \App\Enums\ServiceSectionPublicationStatus::REJECTED => 'bg-rose-100 text-rose-800',
                                                            \App\Enums\ServiceSectionPublicationStatus::PUBLISHED => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                                                            default => 'bg-gray-100 text-gray-700',
                                                        } }}">
                                                            {{ $section->publication_status->label() }}
                                                        </span>
                                                        @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PUBLISHED && $section->publishedSermon)
                                                            <p class="mt-1 text-xs">
                                                                <a href="{{ $section->publishedSermon->public_url }}" class="text-cbc-teal hover:text-cbc-teal-dark">
                                                                    View {{ strtolower($section->publishedSermon->content_type->label()) }}
                                                                </a>
                                                            </p>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">
                            No related livestream runs found for this service.
                        </p>
                    @endforelse
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card heading="Import Metadata">
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-gray-500">Parse method</p>
                        <p class="font-medium">{{ $importMetadata['parse_method'] ?? 'unknown' }}</p>
                    </div>

                    <div>
                        <p class="text-gray-500">Confidence score</p>
                        <p class="font-medium">
                            @if($confidenceScore !== null)
                                {{ number_format($confidenceScore * 100, 0) }}%
                            @else
                                Unknown
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-gray-500">Filename mismatch</p>
                        <p class="font-medium">{{ ($importMetadata['filename_mismatch'] ?? false) ? 'Yes' : 'No' }}</p>
                    </div>
                </div>
            </x-card>

            @if($warnings !== [])
                <x-card heading="Warnings">
                    <div class="space-y-2">
                        @foreach($warnings as $warning)
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {{ $warning }}
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
