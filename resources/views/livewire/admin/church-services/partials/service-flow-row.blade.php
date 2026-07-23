@php
    use App\Enums\ServiceSectionPublicationStatus;
    use App\Enums\SermonContentType;

    $reviewPanel = ($item['section_id'] ?? null) !== null
        ? (($sectionReviewPanels ?? [])[$item['section_id']] ?? null)
        : null;
    $mergeSecondaryId = ($item['section_id'] ?? null) !== null
        ? (($mergeCandidatePairs ?? [])[$item['section_id']] ?? null)
        : null;
    $reviewReasons = is_array($reviewPanel['reasons'] ?? null) ? $reviewPanel['reasons'] : [];
    $hasRequeueAction = ($sectionPublishingEnabled ?? false)
        && ($item['section_id'] ?? null) !== null
        && $item['publication_status'] === ServiceSectionPublicationStatus::Rejected;
    $hasDetails = $reviewPanel !== null
        || $hasRequeueAction
        || $mergeSecondaryId !== null
        || ($item['mismatch_reason'] ?? null) !== null;
    $detailsId = "service-row-details-{$rowIndex}";
    $summary = trim((string) ($item['description'] ?? ''));
    $excerpt = trim((string) ($item['transcript_excerpt'] ?? ''));
    $showExcerpt = $excerpt !== '' && strcasecmp($excerpt, $summary) !== 0;
    $rowClasses = $item['row_type'] === 'mismatched'
        ? 'border-l-4 border-l-amber-400 bg-amber-50/50'
        : 'border-l-4 border-l-transparent';
@endphp

<li
    class="px-1 py-4 {{ $rowClasses }}"
    @if($hasDetails) x-data="{ expanded: {{ $reviewPanel !== null ? 'true' : 'false' }} }" @endif
    wire:key="service-record-row-{{ $rowIndex }}"
>
    @if($hasDetails)
        <button
            type="button"
            class="min-h-11 w-full rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
            x-on:click="expanded = ! expanded"
            :aria-expanded="expanded.toString()"
            aria-controls="{{ $detailsId }}"
        >
            @include('livewire.admin.church-services.partials.service-record-row-summary')
        </button>
    @else
        <div>
            @include('livewire.admin.church-services.partials.service-record-row-summary')
        </div>
    @endif

    @if($summary !== '')
        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600 sm:ml-28">{{ $summary }}</p>
    @endif

    @if($showExcerpt)
        <blockquote class="mt-2 max-w-3xl border-l-2 border-gray-200 pl-3 text-sm italic leading-relaxed text-gray-600 sm:ml-28">
            “{{ $excerpt }}”
        </blockquote>
    @endif

    @if($hasDetails)
        <div id="{{ $detailsId }}" x-show="expanded" x-collapse class="mt-3 space-y-3 sm:ml-28">
            @if($item['mismatch_reason'])
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <p class="font-medium">Plan and recording differ</p>
                    <p>{{ str($item['mismatch_reason'])->replace('_', ' ')->ucfirst() }}</p>
                </div>
            @endif

            @if($reviewPanel !== null)
                @include('livewire.admin.church-services.partials.section-review-panel', [
                    'panel' => $reviewPanel,
                ])
            @endif

            @if($reviewPanel === null && $hasRequeueAction)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                    <p class="text-xs text-gray-600">This section was rejected. Requeue it to send it back for approval.</p>
                    <x-form-button type="button" size="xs" variant="outline" wire:click="requeue({{ $item['section_id'] }})">
                        Requeue
                    </x-form-button>
                </div>
            @endif

            @if($mergeSecondaryId !== null)
                @php $pendingSectionMergePair = $pendingSectionMerge ?? null; @endphp
                @if($pendingSectionMergePair !== null
                    && $pendingSectionMergePair['primary_id'] === $item['section_id']
                    && $pendingSectionMergePair['secondary_id'] === $mergeSecondaryId)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                        <p class="font-medium text-amber-900">Merge these two {{ $item['type_label'] }} sections into one?</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-form-button variant="primary" size="sm" wire:click="confirmMerge">Confirm merge</x-form-button>
                            <x-form-button variant="outline" size="sm" wire:click="cancelMerge">Cancel</x-form-button>
                        </div>
                    </div>
                @else
                    <x-form-button variant="ghost" size="sm" wire:click="initiateMerge({{ $item['section_id'] }}, {{ $mergeSecondaryId }})">
                        Merge with the next {{ $item['type_label'] }} section
                    </x-form-button>
                @endif
            @endif
        </div>
    @endif
</li>
