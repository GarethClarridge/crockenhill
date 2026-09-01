{{-- Inline section review panel: expects $panel (section, confirmable, audio_url,
     video_url), $sectionTypeOptions, $preacherOptions, and $sectionPublishingEnabled. --}}
@php
    $section = $panel['section'];
    $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
    $predictedSpeaker = $section->predictedChildrensTalkSpeaker();
    $speakerOutcome = is_array($predictedSpeaker) ? ($predictedSpeaker['outcome'] ?? null) : null;
    $reviewReasons = is_array($panel['reasons'] ?? null) ? $panel['reasons'] : [];
    $boundaryEvidence = $section->metadata?->toArray()['childrens_talk_boundary'] ?? null;
    $boundaryTail = is_array($boundaryEvidence['tail'] ?? null) ? $boundaryEvidence['tail'] : [];
    $followingSections = is_array($boundaryEvidence['following_sections'] ?? null)
        ? $boundaryEvidence['following_sections']
        : [];
    $boundaryTailText = is_string($boundaryTail['text'] ?? null) ? trim($boundaryTail['text']) : '';
    // Only offer Confirm when a one-click confirm will actually clear the section
    // (unmatched songs and pending speaker reviews are resolved via their own
    // controls, not by Confirm).
    $hasManualReviewAction = (bool) ($panel['confirmable'] ?? false);
@endphp

<div id="section-{{ $section->id }}" class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 space-y-3" wire:key="section-review-panel-{{ $section->id }}">
    @if($hasManualReviewAction || $sectionPublishingEnabled)
        <div class="flex justify-end">
            <div class="flex flex-wrap gap-2">
                @if($hasManualReviewAction)
                    <x-form-button
                        type="button"
                        size="xs"
                        variant="primary"
                        icon="check"
                        wire:click="confirmSection({{ $section->id }})"
                        wire:target="confirmSection({{ $section->id }})"
                        loading-label="Confirming..."
                    >
                        Confirm
                    </x-form-button>
                @endif

                @if($sectionPublishingEnabled)
                    @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PendingApproval)
                        <x-form-button
                            type="button"
                            size="xs"
                            variant="primary"
                            wire:click="approve({{ $section->id }})"
                            wire:target="approve({{ $section->id }})"
                        >
                            Approve
                        </x-form-button>
                    @endif

                    @if(in_array($section->publication_status, [
                        \App\Enums\ServiceSectionPublicationStatus::PendingApproval,
                        \App\Enums\ServiceSectionPublicationStatus::Approved,
                    ], true))
                        <x-form-button
                            type="button"
                            size="xs"
                            variant="outline"
                            wire:click="reject({{ $section->id }})"
                            wire:target="reject({{ $section->id }})"
                        >
                            Reject
                        </x-form-button>
                    @endif

                    @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::Rejected)
                        <x-form-button
                            type="button"
                            size="xs"
                            variant="outline"
                            wire:click="requeue({{ $section->id }})"
                            wire:target="requeue({{ $section->id }})"
                        >
                            Requeue
                        </x-form-button>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="grid gap-3 md:grid-cols-[12rem_minmax(0,1fr)_auto]">
        <x-select
            label="Section type"
            wire:model.blur="sectionEdits.{{ $section->id }}.section_type"
            :options="$sectionTypeOptions"
        />

        <x-input
            label="Section title"
            wire:model.blur="sectionEdits.{{ $section->id }}.title"
            maxlength="255"
        />

        <div class="flex items-end">
            <x-form-button
                type="button"
                size="sm"
                variant="primary"
                wire:click="saveSection({{ $section->id }})"
                wire:target="saveSection({{ $section->id }})"
            >
                Save
            </x-form-button>
        </div>
    </div>

    @if($section->section_type === \App\Enums\ServiceSectionType::ChildrensTalk)
        @if(is_array($boundaryEvidence))
            <div class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium">Boundary evidence</p>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Approval required</span>
                </div>

                <p>
                    The candidate remains inclusive at
                    <span class="font-medium tabular-nums">{{ \App\Services\ChurchService\ServiceRecordTimeline::formatTimestamp((float) ($boundaryEvidence['candidate']['start_time'] ?? $section->start_time)) }}–{{ \App\Services\ChurchService\ServiceRecordTimeline::formatTimestamp((float) ($boundaryEvidence['candidate']['end_time'] ?? $section->end_time)) }}</span>.
                    Following material is not trimmed automatically.
                </p>

                @if($boundaryTailText !== '')
                    <p>
                        <span class="font-medium">Transcript near the candidate end:</span>
                        “{{ \Illuminate\Support\Str::limit($boundaryTailText, 280) }}”
                    </p>
                @elseif(($boundaryTail['status'] ?? null) === 'no_cues')
                    <p class="text-amber-800">No timed transcript cues were found near the candidate end.</p>
                @else
                    <p class="text-amber-800">Timed transcript evidence is {{ str_replace('_', ' ', (string) ($boundaryTail['status'] ?? 'unavailable')) }}.</p>
                @endif

                @if($followingSections !== [])
                    @php $nextSection = $followingSections[0]; @endphp
                    <p>
                        <span class="font-medium">Next detected section:</span>
                        {{ \Illuminate\Support\Str::headline((string) ($nextSection['section_type'] ?? 'section')) }}
                        @if(is_string($nextSection['title'] ?? null) && trim($nextSection['title']) !== '')
                            — {{ $nextSection['title'] }}
                        @endif
                        <span class="tabular-nums text-amber-800">({{ \App\Services\ChurchService\ServiceRecordTimeline::formatTimestamp((float) ($nextSection['start_time'] ?? 0.0)) }}–{{ \App\Services\ChurchService\ServiceRecordTimeline::formatTimestamp((float) ($nextSection['end_time'] ?? 0.0)) }})</span>
                    </p>
                @endif

                <p class="text-xs text-amber-800">
                    If the tail is separate, shorten the end time below and save. The clip will be re-extracted and returned to mandatory approval.
                </p>
            </div>
        @endif

        <div class="grid gap-3 rounded-lg border border-cbc-teal/20 bg-cbc-teal-light/10 p-3 md:grid-cols-2">
            <x-input
                label="Speaker name (recommended)"
                wire:model.blur="speakerEdits.{{ $section->id }}.speaker_name"
                maxlength="255"
                hint="Enter the speaker's name directly if they are not in the list."
            />

            <x-select
                label="Existing speaker (optional)"
                wire:model.blur="speakerEdits.{{ $section->id }}.preacher_id"
                :options="$preacherOptions"
                placeholder="Choose from the speaker list..."
                hint="Use this when the speaker is already in the list."
            />

            <x-input
                type="number"
                label="Candidate end (seconds)"
                wire:model.blur="sectionEdits.{{ $section->id }}.end_time"
                min="{{ $section->start_time }}"
                max="{{ $section->end_time }}"
                step="0.001"
                hint="Optional: shorten only within this inclusive candidate."
            />
        </div>

        <div class="rounded-lg border border-cbc-teal/15 bg-white px-3 py-2 text-xs text-gray-600">
            @if($publicationSpeaker)
                <p>
                    Publication speaker:
                    <span class="font-semibold text-gray-900">{{ $publicationSpeaker['preacher_name'] }}</span>
                    @if(($publicationSpeaker['confidence'] ?? null) !== null)
                        <span class="text-gray-500">· {{ number_format($publicationSpeaker['confidence'] * 100, 0) }}%</span>
                    @endif
                </p>
            @endif

            @if($predictedSpeaker)
                <p @class(['mt-1' => $publicationSpeaker])>
                    Detected speaker:
                    @if(is_string($predictedSpeaker['preacher_name'] ?? null) && trim((string) $predictedSpeaker['preacher_name']) !== '')
                        <span class="font-semibold text-gray-900">{{ $predictedSpeaker['preacher_name'] }}</span>
                    @else
                        <span class="font-semibold text-gray-900">{{ \Illuminate\Support\Str::headline((string) $speakerOutcome) }}</span>
                    @endif
                    @if(($predictedSpeaker['confidence'] ?? null) !== null)
                        <span class="text-gray-500">· {{ number_format($predictedSpeaker['confidence'] * 100, 0) }}%</span>
                    @endif
                </p>

                @if(is_string($predictedSpeaker['reason'] ?? null) && $predictedSpeaker['reason'] !== '')
                    <p class="mt-1 text-gray-500">{{ $predictedSpeaker['reason'] }}</p>
                @endif
            @elseif(!$publicationSpeaker)
                <p>No speaker has been confirmed for this children's talk yet.</p>
            @endif
        </div>
    @endif

    @if($panel['audio_url'] || $panel['video_url'])
        <div class="grid gap-3 md:grid-cols-2">
            @if($panel['audio_url'])
                <div>
                    <p class="mb-1 text-xs text-gray-500">Audio preview</p>
                    <audio src="{{ $panel['audio_url'] }}" class="w-full" controls preload="none">
                        Your browser does not support audio playback.
                    </audio>
                </div>
            @endif
            @if($panel['video_url'])
                <div>
                    <p class="mb-1 text-xs text-gray-500">Video preview</p>
                    <video src="{{ $panel['video_url'] }}" class="w-full rounded-md bg-black" controls preload="none"></video>
                </div>
            @endif
        </div>
    @endif
</div>
