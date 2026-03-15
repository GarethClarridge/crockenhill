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

            @if($processingRuns->isEmpty())
                @include('livewire.admin.church-services.partials.planned-only-list', [
                    'items' => $churchService->items,
                ])
            @else
                <x-card heading="Classified Livestream Runs">
                    <div class="space-y-4">
                        @forelse($processingRuns as $processingRun)
                            @include('livewire.admin.church-services.partials.unified-timeline', [
                                'run'                => $processingRun,
                                'serviceTimeline'    => $serviceTimelines[$processingRun->id] ?? [],
                                'processingTimeline' => $processingTimelines[$processingRun->id] ?? [],
                            ])
                        @empty
                            <p class="text-sm text-gray-500">No related livestream runs found for this service.</p>
                        @endforelse
                    </div>
                </x-card>
            @endif
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
