{{-- Shared sermon-segment confirmation table. Expects:
     $segments (sorted collection), $confirmedSegmentId, $requiresReview,
     $sourceAvailable, $confirming (bool), $confirmCall (Closure(segment): string
     returning the wire:click expression), optional $returnLink {href, label} --}}
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
                        $confirmExpression = $confirmCall($segment);
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
                                    wire:click="{{ $confirmExpression }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $confirmExpression }}"
                                    variant="primary"
                                    size="xs"
                                    :disabled="$confirming || ! $sourceAvailable">
                                    <span wire:loading.remove wire:target="{{ $confirmExpression }}">
                                        This is the sermon
                                    </span>
                                    <span wire:loading wire:target="{{ $confirmExpression }}">
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
                @if(($returnLink ?? null) !== null)
                    <a href="{{ $returnLink['href'] }}" wire:navigate class="font-medium underline">
                        {{ $returnLink['label'] }}
                    </a>
                @endif
            </p>
        </div>
    @endif
@endif
