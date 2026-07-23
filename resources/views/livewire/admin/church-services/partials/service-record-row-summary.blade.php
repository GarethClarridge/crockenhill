@php
    use App\Enums\ServiceSectionPublicationStatus;
    use App\Enums\SermonContentType;
    use App\Services\ChurchService\ServiceRecordTimeline;
@endphp

<div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-4">
    <div class="shrink-0 sm:w-24 sm:text-right">
        @if($item['start_time'] !== null && $item['end_time'] !== null)
            <p class="font-mono text-xs text-gray-500">
                {{ ServiceRecordTimeline::formatTimestamp($item['start_time']) }}–{{ ServiceRecordTimeline::formatTimestamp($item['end_time']) }}
            </p>
            @if($item['duration_formatted'])
                <p class="text-xs text-gray-400">{{ $item['duration_formatted'] }}</p>
            @endif
        @else
            <p class="text-xs text-gray-400">Plan only</p>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-gray-900">
                {{ $item['type_label'] }}
                @if($item['title_suffix'] ?? $item['detected_title'] ?? $item['planned_title'] ?? null)
                    — <span class="font-normal">{{ $item['title_suffix'] ?? $item['detected_title'] ?? $item['planned_title'] ?? null }}</span>
                @endif
            </span>

            @foreach($reviewReasons as $reason)
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $reason['classes'] }}">
                    {{ $reason['label'] }}
                </span>
            @endforeach

            @if($reviewReasons === [] && $item['needs_review'])
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Review</span>
            @endif

            @if($item['row_type'] === 'mismatched')
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Mismatch</span>
            @elseif($item['row_type'] === 'planned_only')
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Plan only</span>
            @elseif($item['row_type'] === 'unplanned')
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Recording only</span>
            @elseif($item['row_type'] === 'matched')
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Matches plan</span>
            @endif

            @if($item['publication_status'] instanceof ServiceSectionPublicationStatus
                && $item['publication_status'] !== ServiceSectionPublicationStatus::NotApplicable)
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($item['publication_status']) {
                    ServiceSectionPublicationStatus::Published => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
                    ServiceSectionPublicationStatus::Rejected => 'bg-rose-100 text-rose-800',
                    default => 'bg-amber-100 text-amber-800',
                } }}">
                    {{ $item['publication_status']->label() }}
                </span>
            @endif
        </div>

        @if($item['planned_context'])
            <p class="mt-1 text-xs {{ $item['row_type'] === 'mismatched' ? 'text-amber-700' : 'text-gray-500' }}">
                {{ $item['planned_context'] }}
            </p>
        @endif

        @if($item['publication_status'] === ServiceSectionPublicationStatus::Published && $item['published_sermon'])
            @php
                $publishedSermonUrl = $item['published_sermon']->content_type === SermonContentType::ChildrensTalk
                    ? route('childrens-corner.show', ['sermon' => $item['published_sermon']->slug])
                    : route('sermons.show', ['sermon' => $item['published_sermon']->slug]);
            @endphp
            <a href="{{ $publishedSermonUrl }}" class="mt-1 inline-block text-xs text-cbc-teal no-underline hover:text-cbc-teal-dark" target="_blank">
                View {{ strtolower($item['published_sermon']->content_type->label()) }} →
            </a>
        @endif
    </div>

    @if($hasDetails)
        <span class="shrink-0 text-gray-400" aria-hidden="true">⌄</span>
    @endif
</div>
