{{-- Inline section review panel: expects $panel (section, audio_url, video_url),
     $sectionTypeOptions, $preacherOptions, and $sectionPublishingEnabled. --}}
@php
    $section = $panel['section'];
    $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
    $predictedSpeaker = $section->predictedChildrensTalkSpeaker();
    $speakerOutcome = is_array($predictedSpeaker) ? ($predictedSpeaker['outcome'] ?? null) : null;
@endphp

<div id="section-{{ $section->id }}" class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 space-y-3" wire:key="section-review-panel-{{ $section->id }}">
    @if($sectionPublishingEnabled)
        <div class="flex justify-end">
            <div class="flex flex-wrap gap-2">
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
        <div class="grid gap-3 rounded-lg border border-gray-200 bg-white p-3 md:grid-cols-2">
            <x-select
                label="Choose speaker"
                wire:model.blur="speakerEdits.{{ $section->id }}.preacher_id"
                :options="$preacherOptions"
                placeholder="Select a speaker..."
                hint="Pick an existing speaker to confirm or override the detected one."
            />

            <x-input
                label="Fallback speaker name"
                wire:model.blur="speakerEdits.{{ $section->id }}.speaker_name"
                maxlength="255"
                hint="Use free text when the speaker is not in the speaker list."
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
