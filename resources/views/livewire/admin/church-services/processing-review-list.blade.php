<x-admin.list-shell
    title="Sermon review queue"
    description="Segmentation-style runs paused for manual sermon selection"
    :paginator="$pendingReviews"
    items-name="run"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
    </x-slot:actions>

    <x-slot:pagination>
        {{ $pendingReviews->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flagged</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Review Reason</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Segments</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($pendingReviews as $log)
                @php
                    $reviewMeta = $log->manualReviewMetadata();
                    $flaggedAt = isset($reviewMeta['flagged_at'])
                        ? \Illuminate\Support\Carbon::parse($reviewMeta['flagged_at'])
                        : $log->updated_at;
                    $reasonCode = $reviewMeta['reason_code'] ?? null;
                    $speechSegments = $reviewMeta['speech_segments'] ?? [];
                    $isConfirmed = ($reviewMeta['status'] ?? null) === 'confirmed';
                    $runLabel = $log->isAutoTrimVideoRun() ? 'Auto-trim video' : 'Livestream';
                @endphp

                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <p class="text-sm font-medium">{{ $flaggedAt?->format('j M Y') }}</p>
                        <p class="text-xs text-gray-500">{{ $flaggedAt?->format('H:i') }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-medium truncate max-w-xs">{{ $log->original_filename }}</p>
                        <p class="text-xs text-gray-500">{{ $runLabel }}</p>
                        <p class="text-xs text-gray-500 font-mono">{{ $log->processing_id }}</p>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($log->extracted_date)
                            <p class="text-sm font-medium">{{ $log->extracted_date->format('j M Y') }}</p>
                        @endif
                        @if($log->extracted_service)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ match($log->extracted_service) {
                                \App\Enums\SermonService::Morning => 'bg-green-100 text-green-800',
                                \App\Enums\SermonService::Evening => 'bg-amber-100 text-amber-800',
                                default => 'bg-gray-100 text-gray-800',
                            } }}">
                                {{ $log->extracted_service->label() }}
                            </span>
                        @else
                            <span class="text-xs text-gray-400">Unknown</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($reasonCode)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $reasonCode)) }}
                            </span>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-sm text-gray-700">{{ count($speechSegments) }} speech</span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($isConfirmed)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                                Confirmed
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                Awaiting review
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-button
                            link="{{ route('admin.services.processing.review', $log) }}"
                            variant="outline"
                            size="xs"
                            inline
                        >
                            Review
                        </x-button>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    :colspan="7"
                    title="No sermon processing runs"
                    description="No sermon processing runs are awaiting manual review."
                />
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
