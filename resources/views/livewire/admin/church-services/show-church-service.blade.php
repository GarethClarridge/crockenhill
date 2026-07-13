<x-admin.page
    :title="$churchService->date->format('j M Y').' '.$churchService->service->label()"
    description="Review the planned service, processing runs, and publication state"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.inbox') }}" variant="outline" icon="inbox" inline>
            Review inbox
        </x-button>
        <x-button link="{{ route('admin.services.edit', $churchService) }}" variant="outline" icon="pencil-square" inline>
            Edit service
        </x-button>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
    </x-slot:actions>

    <x-card class="py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-admin.pipeline-steps :steps="$pipelineSteps" />

            @if($sectionPublishingEnabled && $pendingApprovalCount > 0)
                <div class="flex items-center gap-3">
                    <p class="text-xs text-gray-500">
                        {{ $pendingApprovalCount }} pending {{ \Illuminate\Support\Str::plural('publication', $pendingApprovalCount) }}
                    </p>
                    <x-form-button
                        type="button"
                        variant="primary"
                        size="sm"
                        wire:click="approvePendingPublications({{ $churchService->id }})"
                        wire:target="approvePendingPublications({{ $churchService->id }})"
                        wire:loading.attr="disabled"
                    >
                        Approve all pending publications
                    </x-form-button>
                </div>
            @endif
        </div>
    </x-card>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div class="lg:col-span-3 space-y-6">
            @if($pendingMerge)
                @include('livewire.admin.church-services.partials.pending-structure-merge', [
                    'pendingMerge' => $pendingMerge,
                    'pendingMergeSource' => $pendingMergeSource,
                ])
            @endif

            @if($processingRunViews === [])
                @include('livewire.admin.church-services.partials.planned-only-list', [
                    'items' => $churchService->items,
                ])
            @else
                <x-card heading="Classified livestream runs">
                    <div class="space-y-4">
                        @foreach($processingRunViews as $processingRunView)
                            @include('livewire.admin.church-services.partials.processing-run-card', [
                                'processingRunView' => $processingRunView,
                            ])
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-card>
                <div class="space-y-4 text-sm">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Service</p>
                        <div class="space-y-2">
                            <div>
                                <p class="text-gray-500">Date</p>
                                <p class="font-medium">{{ $churchService->date->format('l j F Y') }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Type</p>
                                <p class="font-medium">{{ $churchService->service->label() }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Source</p>
                                <p class="font-medium uppercase">{{ $churchService->source }}</p>
                            </div>
                            @if($churchService->original_filename)
                                <div>
                                    <p class="text-gray-500">Original filename</p>
                                    <p class="font-medium break-all">{{ $churchService->original_filename }}</p>
                                </div>
                            @endif
                            <div>
                                <p class="text-gray-500">Imported</p>
                                <p class="font-medium">{{ $churchService->updated_at?->format('j M Y g:ia') }}</p>
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
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Import</p>
                        <div class="space-y-2">
                            <div>
                                <p class="text-gray-500">Parse method</p>
                                <p class="font-medium">{{ $importMetadata['parse_method'] ?? 'unknown' }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Confidence</p>
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
</x-admin.page>
