@php
    use App\Enums\ServiceSectionPublicationStatus;
    use App\Enums\ServiceSectionSongMatchType;
    use App\Enums\SermonContentType;
    use App\Services\ChurchService\ServiceRecordTimeline;

    /** @var array<string, mixed> $row */
    /** @var int $rowIndex */

    $rowBg = match($row['row_type']) {
        'mismatched' => 'bg-amber-50',
        'unplanned' => 'bg-rose-50',
        'planned_only' => 'bg-slate-50',
        default => '',
    };
@endphp

<tr class="{{ $rowBg }}" wire:key="table-row-{{ $rowIndex }}">
    <td class="px-3 py-2 text-sm font-medium text-gray-700">
        {{ $row['section_order'] ?? $row['position'] ?? '—' }}
    </td>

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
                <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $row['song_match_type'] === ServiceSectionSongMatchType::Confirmed ? 'bg-cbc-teal-light/15 text-cbc-teal-dark' : 'bg-amber-100 text-amber-700' }}">
                    {{ $row['song_match_type']->label() }}
                </span>
            @endif
        @else
            <span class="text-xs text-gray-400">—</span>
        @endif
    </td>

    <td class="px-3 py-2">
        @if($row['item_source'])
            {{-- Source is inert provenance metadata — neutral, so colour keeps its meaning --}}
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                {{ strtoupper($row['item_source']->value) }}
            </span>
        @else
            <span class="text-xs text-gray-400">—</span>
        @endif
    </td>

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

    <td class="px-3 py-2 text-xs text-gray-600">
        @if($row['start_time'] !== null && $row['end_time'] !== null)
            {{ ServiceRecordTimeline::formatTimestamp($row['start_time']) }}
            –
            {{ ServiceRecordTimeline::formatTimestamp($row['end_time']) }}
        @else
            <span class="text-gray-400">—</span>
        @endif
    </td>

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

    <td class="px-3 py-2 text-sm">
        @if($row['publication_status'] instanceof ServiceSectionPublicationStatus)
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($row['publication_status']) {
                ServiceSectionPublicationStatus::PendingApproval => 'bg-amber-100 text-amber-800',
                ServiceSectionPublicationStatus::Approved => 'bg-sky-100 text-sky-800',
                ServiceSectionPublicationStatus::Rejected => 'bg-rose-100 text-rose-800',
                ServiceSectionPublicationStatus::Published => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                default => 'bg-gray-100 text-gray-700',
            } }}">
                {{ $row['publication_status']->label() }}
            </span>
            @if($row['publication_status'] === ServiceSectionPublicationStatus::Published && $row['published_sermon'])
                @php
                    $publishedSermonUrl = $row['published_sermon']->content_type === SermonContentType::ChildrensTalk
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
