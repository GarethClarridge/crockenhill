<x-admin.page
    title="Evidence proposal queue"
    description="Review recurring evidence ambiguities once, with every affected proposal explicitly enumerated."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" icon="arrow-left" inline>
            Services
        </x-button>
    </x-slot:actions>

    @if($classes === [])
        <x-card>
            <div class="space-y-2 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50 p-8 text-center" role="status">
                <h2 class="font-display text-xl text-gray-900">No pending evidence classes</h2>
                <p class="text-sm text-gray-600">New evidence proposals will appear here after projection.</p>
            </div>
        </x-card>
    @else
        <x-card class="mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3" role="status" dusk="census-gate">
                <div class="space-y-1">
                    <h2 class="font-display text-lg text-gray-900">Review-load gate</h2>
                    <p class="text-sm text-gray-600">
                        {{ $gate['class_count'] }} class{{ $gate['class_count'] === 1 ? '' : 'es' }}
                        covering {{ $gate['proposal_count'] }} proposal{{ $gate['proposal_count'] === 1 ? '' : 's' }}.
                        @if($gate['residual_decisions'] > 0)
                            Residual hand review: {{ $gate['residual_decisions'] }} decision{{ $gate['residual_decisions'] === 1 ? '' : 's' }}{{ is_null($gate['residual_seconds']) ? '.' : ', about ' . (int) ceil($gate['residual_seconds'] / 60) . ' min measured.' }}
                        @else
                            No class is marked irreducible yet.
                        @endif
                    </p>
                </div>
                @if($gate['passes'])
                    <x-badge variant="success">Every class accounted for</x-badge>
                @else
                    <x-badge variant="warning">{{ count($gate['unclassified']) }} unaccounted</x-badge>
                @endif
            </div>
        </x-card>

        <div
            class="space-y-4"
            wire:loading.class="opacity-60"
            wire:target="selectClass,toggleProposal,clearSelection,applyDecisionRule,startMarkingClass,markClass"
            wire:loading.attr="aria-busy"
        >
            @foreach($classes as $class)
                <x-card wire:key="proposal-class-{{ $class['class_key'] }}">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <h2 class="font-display text-xl break-words text-gray-900">{{ $class['subject'] }}</h2>
                                <p class="text-sm text-gray-600">
                                    {{ $class['occurrence_count'] }} proposal{{ $class['occurrence_count'] === 1 ? '' : 's' }}
                                    across {{ $class['service_count'] }} service{{ $class['service_count'] === 1 ? '' : 's' }}
                                    · Match tier {{ $class['match_tier'] ?? 'unclassified' }}
                                </p>
                                @if($class['conflict_kinds'] !== [])
                                    <p class="flex flex-wrap gap-1 pt-1">
                                        @foreach(array_unique($class['conflict_kinds']) as $kind)
                                            <x-badge variant="info" size="xs">{{ str_replace('_', ' ', $kind) }}</x-badge>
                                        @endforeach
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($class['status'] === \App\Models\ChurchServiceProposalClassReview::AUTOMATED)
                                    <x-badge variant="success">Automated</x-badge>
                                @elseif($class['status'] === \App\Models\ChurchServiceProposalClassReview::IRREDUCIBLE)
                                    <x-badge variant="amber">Irreducible</x-badge>
                                @else
                                    <x-badge variant="warning">Unaccounted</x-badge>
                                @endif
                                <x-form-button
                                    wire:click="startMarkingClass('{{ $class['class_key'] }}')"
                                    dusk="mark-class-{{ $class['class_key'] }}"
                                    variant="outline"
                                    size="xs"
                                >
                                    Record standing
                                </x-form-button>
                                <x-form-button
                                    wire:click="selectClass('{{ $class['class_key'] }}')"
                                    dusk="select-class-{{ $class['class_key'] }}"
                                    variant="outline"
                                    size="xs"
                                >
                                    Select all {{ $class['occurrence_count'] }}
                                </x-form-button>
                            </div>
                        </div>

                        @if($class['reason'] !== null)
                            <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                                <span class="font-medium">Recorded reason:</span> {{ $class['reason'] }}
                                @if($class['seconds_per_decision'] !== null)
                                    <span class="text-gray-500">({{ $class['seconds_per_decision'] }}s per decision)</span>
                                @endif
                            </p>
                        @endif

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="space-y-2">
                                <h3 class="text-sm font-semibold text-gray-900">Affected services</h3>
                                <p class="flex flex-wrap gap-1">
                                    @foreach(array_slice($class['service_identities'], 0, 12) as $identity)
                                        @php([$identityDate, $identityService] = explode('|', $identity))
                                        <x-badge size="xs">{{ \Illuminate\Support\Carbon::parse($identityDate)->format('j M Y') }} {{ $identityService }}</x-badge>
                                    @endforeach
                                    @if(count($class['service_identities']) > 12)
                                        <x-badge size="xs" variant="info">+{{ count($class['service_identities']) - 12 }} more</x-badge>
                                    @endif
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h3 class="text-sm font-semibold text-gray-900">Candidate resolutions</h3>
                                @if($class['candidate_resolutions'] === [])
                                    <p class="text-sm text-gray-500">The projector recorded no candidate for this class.</p>
                                @else
                                    <ul class="list-inside list-disc space-y-1 text-sm text-gray-700">
                                        @foreach($class['candidate_resolutions'] as $candidate)
                                            <li class="break-words">{{ $candidate }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h3 class="text-sm font-semibold text-gray-900">Representative evidence</h3>
                            @foreach($class['representative_evidence'] as $evidence)
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <x-checkbox
                                            wire:click="toggleProposal('{{ $class['class_key'] }}', {{ $evidence['proposal_id'] }})"
                                            dusk="proposal-{{ $evidence['proposal_id'] }}"
                                            :checked="in_array($evidence['proposal_id'], $selectedProposalIds, true)"
                                            :label="'Proposal ' . $evidence['proposal_id']"
                                        />
                                        <span class="font-mono text-xs break-all text-gray-500">{{ $evidence['identity'] }}</span>
                                    </div>
                                    @if($evidence['conflicts'] !== [])
                                        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-gray-600">{{ json_encode($evidence['conflicts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                </div>
                            @endforeach
                            @if($class['occurrence_count'] > count($class['representative_evidence']))
                                <p class="text-xs text-gray-500">
                                    Showing {{ count($class['representative_evidence']) }} of {{ $class['occurrence_count'] }} proposals.
                                    "Select all" enumerates every one of them.
                                </p>
                            @endif
                        </div>

                        @if($markClassKey === $class['class_key'])
                            <div class="space-y-4 rounded-lg border border-amber-300 bg-amber-50/60 p-4">
                                <h3 class="font-semibold text-gray-900">Record this class's standing</h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-select wire:model.live="markStatus" dusk="markStatus" label="Standing" :options="[
                                        ['id' => \App\Models\ChurchServiceProposalClassReview::AUTOMATED, 'name' => 'Automated — a matcher change settles it'],
                                        ['id' => \App\Models\ChurchServiceProposalClassReview::IRREDUCIBLE, 'name' => 'Irreducible — a human decides each one'],
                                    ]" />
                                    @if($markStatus === \App\Models\ChurchServiceProposalClassReview::IRREDUCIBLE)
                                        <x-input
                                            type="number"
                                            wire:model="markSecondsPerDecision"
                                            dusk="markSecondsPerDecision"
                                            label="Measured seconds per decision"
                                            min="1"
                                            max="86400"
                                        />
                                    @endif
                                </div>
                                <x-textarea wire:model="markReason" dusk="markReason" label="Reason" rows="2" placeholder="Why this class is automated or irreducible." />

                                @error('markReason')
                                    <p class="text-sm text-rose-700" role="alert">{{ $message }}</p>
                                @enderror
                                @error('markSecondsPerDecision')
                                    <p class="text-sm text-rose-700" role="alert">{{ $message }}</p>
                                @enderror

                                <x-form-button wire:click="markClass" dusk="save-class-standing" variant="primary" icon="check" loading-label="Recording…">
                                    Record standing
                                </x-form-button>
                            </div>
                        @endif

                        @if($selectedClassKey === $class['class_key'])
                            <div class="space-y-4 rounded-lg border border-cbc-teal/30 bg-cbc-teal/5 p-4" aria-label="Selected proposal rule">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Apply an explicit rule</h3>
                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ count($selectedProposalIds) }} of {{ $class['occurrence_count'] }} proposal{{ $class['occurrence_count'] === 1 ? '' : 's' }} selected.
                                            The rule records each proposal identity and settles every service it touches.
                                        </p>
                                    </div>
                                    <x-form-button wire:click="clearSelection" variant="outline" size="xs">
                                        Clear selection
                                    </x-form-button>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-select wire:model="disposition" label="Disposition" :options="[
                                        ['id' => 'accepted', 'name' => 'Accept — adopt the proposed projection'],
                                        ['id' => 'rejected', 'name' => 'Reject — keep the current canonical items'],
                                    ]" />
                                    <x-textarea wire:model="rationale" dusk="rationale" label="Rationale" rows="3" placeholder="Explain why this class shares one decision." />
                                </div>

                                @error('selectedProposalIds')
                                    <p class="text-sm text-rose-700" role="alert">{{ $message }}</p>
                                @enderror
                                @error('rationale')
                                    <p class="text-sm text-rose-700" role="alert">{{ $message }}</p>
                                @enderror

                                <x-form-button
                                    wire:click="applyDecisionRule"
                                    dusk="apply-decision-rule"
                                    variant="primary"
                                    icon="check"
                                    loading-label="Applying…"
                                >
                                    Apply rule to selected proposals
                                </x-form-button>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</x-admin.page>
