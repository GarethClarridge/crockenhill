<section id="evidence-review" class="space-y-4 scroll-mt-6" aria-labelledby="evidence-review-heading">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 id="evidence-review-heading" class="font-display text-2xl text-gray-900">Review source evidence</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">
                Compare the current service with every pending source revision. Completing a review creates a new Manual revision; evidence and proposal history remain available.
            </p>
        </div>
        <x-form-button
            type="button"
            variant="outline"
            size="sm"
            wire:click="selectAllPendingEvidence"
            wire:target="selectAllPendingEvidence"
            wire:loading.attr="disabled"
        >
            Review all currently pending evidence
        </x-form-button>
    </div>

    @if($changedSinceLoad)
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
            <strong>New evidence arrived after this screen loaded.</strong>
            Reload before submitting so the new proposal is included in your decisions.
        </div>
    @endif

    @php
        $proposalsBySource = $proposals->groupBy(
            static fn ($proposal) => $proposal->triggerSourceRecord->source->value
        );
    @endphp

    <div class="space-y-4">
        @foreach($proposalsBySource as $source => $sourceProposals)
            <x-card wire:key="evidence-source-{{ $source }}">
                <div class="flex flex-col gap-2 border-b border-gray-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ str($source)->headline() }}</h3>
                        <p class="text-sm text-gray-500">{{ $sourceProposals->count() }} proposal {{ str('revision')->plural($sourceProposals->count()) }}</p>
                    </div>
                </div>

                <div class="divide-y divide-gray-200">
                    @foreach($sourceProposals as $proposal)
                        @php
                            $record = $proposal->triggerSourceRecord;
                        @endphp
                        <article class="space-y-4 py-5 first:pt-4 last:pb-0" wire:key="evidence-proposal-{{ $proposal->id }}">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                                            {{ str($proposal->status->value)->headline() }}
                                        </span>
                                        <span class="text-sm font-medium text-gray-900">{{ $record->source_key }}</span>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500">
                                        Captured {{ $record->captured_at?->format('j F Y, H:i') ?? 'at an unknown time' }}
                                        · artifact {{ str($record->input_hash)->limit(12, '') }}
                                    </p>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-toggle
                                        label="Include in this review"
                                        wire:model="selectedProposals.{{ $proposal->id }}"
                                    />
                                    <x-select
                                        label="Decision"
                                        dusk="proposal-decision-{{ $proposal->id }}"
                                        wire:model="proposalResolutions.{{ $proposal->id }}"
                                        :options="[
                                            ['id' => '', 'name' => 'Choose a decision'],
                                            ['id' => 'accepted', 'name' => 'Accept into Manual review'],
                                            ['id' => 'rejected', 'name' => 'Reject, preserve as history'],
                                            ['id' => 'replaced', 'name' => 'Replace with Manual value'],
                                        ]"
                                    />
                                </div>
                            </div>

                            @if(filled($proposal->conflicts))
                                <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                                    <strong>This proposal needs a decision the projector would not make for you.</strong>
                                    <ul class="mt-2 list-disc space-y-1 pl-5">
                                        @foreach($proposal->conflicts as $conflict)
                                            <li wire:key="conflict-{{ $proposal->id }}-{{ $loop->index }}">
                                                <span class="font-medium">{{ str($conflict['kind'] ?? 'unknown')->replace('_', ' ')->ucfirst() }}</span>
                                                — {{ $conflict['reason'] ?? 'No reason recorded.' }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="overflow-x-auto rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                        <tr>
                                            <th scope="col" class="px-3 py-2">Source assertion</th>
                                            <th scope="col" class="px-3 py-2">Evidence</th>
                                            <th scope="col" class="px-3 py-2">Current canonical value</th>
                                            <th scope="col" class="px-3 py-2">Authority / match</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        @foreach($record->assertions as $assertion)
                                            @php
                                                $decision = $proposal->field_decisions[$record->revision_hash.':'.$assertion->assertion_key] ?? null;

                                                // Without a recorded decision the only honest fallback is an
                                                // unambiguous one: a single matching item. Two candidates mean
                                                // this assertion's canonical item is genuinely unknown.
                                                $candidates = $decision !== null
                                                    ? $churchService->items->where('canonical_identity', $decision['canonical_identity'])
                                                    : $churchService->items->filter(
                                                        fn ($item) => ($assertion->song_id !== null && $item->song_id === $assertion->song_id)
                                                            || mb_strtolower((string) $item->title) === mb_strtolower($assertion->title)
                                                    );
                                                $currentItem = $candidates->count() === 1 ? $candidates->first() : null;
                                                $occurrence = $currentItem?->occurrence_state?->value
                                                    ?? ($assertion->evidence_kind->value === 'observed' ? 'observed_only' : 'planned_only');
                                                $badgeClasses = match ($occurrence) {
                                                    'planned_and_observed' => 'bg-emerald-50 text-emerald-800',
                                                    'observed_only' => 'bg-sky-50 text-sky-800',
                                                    'manually_confirmed' => 'bg-gray-900 text-white',
                                                    default => 'bg-amber-50 text-amber-800',
                                                };
                                            @endphp
                                            <tr wire:key="source-assertion-{{ $assertion->id }}" class="align-top">
                                                <td class="px-3 py-3">
                                                    <p class="font-medium text-gray-900">{{ $assertion->title }}</p>
                                                    <p class="text-gray-500">Position {{ $assertion->source_position }}</p>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $badgeClasses }}">
                                                        {{ str($occurrence)->replace('_', ' ')->headline() }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 text-gray-700">{{ $currentItem?->title ?? 'Not currently included' }}</td>
                                                <td class="px-3 py-3 text-gray-600">
                                                    @if($decision === null)
                                                        <span class="font-medium text-amber-800">
                                                            No recorded match explanation — check this assertion yourself.
                                                        </span>
                                                    @else
                                                        <p>{{ $decision['explanation'] }}</p>
                                                        <p class="mt-1 text-xs text-gray-500">
                                                            {{ str($decision['match_method'])->replace('_', ' ')->ucfirst() }}
                                                            · {{ $decision['canonical_identity'] }}
                                                        </p>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        @if($record->assertions->isEmpty())
                                            <tr>
                                                <td colspan="4" class="px-3 py-4 text-center text-gray-500">This source revision has no item assertions.</td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <h4 class="font-semibold text-gray-900">Current service content</h4>
                                    <dl class="mt-3 space-y-3 text-sm">
                                        <div><dt class="font-medium text-gray-600">Summary</dt><dd class="text-gray-900">{{ $churchService->summary ?: 'None' }}</dd></div>
                                        <div><dt class="font-medium text-gray-600">Notices</dt><dd class="text-gray-900">{{ json_encode($churchService->notices ?? [], JSON_UNESCAPED_SLASHES) }}</dd></div>
                                        <div><dt class="font-medium text-gray-600">Chapter markers</dt><dd class="text-gray-900">{{ json_encode($churchService->chapter_markers ?? [], JSON_UNESCAPED_SLASHES) }}</dd></div>
                                    </dl>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <h4 class="font-semibold text-gray-900">Proposed service content</h4>
                                    <dl class="mt-3 space-y-3 text-sm">
                                        <div><dt class="font-medium text-gray-600">Summary</dt><dd class="text-gray-900">{{ $record->service_content['summary'] ?? 'No source claim' }}</dd></div>
                                        <div><dt class="font-medium text-gray-600">Notices</dt><dd class="text-gray-900">{{ json_encode($record->service_content['notices'] ?? [], JSON_UNESCAPED_SLASHES) }}</dd></div>
                                        <div><dt class="font-medium text-gray-600">Chapter markers</dt><dd class="text-gray-900">{{ json_encode($record->service_content['chapter_markers'] ?? [], JSON_UNESCAPED_SLASHES) }}</dd></div>
                                    </dl>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </x-card>
        @endforeach
    </div>

    <x-card heading="Final Manual revision">
        <div class="space-y-5">
            <div class="space-y-3">
                @foreach($evidenceReviewItems as $index => $item)
                    @php
                        $assertionOptions = $proposals
                            ->flatMap(fn ($proposal) => $proposal->triggerSourceRecord->assertions)
                            ->filter(fn ($assertion) => mb_strtolower($assertion->title) === mb_strtolower((string) ($item['title'] ?? '')))
                            ->map(fn ($assertion) => [
                                'id' => $assertion->id,
                                'name' => $assertion->sourceRecord->source->value.' — '.$assertion->title,
                            ])
                            ->values()
                            ->all();
                    @endphp
                    <div class="grid gap-3 rounded-lg border border-gray-200 p-4 lg:grid-cols-[auto_1fr_1fr_1fr]" wire:key="manual-review-item-{{ $index }}">
                        <x-toggle label="Include" wire:model="evidenceReviewItems.{{ $index }}.included" />
                        <x-select
                            label="Select source assertion"
                            placeholder="Custom Manual value"
                            wire:model="evidenceReviewItems.{{ $index }}.selected_assertion_id"
                            :options="$assertionOptions"
                        />
                        <x-input
                            label="Final Manual title"
                            dusk="final-manual-title-{{ $index }}"
                            wire:model="evidenceReviewItems.{{ $index }}.title"
                            maxlength="255"
                        />
                        <x-input
                            label="Decision rationale"
                            wire:model="evidenceReviewItems.{{ $index }}.rationale"
                            maxlength="2000"
                            placeholder="Optional explanation"
                        />
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <x-textarea label="Final summary" wire:model="evidenceSummary" autogrow />
                <x-textarea label="Final notices (JSON)" wire:model="evidenceNotices" rows="6" />
                <x-textarea label="Final chapter markers (JSON)" wire:model="evidenceChapterMarkers" rows="6" />
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-600">
                    Only selected proposals are resolved. Unselected and newly arrived proposals stay pending.
                </p>
                <x-form-button
                    type="button"
                    variant="primary"
                    dusk="complete-evidence-review"
                    wire:click="reviewSelectedEvidence"
                    wire:target="reviewSelectedEvidence"
                    wire:loading.attr="disabled"
                    wire:confirm="Complete this review and create a new Manual revision?"
                    loading-label="Saving review..."
                    :disabled="$changedSinceLoad"
                >
                    Complete evidence review
                </x-form-button>
            </div>
        </div>
    </x-card>
</section>
