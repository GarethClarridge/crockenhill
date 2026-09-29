<section class="space-y-4 rounded-lg border border-amber-200 bg-amber-50/50 p-4" aria-labelledby="ensemble-review-{{ $run->id }}">
    <div>
        <h3 id="ensemble-review-{{ $run->id }}" class="font-display text-xl text-gray-900">Structure questions</h3>
        <p class="mt-1 text-sm text-gray-700">Review each contested claim against the recording and transcript. Answers update the section proposal without resuming processing. Once a run has extracted media, answers are recorded and apply at its next re-detection.</p>
        <p class="mt-1 text-xs text-gray-600">
            {{ count($panel['questions']) }} open · {{ $panel['deferred_count'] }} deferred · {{ $panel['stale_count'] }} stale answers · {{ $panel['applied_count'] }} applied answers
        </p>
    </div>

    @unless($panel['current'])
        <p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">
            Source evidence has changed or is unavailable. These questions cannot be answered until the structure is detected again.
        </p>
    @endunless

    @foreach($panel['questions'] as $question)
        @php
            $questionId = $question['question_id'] ?? '';
            $start = isset($question['start_time']) ? gmdate('H:i:s', (int) $question['start_time']) : null;
            $end = isset($question['end_time']) ? gmdate('H:i:s', (int) $question['end_time']) : null;
        @endphp
        <article wire:key="ensemble-question-{{ $run->id }}-{{ $questionId }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="font-semibold text-gray-900">{{ str_replace('_', ' ', ucfirst($question['type'] ?? 'Disagreement')) }}</h4>
                @if($start && $end)
                    <span class="font-mono text-xs text-gray-600">{{ $start }}–{{ $end }}</span>
                @endif
                @if($question['deferred'] ?? false)
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Cannot tell yet</span>
                @endif
            </div>

            @if(($question['context'] ?? []) !== [])
                <details class="text-sm">
                    <summary class="min-h-11 cursor-pointer text-cbc-teal-dark underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal">Transcript around this question</summary>
                    <div class="max-h-48 space-y-1 overflow-y-auto rounded bg-gray-50 p-3 text-gray-700">
                        @foreach($question['context'] as $cue)
                            <p><span class="font-mono text-xs text-gray-500">{{ gmdate('H:i:s', (int) ($cue['start'] ?? 0)) }}</span> {{ $cue['text'] ?? '' }}</p>
                        @endforeach
                    </div>
                </details>
            @endif

            @if($panel['service_audio_url'] && ($question['clip'] ?? null))
                <div class="space-y-1">
                    <p class="text-xs text-gray-600">
                        Recording {{ \App\Support\ServiceTimestamp::format($question['clip']['start']) }}–{{ \App\Support\ServiceTimestamp::format($question['clip']['end']) }}, with ten seconds either side of every version
                    </p>
                    <audio src="{{ $panel['service_audio_url'] }}#t={{ $question['clip']['start'] }},{{ $question['clip']['end'] }}"
                        controls preload="none" class="w-full"
                        aria-label="Recording around this question">
                        Your browser does not support audio playback.
                    </audio>
                </div>
            @elseif($question['audio_url'] ?? null)
                <audio src="{{ $question['audio_url'] }}" controls preload="none" class="w-full" aria-label="Extracted section audio">
                    Your browser does not support audio playback.
                </audio>
            @elseif($question['video_url'] ?? null)
                <video src="{{ $question['video_url'] }}" controls preload="none" class="w-full rounded-lg bg-black" aria-label="Extracted section video"></video>
            @else
                <p class="text-xs text-gray-600">No archived recording for this run — judge from the transcript.</p>
            @endif

            @if(($question['alternatives'] ?? []) !== [])
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-800">Recorded alternatives</p>
                    @foreach($question['alternatives'] as $alternative)
                        @php $section = $alternative['section'] ?? []; @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-gray-200 px-3 py-2 text-sm">
                            <span>
                                {{ str_replace('_', ' ', $section['type'] ?? 'Section') }}
                                {{ gmdate('H:i:s', (int) ($section['start_time'] ?? 0)) }}–{{ gmdate('H:i:s', (int) ($section['end_time'] ?? 0)) }}
                                @if($section['reading_reference'] ?? null) · {{ $section['reading_reference'] }} @endif
                                @if($section['song_title'] ?? null) · {{ $section['song_title'] }} @endif
                            </span>
                            @if($panel['current'] && $questionId !== '' && isset($alternative['slots'][0]))
                                <x-form-button type="button" variant="outline" size="xs"
                                    wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'choose', {{ $alternative['slots'][0] }})"
                                    wire:loading.attr="disabled">
                                    Use this version
                                </x-form-button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if($panel['current'] && $questionId !== '')
                <div class="flex flex-wrap gap-2">
                    @unless(($question['type'] ?? '') === 'sermon_absence')
                        <x-form-button type="button" variant="outline" size="xs"
                            wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'accept')"
                            wire:loading.attr="disabled">Accept proposal</x-form-button>
                    @endunless
                    @unless(in_array($question['type'] ?? '', ['degraded_coverage', 'sermon_absence'], true))
                        <x-form-button type="button" variant="outline" size="xs"
                            wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'remove')"
                            wire:loading.attr="disabled">Remove claim</x-form-button>
                    @endunless
                    <x-form-button type="button" variant="outline" size="xs"
                        wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'defer')"
                        wire:loading.attr="disabled">Cannot tell</x-form-button>
                </div>

                @if(($question['type'] ?? '') === 'sermon_absence')
                    <x-textarea label="What happened instead of a sermon?" wire:model="ensembleAbsenceExplanation.{{ $questionId }}" rows="2" />
                    <x-form-button type="button" variant="primary" size="xs"
                        wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'absent')"
                        wire:loading.attr="disabled">Confirm no sermon</x-form-button>
                @elseif(! in_array($question['type'] ?? '', ['degraded_coverage', 'alignment'], true))
                    @php $rows = $ensembleCorrections[$questionId] ?? null; @endphp
                    @if($rows === null)
                        <x-form-button type="button" variant="outline" size="xs"
                            wire:click="startEnsembleCorrection({{ $run->id }}, '{{ $questionId }}')"
                            wire:loading.attr="disabled">Correct it myself</x-form-button>
                    @else
                        <fieldset class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <legend class="px-1 text-sm font-medium text-gray-800">What is really here</legend>
                            <p class="text-xs text-gray-600">Times as h:mm:ss, m:ss or seconds. Add a row to split; remove every row if nothing belongs here.</p>
                            @foreach($rows as $index => $row)
                                <div wire:key="ensemble-correction-{{ $questionId }}-{{ $index }}" class="grid gap-2 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.5fr)_auto] sm:items-end">
                                    <x-select label="Section" :options="$ensembleSectionTypes"
                                        wire:model="ensembleCorrections.{{ $questionId }}.{{ $index }}.type" />
                                    <x-input label="Start" inputmode="decimal"
                                        wire:model="ensembleCorrections.{{ $questionId }}.{{ $index }}.start" />
                                    <x-input label="End" inputmode="decimal"
                                        wire:model="ensembleCorrections.{{ $questionId }}.{{ $index }}.end" />
                                    @if(($row['type'] ?? '') === 'song')
                                        <x-input label="Song title" wire:model="ensembleCorrections.{{ $questionId }}.{{ $index }}.song_title" />
                                    @elseif(in_array($row['type'] ?? '', ['bible_reading', 'sermon'], true))
                                        <x-input label="Bible reference" wire:model="ensembleCorrections.{{ $questionId }}.{{ $index }}.reference" />
                                    @else
                                        <span class="hidden sm:block" aria-hidden="true"></span>
                                    @endif
                                    <x-form-button type="button" variant="ghost" size="xs"
                                        wire:click="removeEnsembleCorrectionRow('{{ $questionId }}', {{ $index }})"
                                        aria-label="Remove row {{ $index + 1 }}">Remove</x-form-button>
                                </div>
                            @endforeach
                            <div class="flex flex-wrap gap-2">
                                <x-form-button type="button" variant="outline" size="xs"
                                    wire:click="addEnsembleCorrectionRow('{{ $questionId }}')">Add a section</x-form-button>
                                <x-form-button type="button" variant="primary" size="xs"
                                    wire:click="answerEnsembleQuestion({{ $run->id }}, '{{ $questionId }}', 'correct')"
                                    wire:loading.attr="disabled">Apply correction</x-form-button>
                            </div>
                        </fieldset>
                    @endif
                @endif
            @endif
        </article>
    @endforeach
</section>
