<x-admin.page title="Review sermon processing" :description="'Select the correct sermon segment to resume processing for this '.strtolower($runLabel)">
    <x-slot:actions>
        <x-button link="{{ route('admin.services.inbox', ['filter' => 'segments']) }}" variant="outline" inline wire:navigate>
            Back to review inbox
        </x-button>
    </x-slot:actions>

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
                                \App\Enums\SermonService::Morning => 'bg-green-100 text-green-800',
                                \App\Enums\SermonService::Evening => 'bg-amber-100 text-amber-800',
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
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Run Type</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $runLabel }}</dd>
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
            <x-alert type="warning" title="Why manual review is required" class="mt-4">
                {{ $reviewMeta['reason_message'] }}
            </x-alert>
        @endif

        @if(! $sourceAvailable)
            <x-alert type="error" title="Source video unavailable" class="mt-4">
                The original source video file is no longer accessible. This run cannot be resumed from manual review.
                The file may have been deleted or moved after processing was paused.
            </x-alert>
        @endif
    </x-card>

    {{-- LLM structure proposal that failed validation --}}
    @if($structureProposal !== null && isset($structureProposal['sections']) && $structureProposal['sections'] !== [])
        @php
            $formatSeconds = fn ($seconds): string => sprintf('%d:%02d', intdiv((int) $seconds, 60), ((int) $seconds) % 60);
        @endphp
        <x-card heading="Detected Structure (failed validation)">
            <p class="text-sm text-gray-600">
                The structure below was detected but rejected by the validation gate, so no sections were saved.
                It is usually largely correct — use it alongside the segments when confirming the sermon.
            </p>

            @if(! empty($structureProposal['hard_failures']))
                <ul class="mt-3 list-disc pl-5 text-sm text-amber-800 space-y-1">
                    @foreach($structureProposal['hard_failures'] as $failure)
                        <li>{{ $failure['message'] ?? $failure['code'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Confidence</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($structureProposal['sections'] as $section)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 text-sm text-gray-500">{{ $section['section_order'] ?? $loop->iteration }}</td>
                                <td class="px-3 py-2 text-sm text-gray-900">{{ str_replace('_', ' ', $section['section_type'] ?? '') }}</td>
                                <td class="px-3 py-2 text-sm text-gray-900">{{ $section['title'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $formatSeconds($section['start_time'] ?? 0) }}–{{ $formatSeconds($section['end_time'] ?? 0) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-500">{{ number_format((float) ($section['confidence'] ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-sm text-gray-500">
                                    {{ implode(', ', $section['metadata']['review_flags'] ?? []) ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    {{-- Segment timeline --}}
    <x-card heading="Detected Segments">
        @include('livewire.admin.church-services.partials.segment-confirmation', [
            'segments' => $segments,
            'confirmedSegmentId' => $confirmedSegmentId,
            'requiresReview' => $requiresReview,
            'sourceAvailable' => $sourceAvailable,
            'confirming' => $confirming,
            'confirmCall' => fn ($segment): string => "confirmSegment({$segment->id})",
            'returnLink' => [
                'href' => route('admin.services.inbox', ['filter' => 'segments']),
                'label' => 'Return to review inbox',
            ],
        ])
    </x-card>
</x-admin.page>
