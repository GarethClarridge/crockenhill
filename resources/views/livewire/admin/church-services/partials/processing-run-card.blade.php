@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */

    $run = $processingRunView->run;
@endphp

<div class="rounded-lg border border-gray-200 p-4" id="processing-run-{{ $run->id }}" wire:key="processing-run-{{ $run->id }}">
    @include('livewire.admin.church-services.partials.processing-run-header', [
        'processingRunView' => $processingRunView,
    ])

    @if($processingRunView->hasProcessingTimeline())
        @include('livewire.admin.church-services.partials.processing-step-timeline', [
            'processingTimeline' => $processingRunView->processingTimeline,
        ])
    @endif

    @if($processingRunView->needsSermonReview && isset($segmentConfirmations[$run->id]))
        <div class="mt-3">
            <p class="mb-2 flex items-center gap-1.5 text-sm font-medium text-gray-900">
                <x-heroicon-o-check-circle class="h-4 w-4 text-amber-500" aria-hidden="true" />
                Confirm the sermon segment
            </p>
            @include('livewire.admin.church-services.partials.segment-confirmation', [
                'segments' => $segmentConfirmations[$run->id]['segments'],
                'confirmedSegmentId' => $segmentConfirmations[$run->id]['confirmed_segment_id'],
                'requiresReview' => true,
                'sourceAvailable' => $segmentConfirmations[$run->id]['source_available'],
                'confirming' => false,
                'confirmCall' => fn ($segment): string => "confirmRunSegment({$run->id}, {$segment->id})",
                'returnLink' => null,
            ])
        </div>
    @endif

    @if($processingRunView->isWaitingForSections())
        <p class="text-sm text-gray-500">Sections not yet available — processing is still in progress.</p>
    @elseif($processingRunView->isFailedWithoutSections())
        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-3" role="status">
            <p class="text-sm font-medium text-rose-900">
                Run failed {{ $run->updated_at?->diffForHumans() ?? 'previously' }} — no sections were produced
            </p>
        </div>
    @elseif(! $processingRunView->hasClassifiedSections())
        <p class="text-sm text-gray-500">No classified sections available for this run yet.</p>
    @else
        @include('livewire.admin.church-services.partials.service-flow', [
            'serviceFlow' => $processingRunView->serviceFlow,
        ])

        @include('livewire.admin.church-services.partials.timeline-alignment-table', [
            'serviceTimeline' => $processingRunView->serviceTimeline,
        ])

        @include('livewire.admin.church-services.partials.processing-run-review-actions', [
            'processingRunView' => $processingRunView,
        ])
    @endif
</div>
