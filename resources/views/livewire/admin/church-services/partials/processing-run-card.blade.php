@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */
    $run = $processingRunView->run;
@endphp

<div id="processing-run-{{ $run->id }}" wire:key="processing-run-{{ $run->id }}" class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-900">
                Recording uploaded {{ $run->created_at?->format('j F Y \a\t H:i') ?? 'at an unknown time' }}
            </p>
            <p class="mt-1 text-sm {{ $run->isFailed() ? 'text-rose-700' : 'text-gray-500' }}">
                {{ $run->status->label() }}
                @if($run->isFailed() && $run->error_message)
                    · {{ $run->error_message }}
                @elseif($run->original_filename)
                    · {{ $run->original_filename }}
                @endif
            </p>
        </div>
    </div>

    @if($processingRunView->needsSermonReview && isset($segmentConfirmations[$run->id]))
        <div>
            <p class="mb-2 text-sm font-medium text-gray-900">Confirm the sermon segment</p>
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
        <p class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status">
            Sections are not available yet. Processing is still in progress.
        </p>
    @elseif($processingRunView->isFailedWithoutSections())
        <p class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
            No sections were produced from this upload.
        </p>
        @include('livewire.admin.church-services.partials.service-flow', [
            'serviceFlow' => $processingRunView->serviceFlow,
        ])
    @elseif(! $processingRunView->hasClassifiedSections())
        <p class="text-sm text-gray-500">No classified sections are available for this upload.</p>
        @include('livewire.admin.church-services.partials.service-flow', [
            'serviceFlow' => $processingRunView->serviceFlow,
        ])
    @else
        @include('livewire.admin.church-services.partials.service-flow', [
            'serviceFlow' => $processingRunView->serviceFlow,
        ])

        @include('livewire.admin.church-services.partials.processing-run-review-actions', [
            'processingRunView' => $processingRunView,
        ])
    @endif

    <details class="rounded-lg border border-gray-200 bg-gray-50">
        <summary class="min-h-11 cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal">
            Technical processing details
        </summary>
        <div class="space-y-4 border-t border-gray-200 p-4">
            <dl class="grid gap-3 text-xs sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500">Run ID</dt>
                    <dd class="break-all font-mono text-gray-800">{{ $run->processing_id }}</dd>
                </div>
                @if($run->original_filename)
                    <div>
                        <dt class="text-gray-500">Source filename</dt>
                        <dd class="break-all text-gray-800">{{ $run->original_filename }}</dd>
                    </div>
                @endif
            </dl>

            @if($processingRunView->hasProcessingTimeline())
                @include('livewire.admin.church-services.partials.processing-step-timeline', [
                    'processingTimeline' => $processingRunView->processingTimeline,
                ])
            @endif

            <div class="border-t border-gray-200 pt-4">
                <x-form-button
                    type="button"
                    variant="danger"
                    size="sm"
                    icon="trash"
                    wire:click="deleteUpload({{ $run->id }})"
                    wire:target="deleteUpload({{ $run->id }})"
                    wire:confirm="Delete this livestream upload? This will remove the processing run, projected service items, and any sermons or assets created from it."
                >
                    Delete upload
                </x-form-button>
            </div>
        </div>
    </details>
</div>
