@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */

    $run = $processingRunView->run;
@endphp

@if($processingRunView->hasReviewActions())
    <div class="mt-3 flex flex-wrap gap-2">
        @if($processingRunView->needsSermonReview)
            <x-button
                link="{{ route('admin.services.processing.review', $run) }}"
                variant="outline"
                size="xs"
                icon="check-circle"
                inline
            >
                Confirm sermon segment
            </x-button>
        @endif

        @if($processingRunView->needsSectionReview)
            <x-button
                link="{{ route('admin.services.review') }}"
                variant="outline"
                size="xs"
                icon="exclamation-triangle"
                inline
            >
                Review sections
            </x-button>
        @endif

        @if($processingRunView->hasPendingPublications)
            <x-button
                link="{{ route('admin.services.section-publications') }}"
                variant="outline"
                size="xs"
                icon="queue-list"
                inline
            >
                Publication queue
            </x-button>
        @endif
    </div>
@endif
