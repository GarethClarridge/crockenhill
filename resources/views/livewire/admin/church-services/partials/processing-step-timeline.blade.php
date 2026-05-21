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

                        <x-metadata-list columns="3" class="xl:min-w-100" :items="[
                            ['label' => 'Started',   'value' => $timelineStep['started_at']?->format('j M Y H:i:s') ?? 'Not recorded'],
                            ['label' => 'Completed', 'value' => $timelineStep['completed_at']?->format('j M Y H:i:s') ?? 'Not recorded'],
                            ['label' => 'Duration',  'value' => $timelineStep['duration'] ?? 'Not recorded'],
                        ]" />
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</details>
