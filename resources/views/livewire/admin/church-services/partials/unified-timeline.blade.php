@php
    use App\Enums\ServiceSectionPublicationStatus;
    use App\Enums\ServiceSectionSongMatchType;
    use App\Support\ServiceRecordTimeline;

    $hasSections = $processingRun->serviceSections->isNotEmpty();
    $isInProgress = $processingRun->status->isInProgress();
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
    </div>

    {{-- Collapsible processing timeline --}}
    @if($processingTimeline !== [])
        <details class="mb-4">
            <summary class="cursor-pointer rounded-lg border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm font-semibold text-gray-900 select-none hover:bg-slate-100">
                Processing Timeline
            </summary>
            <div class="mt-1 rounded-lg border border-slate-200 bg-slate-50/80 p-4">
                <ol class="space-y-3">
                    @foreach($processingTimeline as $timelineStep)
                        <li class="rounded-lg border border-white bg-white p-3 shadow-sm">
                            <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-gray-900">{{ $timelineStep['label'] }}</p>
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($timelineStep['status']) {
                                            'completed' => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                                            'running' => 'bg-sky-100 text-sky-800',
                                            'failed' => 'bg-rose-100 text-rose-800',
                                            'skipped' => 'bg-amber-100 text-amber-800',
                                            'not_recorded' => 'bg-slate-200 text-slate-700',
                                            default => 'bg-gray-100 text-gray-700',
                                        } }}">
                                            {{ match($timelineStep['status']) {
                                                'running' => 'Running',
                                                'failed' => 'Failed',
                                                'skipped' => 'Skipped',
                                                'not_recorded' => 'Not recorded',
                                                'pending' => 'Pending',
                                                default => 'Completed',
                                            } }}
                                        </span>
                                    </div>

                                    @if($timelineStep['message'])
                                        <p class="text-xs {{ $timelineStep['status'] === 'failed' ? 'text-rose-700' : 'text-gray-600' }}">
                                            {{ $timelineStep['message'] }}
                                        </p>
                                    @endif
                                </div>

                                <dl class="grid grid-cols-1 gap-3 text-xs text-gray-600 sm:grid-cols-3 xl:min-w-[25rem]">
                                    <div>
                                        <dt class="font-medium uppercase tracking-wide text-gray-500">Started</dt>
                                        <dd class="mt-1">{{ $timelineStep['started_at']?->format('j M Y H:i:s') ?? 'Not recorded' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium uppercase tracking-wide text-gray-500">Completed</dt>
                                        <dd class="mt-1">{{ $timelineStep['completed_at']?->format('j M Y H:i:s') ?? 'Not recorded' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium uppercase tracking-wide text-gray-500">Duration</dt>
                                        <dd class="mt-1">{{ $timelineStep['duration'] ?? 'Not recorded' }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </details>
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
        <details class="mt-4">
            <summary class="cursor-pointer text-sm text-gray-500 select-none hover:text-gray-700">
                Show detailed alignment table
            </summary>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Planned</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Detected</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Timing</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Publication</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach($serviceTimeline as $row)
                        @php
                            $rowBg = match($row['row_type']) {
                                'mismatched' => 'bg-amber-50',
                                'unplanned'  => 'bg-rose-50',
                                'planned_only' => 'bg-slate-50',
                                default => '',
                            };
                        @endphp
                        <tr class="{{ $rowBg }}" wire:key="table-row-{{ $loop->index }}">

                            {{-- # — prefer section_order (livestream position) when available, fall back to planned position --}}
                            <td class="px-3 py-2 text-sm font-medium text-gray-700">
                                {{ $row['section_order'] ?? $row['position'] ?? '—' }}
                            </td>

                            {{-- Type --}}
                            <td class="px-3 py-2">
                                @if($row['section_type'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ $row['section_type']->label() }}
                                    </span>
                                @elseif($row['item_type'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600">
                                        {{ ucfirst($row['item_type']) }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Planned --}}
                            <td class="px-3 py-2 text-sm">
                                @if($row['row_type'] === 'unplanned')
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700">
                                        Not in plan
                                    </span>
                                @elseif($row['planned_title'])
                                    <p class="font-medium text-gray-900">{{ $row['planned_title'] }}</p>
                                    @if($row['planned_item_state'] === 'soft_deleted')
                                        <span class="mt-0.5 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                            Archived from plan
                                        </span>
                                    @elseif($row['planned_item_state'] === 'metadata_only')
                                        <span class="mt-0.5 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            Historical expected item
                                        </span>
                                    @endif
                                    @if($row['song_title'] && $row['song_id'])
                                        <p class="mt-0.5 text-xs text-gray-500">Song: {{ $row['song_title'] }}</p>
                                    @endif
                                    @if($row['song_match_type'] instanceof ServiceSectionSongMatchType)
                                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $row['song_match_type'] === ServiceSectionSongMatchType::CONFIRMED ? 'bg-cbc-teal-light/15 text-cbc-teal-dark' : 'bg-amber-100 text-amber-700' }}">
                                            {{ $row['song_match_type']->label() }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Source badge --}}
                            <td class="px-3 py-2">
                                @if($row['item_source'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($row['item_source']->value) {
                                        'email' => 'bg-blue-100 text-blue-700',
                                        'openlp' => 'bg-green-100 text-green-700',
                                        'manual' => 'bg-purple-100 text-purple-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    } }}">
                                        {{ strtoupper($row['item_source']->value) }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Detected --}}
                            <td class="px-3 py-2 text-sm">
                                @if($row['row_type'] === 'planned_only')
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                        Not detected
                                    </span>
                                @elseif($row['section_title'])
                                    <p class="font-medium text-gray-900">{{ $row['section_title'] }}</p>
                                @elseif($row['section_type'])
                                    <p class="text-gray-500">{{ $row['section_type']->label() }}</p>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Timing --}}
                            <td class="px-3 py-2 text-xs text-gray-600">
                                @if($row['start_time'] !== null && $row['end_time'] !== null)
                                    {{ ServiceRecordTimeline::formatTimestamp($row['start_time']) }}
                                    –
                                    {{ ServiceRecordTimeline::formatTimestamp($row['end_time']) }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-3 py-2 text-sm">
                                @if($row['row_type'] === 'matched' && ! $row['needs_review'])
                                    <span class="inline-flex items-center rounded-full bg-cbc-teal-light/15 px-2.5 py-0.5 text-xs font-medium text-cbc-teal-dark">
                                        Aligned
                                    </span>
                                @elseif($row['row_type'] === 'mismatched')
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                        Mismatch
                                    </span>
                                    @if($row['mismatch_reason'])
                                        <p class="mt-1 text-xs text-amber-700">{{ str_replace('_', ' ', $row['mismatch_reason']) }}</p>
                                    @endif
                                    @if($row['expected_section_type'] && $row['section_type'] && $row['expected_section_type'] !== $row['section_type'])
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            Expected {{ $row['expected_section_type']->label() }}, detected {{ $row['section_type']->label() }}
                                        </p>
                                    @endif
                                @elseif($row['row_type'] === 'unplanned')
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">
                                        Unplanned
                                    </span>
                                @elseif($row['row_type'] === 'planned_only')
                                    <span class="inline-flex items-center rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                        Not detected
                                    </span>
                                @endif

                                @if($row['needs_review'] && $row['row_type'] !== 'mismatched')
                                    <span class="mt-1 inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                        Needs review
                                    </span>
                                    @if($row['review_reason'])
                                        <p class="mt-0.5 text-xs text-gray-500">{{ str_replace('_', ' ', $row['review_reason']) }}</p>
                                    @endif
                                @endif
                            </td>

                            {{-- Publication --}}
                            <td class="px-3 py-2 text-sm">
                                @if($row['publication_status'] instanceof ServiceSectionPublicationStatus)
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($row['publication_status']) {
                                        ServiceSectionPublicationStatus::PENDING_APPROVAL => 'bg-amber-100 text-amber-800',
                                        ServiceSectionPublicationStatus::APPROVED => 'bg-sky-100 text-sky-800',
                                        ServiceSectionPublicationStatus::REJECTED => 'bg-rose-100 text-rose-800',
                                        ServiceSectionPublicationStatus::PUBLISHED => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                                        default => 'bg-gray-100 text-gray-700',
                                    } }}">
                                        {{ $row['publication_status']->label() }}
                                    </span>
                                    @if($row['publication_status'] === ServiceSectionPublicationStatus::PUBLISHED && $row['published_sermon'])
                                        @php
                                            $publishedSermonUrl = $row['published_sermon']->content_type === \App\Enums\SermonContentType::ChildrensTalk
                                                ? route('childrens-corner.show', ['sermon' => $row['published_sermon']->slug])
                                                : route('sermons.show', ['sermon' => $row['published_sermon']->slug]);
                                        @endphp
                                        <p class="mt-1 text-xs">
                                            <a href="{{ $publishedSermonUrl }}" class="text-cbc-teal hover:text-cbc-teal-dark">
                                                View {{ strtolower($row['published_sermon']->content_type->label()) }}
                                            </a>
                                        </p>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        {{-- Action links --}}
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
