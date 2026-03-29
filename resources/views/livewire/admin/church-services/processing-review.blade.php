<div class="space-y-6">
    {{-- Header --}}
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Review livestream processing</h1>
            <p class="text-gray-600">Select the correct sermon segment to resume processing</p>
        </div>
        <x-button link="{{ route('admin.services.processing.review.index') }}" variant="outline" inline wire:navigate>
            Back to queue
        </x-button>
    </div>

    {{-- Processing run details --}}
    <x-card heading="Processing run">
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">File</dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 break-all">{{ $log->original_filename }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Processing ID</dt>
                <dd class="mt-1 text-sm font-mono text-gray-700">{{ $log->processing_id }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Service Date</dt>
                <dd class="mt-1 text-sm text-gray-900">
                    @if($log->extracted_date)
                        {{ $log->extracted_date->format('j M Y') }}
                        @if($log->extracted_service)
                            &mdash;
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ match($log->extracted_service) {
                                \App\Enums\SermonService::MORNING => 'bg-green-100 text-green-800',
                                \App\Enums\SermonService::EVENING => 'bg-amber-100 text-amber-800',
                                default => 'bg-gray-100 text-gray-800',
                            } }}">
                                {{ $log->extracted_service->label() }}
                            </span>
                        @endif
                    @else
                        <span class="text-gray-400">Unknown</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Status</dt>
                <dd class="mt-1">
                    @if($requiresReview)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                            Awaiting manual review
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-light/15 text-cbc-teal-dark">
                            Confirmed — resuming
                        </span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Flagged at</dt>
                <dd class="mt-1 text-sm text-gray-900">
                    @if(isset($reviewMeta['flagged_at']))
                        {{ \Illuminate\Support\Carbon::parse($reviewMeta['flagged_at'])->format('j M Y H:i') }}
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Source video</dt>
                <dd class="mt-1">
                    @if($sourceAvailable)
                        <span class="inline-flex items-center gap-1 text-sm text-green-700">
                            <x-heroicon-o-check-circle class="w-4 h-4" aria-hidden="true" />
                            Available
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-sm text-rose-700">
                            <x-heroicon-o-x-circle class="w-4 h-4" aria-hidden="true" />
                            Not available
                        </span>
                    @endif
                </dd>
            </div>
        </dl>

        @if(isset($reviewMeta['reason_message']) && $reviewMeta['reason_message'] !== '')
            <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3">
                <p class="text-sm font-medium text-amber-800">Why manual review is required</p>
                <p class="mt-1 text-sm text-amber-700">{{ $reviewMeta['reason_message'] }}</p>
            </div>
        @endif

        @if(! $sourceAvailable)
            <div class="mt-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3" role="alert">
                <p class="text-sm font-medium text-rose-800">Source video unavailable</p>
                <p class="mt-1 text-sm text-rose-700">
                    The original source video file is no longer accessible. This run cannot be resumed from manual review.
                    The file may have been deleted or moved after processing was paused.
                </p>
            </div>
        @endif
    </x-card>

    {{-- Segment timeline --}}
    <x-card heading="Detected Segments">
        @if($segments->isEmpty())
            <p class="py-6 text-center text-gray-500">No segments were recorded for this run.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flags</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($segments as $segment)
                            @php
                                $isConfirmedSegment = $confirmedSegmentId !== null && $segment->id === $confirmedSegmentId;
                                $rowClass = match(true) {
                                    $isConfirmedSegment => 'bg-cbc-teal-light/10',
                                    $segment->isSpeech() => 'hover:bg-gray-50',
                                    default => 'bg-gray-50/50 hover:bg-gray-50',
                                };
                                $classificationColors = match($segment->classification) {
                                    \App\Enums\LivestreamSegmentClassification::Speech->value => 'bg-blue-100 text-blue-800',
                                    \App\Enums\LivestreamSegmentClassification::Song->value => 'bg-purple-100 text-purple-800',
                                    \App\Enums\LivestreamSegmentClassification::Silence->value => 'bg-gray-100 text-gray-600',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                            @endphp
                            <tr wire:key="segment-{{ $segment->id }}" class="{{ $rowClass }}">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                    {{ $segment->segment_index + 1 }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $classificationColors }}">
                                        {{ $segment->classificationDisplay }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-700">
                                    {{ $segment->getStartTimeFormatted() }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-700">
                                    {{ $segment->getEndTimeFormatted() }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                    {{ $segment->getDurationFormatted() }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex flex-wrap gap-1">
                                        @if($segment->isSermonCandidate())
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                                Candidate
                                            </span>
                                        @endif
                                        @if($isConfirmedSegment)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-cbc-teal-light/20 text-cbc-teal-dark">
                                                Confirmed
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right">
                                    @if($isConfirmedSegment)
                                        <span class="text-xs text-cbc-teal-dark font-medium">Selected</span>
                                    @elseif($segment->isSpeech() && $requiresReview)
                                        <x-form-button
                                            wire:click="confirmSegment({{ $segment->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="confirmSegment({{ $segment->id }})"
                                            variant="primary"
                                            size="xs"
                                            :disabled="$confirming || ! $sourceAvailable">
                                            <span wire:loading.remove wire:target="confirmSegment({{ $segment->id }})">
                                                This is the sermon
                                            </span>
                                            <span wire:loading wire:target="confirmSegment({{ $segment->id }})">
                                                Confirming…
                                            </span>
                                        </x-form-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($confirmedSegmentId !== null)
                <div class="mt-4 rounded-lg bg-cbc-teal-light/10 border border-cbc-teal-light/40 px-4 py-3">
                    <p class="text-sm text-cbc-teal-dark">
                        A segment has been confirmed and processing is queued to resume.
                        <a href="{{ route('admin.services.processing.review.index') }}" wire:navigate class="font-medium underline">
                            Return to queue
                        </a>
                    </p>
                </div>
            @endif
        @endif
    </x-card>
</div>
