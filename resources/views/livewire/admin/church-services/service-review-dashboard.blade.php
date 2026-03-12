<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl">Service Review Dashboard</h1>
            <p class="text-gray-600">Review flagged services and classified sections in one queue</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-button link="{{ route('admin.services.section-publications') }}" variant="outline" inline>
                Section Queue
            </x-button>
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Service groups</p>
            <p class="mt-2 font-display text-3xl">{{ $summary['service_groups'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Flagged sections</p>
            <p class="mt-2 font-display text-3xl">{{ $summary['sections'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Services needing review</p>
            <p class="mt-2 font-display text-3xl">{{ $summary['services_needing_review'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Pending approvals</p>
            <p class="mt-2 font-display text-3xl">{{ $summary['pending_approvals'] }}</p>
        </div>
    </div>

    @forelse($groups as $group)
        <x-card>
            <div class="not-prose space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-2xl text-gray-900">{{ $group['date_label'] }}</h2>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($group['service_enum']) {
                                \App\Enums\SermonService::MORNING => 'bg-green-100 text-green-800',
                                \App\Enums\SermonService::EVENING => 'bg-amber-100 text-amber-800',
                                \App\Enums\SermonService::OTHER => 'bg-slate-100 text-slate-800',
                                default => 'bg-gray-100 text-gray-700',
                            } }}">
                                {{ $group['service_label'] }}
                            </span>
                            @if($group['service']?->needs_review)
                                <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">
                                    Service needs review
                                </span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ count($group['sections']) }} {{ \Illuminate\Support\Str::plural('flagged section', count($group['sections'])) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if($group['service'] && $group['pending_approval_count'] > 0)
                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    {{ $group['pending_approval_count'] }} pending {{ \Illuminate\Support\Str::plural('publication', $group['pending_approval_count']) }}
                                </p>
                                @if($group['batch_blocked_count'] > 0)
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $group['batch_ready_count'] }} ready, {{ $group['batch_blocked_count'] }} blocked by other review checks.
                                    </p>
                                @endif
                                <div class="mt-2 flex justify-end">
                                    <x-form-button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        wire:click="approvePendingPublications({{ $group['service']->id }})"
                                        wire:target="approvePendingPublications({{ $group['service']->id }})"
                                        wire:loading.attr="disabled"
                                    >
                                        <span wire:loading.remove wire:target="approvePendingPublications({{ $group['service']->id }})">
                                            Approve all pending publications
                                        </span>
                                        <span wire:loading wire:target="approvePendingPublications({{ $group['service']->id }})">
                                            Approving...
                                        </span>
                                    </x-form-button>
                                </div>
                            </div>
                        @endif

                        @if($group['service']?->needs_review)
                            <x-form-button
                                type="button"
                                variant="primary"
                                size="sm"
                                wire:click="markServiceReviewed({{ $group['service']->id }})"
                                wire:target="markServiceReviewed({{ $group['service']->id }})"
                            >
                                Mark reviewed
                            </x-form-button>
                        @endif

                        @if($group['service'])
                            <x-button link="{{ route('admin.services.edit', $group['service']) }}" variant="outline" size="sm" inline>
                                Edit service
                            </x-button>
                        @endif
                    </div>
                </div>

                @if($group['sections'] === [])
                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-500">
                        This service only has a service-level review flag.
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($group['sections'] as $entry)
                            @php
                                $section = $entry['section'];
                                $sectionTitle = $section->title ?: 'Untitled section';
                                $linkedSongTitle = $section->churchServiceItem?->song?->title;
                                $songMatchType = $section->songMatchType();
                                $predictedSpeaker = $section->predictedChildrensTalkSpeaker();
                                $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
                                $speakerOutcome = is_array($predictedSpeaker) ? ($predictedSpeaker['outcome'] ?? null) : null;
                            @endphp
                            <div wire:key="service-review-section-{{ $section->id }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-semibold text-gray-900">{{ $section->section_type->label() }}</p>
                                            <span class="text-sm text-gray-400">#{{ $section->section_order }}</span>
                                            @foreach($entry['reasons'] as $reason)
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $reason['classes'] }}">
                                                    {{ $reason['label'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                        <p class="text-base font-medium text-gray-900">{{ $sectionTitle }}</p>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                            <span>Run {{ $section->processingLog->processing_id }}</span>
                                            <span>{{ number_format($section->start_time, 1) }}s - {{ number_format($section->end_time, 1) }}s</span>
                                            @if($section->confidence !== null)
                                                <span>Confidence {{ number_format($section->confidence * 100, 0) }}%</span>
                                            @endif
                                            <span>Status {{ $section->publication_status->label() }}</span>
                                        </div>
                                        @if($entry['review_reason'])
                                            <p class="text-xs text-gray-500">{{ $entry['review_reason'] }}</p>
                                        @endif
                                        @if($section->churchServiceItem)
                                            <p class="text-xs text-gray-500">
                                                Canonical item: {{ $section->churchServiceItem->title }}
                                                @if($linkedSongTitle)
                                                    · Linked song: {{ $linkedSongTitle }}
                                                @endif
                                            </p>
                                        @endif
                                        @if($songMatchType)
                                            <p class="text-xs text-gray-500">
                                                Song match: <span class="font-medium text-gray-700">{{ $songMatchType->label() }}</span>.
                                                {{ $songMatchType->description() }}
                                            </p>
                                        @endif
                                        @if($section->section_type === \App\Enums\ServiceSectionType::CHILDRENS_TALK)
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
                                        @if(collect($entry['reasons'])->contains('key', 'low_confidence'))
                                            <p class="text-xs text-gray-500">Low confidence means below the {{ (int) ($lowConfidenceThreshold * 100) }}% review threshold.</p>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PENDING_APPROVAL)
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
                                            \App\Enums\ServiceSectionPublicationStatus::PENDING_APPROVAL,
                                            \App\Enums\ServiceSectionPublicationStatus::APPROVED,
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

                                        @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::REJECTED)
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

                                        @if($entry['manual_edit_url'])
                                            <x-button link="{{ $entry['manual_edit_url'] }}" size="xs" variant="ghost" inline>
                                                Manual list edit
                                            </x-button>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                                    <div class="space-y-4">
                                        <div class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)_auto]">
                                            <x-select
                                                label="Section type"
                                                wire:model.live="sectionEdits.{{ $section->id }}.section_type"
                                                :options="$sectionTypeOptions"
                                            />

                                            <x-input
                                                label="Section title"
                                                wire:model.live.debounce="sectionEdits.{{ $section->id }}.title"
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

                                        @if($section->section_type === \App\Enums\ServiceSectionType::CHILDRENS_TALK)
                                            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-3 md:grid-cols-2">
                                                <x-select
                                                    label="Choose preacher"
                                                    wire:model.live="speakerEdits.{{ $section->id }}.preacher_id"
                                                    :options="$preacherOptions"
                                                    placeholder="Select a preacher..."
                                                    hint="Pick an existing preacher to confirm or override the detected speaker."
                                                />

                                                <x-input
                                                    label="Fallback speaker name"
                                                    wire:model.live.debounce="speakerEdits.{{ $section->id }}.speaker_name"
                                                    maxlength="255"
                                                    hint="Use free text when the speaker is not in the preacher list."
                                                />
                                            </div>
                                        @endif
                                    </div>

                                    <div class="rounded-lg border border-gray-200 bg-white p-3">
                                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Preview</p>
                                        @if($entry['audio_url'] || $entry['video_url'])
                                            <div class="mt-3 space-y-3">
                                                @if($entry['audio_url'])
                                                    <div>
                                                        <p class="mb-1 text-xs text-gray-500">Audio</p>
                                                        <audio src="{{ $entry['audio_url'] }}" class="w-full" controls preload="none">
                                                            Your browser does not support audio playback.
                                                        </audio>
                                                    </div>
                                                @endif
                                                @if($entry['video_url'])
                                                    <div>
                                                        <p class="mb-1 text-xs text-gray-500">Video</p>
                                                        <video src="{{ $entry['video_url'] }}" class="w-full rounded-md bg-black" controls preload="none"></video>
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <p class="mt-3 text-sm text-gray-500">No extracted preview is available for this section yet.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-card>
    @empty
        <x-card>
            <div class="not-prose rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-gray-500">
                No services or sections are awaiting review.
            </div>
        </x-card>
    @endforelse
</div>
