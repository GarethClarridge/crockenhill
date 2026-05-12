@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */

    $run = $processingRunView->run;
@endphp

<div class="rounded-lg border border-gray-200 p-4" wire:key="processing-run-{{ $run->id }}">
    @include('livewire.admin.church-services.partials.processing-run-header', [
        'processingRunView' => $processingRunView,
    ])

    @if($processingRunView->hasProcessingTimeline())
        @include('livewire.admin.church-services.partials.processing-step-timeline', [
            'processingTimeline' => $processingRunView->processingTimeline,
        ])
    @endif

    @if($processingRunView->isWaitingForSections())
        <p class="text-sm text-gray-500">Sections not yet available — processing is still in progress.</p>
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
