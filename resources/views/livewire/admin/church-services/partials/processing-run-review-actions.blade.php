@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */

    $run = $processingRunView->run;
@endphp

@if($processingRunView->hasReviewActions())
    <div class="mt-3 flex flex-wrap gap-2">
        @if($processingRunView->needsSermonReview)
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                <x-heroicon-o-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                Pick the correct sermon segment below
            </span>
        @endif

        @if($processingRunView->needsSectionReview)
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                Flagged sections are expanded in the timeline below
            </span>
        @endif

    </div>
@endif
