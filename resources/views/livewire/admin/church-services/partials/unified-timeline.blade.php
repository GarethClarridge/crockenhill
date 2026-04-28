@php
    use App\Enums\ServiceSectionPublicationStatus;

    $hasSections = $run->serviceSections->isNotEmpty();
    $isInProgress = $run->status->isInProgress();
@endphp

<div class="rounded-lg border border-gray-200 p-4" wire:key="processing-run-{{ $run->id }}">

    {{-- Run header --}}
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm font-medium">Run {{ $run->processing_id }}</p>
            <p class="text-xs text-gray-500">
                Status: {{ $run->status->label() }} · Updated {{ $run->updated_at?->diffForHumans() ?? 'Unknown' }}
            </p>
        </div>

        <x-form-button
            type="button"
            variant="outline"
            size="sm"
            icon="arrow-path"
            wire:click="reclassify({{ $run->id }})"
            wire:target="reclassify({{ $run->id }})"
        >
            Reclassify
        </x-form-button>

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

    {{-- Collapsible processing step timeline --}}
    @if($processingTimeline !== [])
        @include('livewire.admin.church-services.partials.processing-step-timeline', [
            'processingTimeline' => $processingTimeline,
        ])
    @endif

    {{-- Service section display --}}
    @if($isInProgress && ! $hasSections)
        <p class="text-sm text-gray-500">Sections not yet available — processing is still in progress.</p>
    @elseif($serviceTimeline === [])
        <p class="text-sm text-gray-500">No classified sections available for this run yet.</p>
    @else
        {{-- Primary: human-readable service flow --}}
        @include('livewire.admin.church-services.partials.service-flow', [
            'serviceFlow' => $serviceFlow,
        ])

        {{-- Secondary: detailed alignment table (collapsed by default) --}}
        @include('livewire.admin.church-services.partials.timeline-alignment-table', [
            'serviceTimeline' => $serviceTimeline,
            'run' => $run,
        ])

        {{-- Action links to review dashboards where needed --}}
        @php
            $needsSermonReview = $run->requiresManualSermonReview();
            $needsSectionReview = $run->serviceSections->contains('needs_manual_review', true);
            $hasPendingPublications = $run->serviceSections->contains(
                fn ($s) => $s->publication_status === ServiceSectionPublicationStatus::PENDING_APPROVAL
                    || $s->publication_status === ServiceSectionPublicationStatus::APPROVED
            );
        @endphp

        @if($needsSermonReview || $needsSectionReview || $hasPendingPublications)
            <div class="mt-3 flex flex-wrap gap-2">
                @if($needsSermonReview)
                    <a href="{{ route('admin.services.processing.review', $run) }}"
                       class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100 no-underline"
                       wire:navigate>
                        Confirm sermon segment
                    </a>
                @endif
                @if($needsSectionReview)
                    <a href="{{ route('admin.services.review') }}"
                       class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100 no-underline"
                       wire:navigate>
                        Review sections
                    </a>
                @endif
                @if($hasPendingPublications)
                    <a href="{{ route('admin.services.section-publications') }}"
                       class="inline-flex items-center rounded-md border border-sky-300 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-800 hover:bg-sky-100 no-underline"
                       wire:navigate>
                        Publication queue
                    </a>
                @endif
            </div>
        @endif
    @endif
</div>
