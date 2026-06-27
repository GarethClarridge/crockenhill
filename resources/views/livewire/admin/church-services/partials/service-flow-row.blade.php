@php
    use App\Enums\ServiceSectionPublicationStatus;
    use App\Enums\ServiceSectionSongMatchType;
    use App\Enums\ServiceSectionType;
    use App\Enums\SermonContentType;
    use App\Support\ServiceRecordTimeline;

    /** @var array<string, mixed> $item */
    /** @var int $rowIndex */

    $rowBorder = match($item['row_type']) {
        'mismatched'   => 'border-l-4 border-l-amber-400',
        'unplanned'    => 'border-l-4 border-l-rose-400',
        'planned_only' => 'border-l-4 border-l-slate-300',
        default        => 'border-l-4 border-l-transparent',
    };

    $rowBg = match($item['row_type']) {
        'mismatched'   => 'bg-amber-50/50',
        'planned_only' => 'bg-slate-50/70',
        default        => '',
    };

    $reviewPanel = ($item['section_id'] ?? null) !== null
        ? (($sectionReviewPanels ?? [])[$item['section_id']] ?? null)
        : null;
    $mergeSecondaryId = ($item['section_id'] ?? null) !== null
        ? (($mergeCandidatePairs ?? [])[$item['section_id']] ?? null)
        : null;
@endphp

<li
    class="py-3 px-1 {{ $rowBorder }} {{ $rowBg }}"
    x-data="{ expanded: {{ $reviewPanel !== null ? 'true' : 'false' }} }"
    wire:key="flow-item-{{ $rowIndex }}"
>
    <button
        type="button"
        class="flex w-full items-start gap-3 text-left"
        x-on:click="expanded = !expanded"
        :aria-expanded="expanded.toString()"
        aria-controls="service-row-details-{{ $rowIndex }}"
    >
        <div class="w-24 shrink-0 text-right">
            @if($item['start_time'] !== null && $item['end_time'] !== null)
                <span class="font-mono text-xs text-gray-500">
                    {{ ServiceRecordTimeline::formatTimestamp($item['start_time']) }}
                </span>
                <br>
                <span class="font-mono text-xs text-gray-400">
                    –&thinsp;{{ ServiceRecordTimeline::formatTimestamp($item['end_time']) }}
                </span>
            @else
                <span class="text-xs text-gray-400">—</span>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                @if($item['icon'] !== '')
                    <span class="text-sm" aria-hidden="true">{{ $item['icon'] }}</span>
                @endif

                <span class="text-sm font-semibold text-gray-900">
                    {{ $item['type_label'] }}
                    @if($item['title_suffix'])
                        &mdash; <span class="font-normal">{{ $item['title_suffix'] }}</span>
                    @endif
                </span>

                @if($item['duration_formatted'])
                    <span class="text-xs text-gray-400">{{ $item['duration_formatted'] }}</span>
                @endif

                @if($item['needs_review'])
                    <span
                        class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                        @if($item['review_reason']) title="{{ str_replace('_', ' ', $item['review_reason']) }}" @endif
                    >
                        <span aria-hidden="true">⚠</span> Review
                        @if($item['review_reason'])
                            <span class="sr-only">: {{ str_replace('_', ' ', $item['review_reason']) }}</span>
                        @endif
                    </span>
                @endif

                @if($item['row_type'] === 'mismatched')
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                        Mismatch
                    </span>
                @endif

                @if($item['row_type'] === 'unplanned')
                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700">
                        Not in plan
                    </span>
                @endif

                @if($item['row_type'] === 'planned_only')
                    <span class="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">
                        Not detected
                    </span>
                @endif

                @if($item['confidence_level'] === 'low')
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-400" title="Low confidence">
                        <span class="sr-only">Low confidence</span>
                    </span>
                @elseif($item['confidence_level'] === 'none')
                    <span class="inline-block h-2 w-2 rounded-full bg-rose-400" title="No confidence">
                        <span class="sr-only">No confidence</span>
                    </span>
                @endif

                @if($item['publication_status'] instanceof ServiceSectionPublicationStatus && $item['publication_status'] !== ServiceSectionPublicationStatus::PendingApproval)
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($item['publication_status']) {
                        ServiceSectionPublicationStatus::Approved => 'bg-sky-100 text-sky-800',
                        ServiceSectionPublicationStatus::Rejected => 'bg-rose-100 text-rose-800',
                        ServiceSectionPublicationStatus::Published => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                        default => 'bg-gray-100 text-gray-700',
                    } }}">
                        {{ $item['publication_status']->label() }}
                    </span>
                @elseif($item['publication_status'] === ServiceSectionPublicationStatus::PendingApproval)
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                        Pending approval
                    </span>
                @endif

                @if($item['type'] === ServiceSectionType::Song && $item['song_match_type'] instanceof ServiceSectionSongMatchType)
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $item['song_match_type'] === ServiceSectionSongMatchType::Confirmed ? 'bg-cbc-teal-light/15 text-cbc-teal-dark' : 'bg-amber-100 text-amber-700' }}">
                        {{ $item['song_match_type']->label() }}
                    </span>
                @endif
            </div>

            @if($item['description'] !== '')
                <p class="mt-1 text-xs text-gray-500 leading-relaxed">{{ $item['description'] }}</p>
            @endif

            @if($item['planned_context'] && $item['row_type'] !== 'matched')
                <p class="mt-1 text-xs {{ $item['row_type'] === 'mismatched' ? 'text-amber-700' : 'text-gray-400' }}">
                    {{ $item['planned_context'] }}
                </p>
            @endif

            @if($item['publication_status'] === ServiceSectionPublicationStatus::Published && $item['published_sermon'])
                @php
                    $publishedSermonUrl = $item['published_sermon']->content_type === SermonContentType::ChildrensTalk
                        ? route('childrens-corner.show', ['sermon' => $item['published_sermon']->slug])
                        : route('sermons.show', ['sermon' => $item['published_sermon']->slug]);
                @endphp
                <a href="{{ $publishedSermonUrl }}"
                   class="mt-1 inline-block text-xs text-cbc-teal hover:text-cbc-teal-dark no-underline"
                   target="_blank">
                    View {{ strtolower($item['published_sermon']->content_type->label()) }} →
                </a>
            @endif
        </div>

        <div class="shrink-0 text-gray-400" aria-hidden="true">
            <svg x-show="!expanded" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
            <svg x-show="expanded" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </div>
    </button>

    <div
        id="service-row-details-{{ $rowIndex }}"
        x-show="expanded"
        x-collapse
        class="mt-3 ml-27 pl-1"
    >
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700 space-y-2">
            @if($item['transcript_excerpt'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Transcript excerpt</p>
                    <p class="italic text-gray-600">"{{ $item['transcript_excerpt'] }}"</p>
                </div>
            @endif

            @if($item['planned_context'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Order of Service</p>
                    <p>{{ $item['planned_context'] }}</p>
                </div>
            @endif

            @if($item['mismatch_reason'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Mismatch reason</p>
                    <p class="text-amber-700">{{ str_replace('_', ' ', $item['mismatch_reason']) }}</p>
                </div>
            @endif

            @if($item['review_reason'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Review reason</p>
                    <p class="text-amber-700">{{ str_replace('_', ' ', $item['review_reason']) }}</p>
                </div>
            @endif

            @if($item['confidence_level'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Confidence</p>
                    <p>{{ ucfirst($item['confidence_level']) }}</p>
                </div>
            @endif

            @if($item['section_id'])
                <div>
                    <p class="font-medium text-gray-500 uppercase tracking-wide mb-1">Section ID</p>
                    <p class="font-mono">{{ $item['section_id'] }}</p>
                </div>
            @endif
        </div>

        @if($reviewPanel !== null)
            <div class="mt-3">
                @include('livewire.admin.church-services.partials.section-review-panel', [
                    'panel' => $reviewPanel,
                ])
            </div>
        @endif

        {{-- A rejected section with no other review flags gets no panel (rejection is a
             decision already made, not a review reason), but it still needs its only
             path back into the approval queue --}}
        @if($reviewPanel === null
            && ($sectionPublishingEnabled ?? false)
            && ($item['section_id'] ?? null) !== null
            && $item['publication_status'] === ServiceSectionPublicationStatus::Rejected)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                <p class="text-xs text-gray-600">This section was rejected. Requeue it to send it back for approval.</p>
                <x-form-button
                    type="button"
                    size="xs"
                    variant="outline"
                    wire:click="requeue({{ $item['section_id'] }})"
                    wire:target="requeue({{ $item['section_id'] }})"
                >
                    Requeue
                </x-form-button>
            </div>
        @endif
    </div>

    @if($mergeSecondaryId !== null)
        @php $pendingSectionMergePair = $pendingSectionMerge ?? null; @endphp
        @if($pendingSectionMergePair !== null
            && $pendingSectionMergePair['primary_id'] === $item['section_id']
            && $pendingSectionMergePair['secondary_id'] === $mergeSecondaryId)
            <div class="mt-3 ml-27 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                <p class="font-medium text-amber-900">Merge these two {{ $item['type_label'] }} sections into one?</p>
                <p class="mt-1 text-amber-700">
                    Any extracted media will be cleared and re-extracted automatically if the source recording is still available.
                </p>
                <div class="mt-3 flex gap-2">
                    <x-form-button variant="primary" size="sm" wire:click="confirmMerge"
                        wire:target="confirmMerge" wire:loading.attr="disabled">
                        Confirm merge
                    </x-form-button>
                    <x-form-button variant="outline" size="sm" wire:click="cancelMerge">
                        Cancel
                    </x-form-button>
                </div>
            </div>
        @else
            <div class="mt-2 ml-27 flex">
                <x-form-button
                    variant="ghost"
                    size="xs"
                    wire:click="initiateMerge({{ $item['section_id'] }}, {{ $mergeSecondaryId }})"
                >
                    ⇕ Merge with the next {{ $item['type_label'] }} section
                </x-form-button>
            </div>
        @endif
    @endif
</li>
