<x-admin.page title="Review sermon processing" :description="'Select the correct sermon segment to resume processing for this '.strtolower($runLabel)">
    <x-slot:actions>
        <x-button link="{{ route('admin.services.processing.review.index') }}" variant="outline" inline wire:navigate>
            Back to queue
        </x-button>
    </x-slot:actions>

    {{-- Processing run details --}}
    <x-card heading="Processing run">
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">File</dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 break-all">{{ $log->original_filename }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Processing ID</dt>
                <dd class="mt-1 text-sm font-mono text-gray-700">{{ $log->processing_id }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Service Date</dt>
                <dd class="mt-1 text-sm text-gray-900">
                    @if($log->extracted_date)
                        {{ $log->extracted_date->format('j M Y') }}
                        @if($log->extracted_service)
                            &mdash;
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ match($log->extracted_service) {
                                \App\Enums\SermonService::Morning => 'bg-green-100 text-green-800',
                                \App\Enums\SermonService::Evening => 'bg-amber-100 text-amber-800',
                                default => 'bg-gray-100 text-gray-800',
                            } }}">
                                {{ $log->extracted_service->label() }}
                            </span>
                        @endif
                    @else
                        <span class="text-gray-400">Unknown</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Run Type</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $runLabel }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Status</dt>
                <dd class="mt-1">
                    @if($requiresReview)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                            Awaiting manual review
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                            Confirmed — resuming
                        </span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Flagged at</dt>
                <dd class="mt-1 text-sm text-gray-900">
                    @if(isset($reviewMeta['flagged_at']))
                        {{ \Illuminate\Support\Carbon::parse($reviewMeta['flagged_at'])->format('j M Y H:i') }}
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Source video</dt>
                <dd class="mt-1">
                    @if($sourceAvailable)
                        <span class="inline-flex items-center gap-1 text-sm text-green-700">
                            <x-heroicon-o-check-circle class="w-4 h-4" aria-hidden="true" />
                            Available
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-sm text-rose-700">
                            <x-heroicon-o-x-circle class="w-4 h-4" aria-hidden="true" />
                            Not available
                        </span>
                    @endif
                </dd>
            </div>
        </dl>

        @if(isset($reviewMeta['reason_message']) && $reviewMeta['reason_message'] !== '')
            <x-alert type="warning" title="Why manual review is required" class="mt-4">
                {{ $reviewMeta['reason_message'] }}
            </x-alert>
        @endif

        @if(! $sourceAvailable)
            <x-alert type="error" title="Source video unavailable" class="mt-4">
                The original source video file is no longer accessible. This run cannot be resumed from manual review.
                The file may have been deleted or moved after processing was paused.
            </x-alert>
        @endif
    </x-card>

    {{-- Segment timeline --}}
    <x-card heading="Detected Segments">
        @include('livewire.admin.church-services.partials.segment-confirmation', [
            'segments' => $segments,
            'confirmedSegmentId' => $confirmedSegmentId,
            'requiresReview' => $requiresReview,
            'sourceAvailable' => $sourceAvailable,
            'confirming' => $confirming,
            'confirmCall' => fn ($segment): string => "confirmSegment({$segment->id})",
            'returnLink' => [
                'href' => route('admin.services.processing.review.index'),
                'label' => 'Return to queue',
            ],
        ])
    </x-card>
</x-admin.page>
