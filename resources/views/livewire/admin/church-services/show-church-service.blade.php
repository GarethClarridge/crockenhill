<x-admin.page
    :title="$churchService->date->format('l j F Y')"
    :description="$churchService->service->label().' service'"
    :show-heading="false"
>
    @php
        $confirmableSectionCount = collect($sectionReviewPanels)->filter(
            static fn (array $panel): bool => (bool) ($panel['confirmable'] ?? false)
        )->count();
    @endphp

    <x-slot:actions>
        @unless($edit)
            <x-form-button
                type="button"
                variant="outline"
                size="sm"
                icon="pencil-square"
                wire:click="startEditingOrderOfService"
            >
                Edit plan
            </x-form-button>
        @endunless
    </x-slot:actions>

    <x-card class="py-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusSummary->status->badgeClasses() }}">
                        {{ $statusSummary->status->label() }}
                    </span>
                    <span class="text-sm text-gray-500">{{ $planSourceLabel }}</span>
                    <span class="text-sm text-gray-400">Last updated {{ $churchService->updated_at?->format('j F Y, H:i') }}</span>
                </div>
                <p class="max-w-3xl text-sm text-gray-700" @if($statusSummary->status === \App\Enums\ChurchServiceRollupStatus::Processing) wire:poll.5s @endif>
                    {{ $statusSummary->explanation }}
                </p>
            </div>

            @if($statusSummary->actionUrl)
                <x-button :link="$statusSummary->actionUrl" variant="primary" size="sm" icon="arrow-up-tray" inline wire:navigate>
                    {{ $statusSummary->actionLabel }}
                </x-button>
            @elseif($statusSummary->attentionTarget)
                <x-button :link="'#'.$statusSummary->attentionTarget" variant="outline" size="sm" inline>
                    {{ $statusSummary->actionLabel }}
                </x-button>
            @endif
        </div>

        @if($confirmableSectionCount > 0 || ($sectionPublishingEnabled && $pendingApprovalCount > 0))
            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                @if($confirmableSectionCount > 0)
                    <x-form-button
                        type="button"
                        variant="primary"
                        size="sm"
                        wire:click="confirmAllSections({{ $churchService->id }})"
                        wire:target="confirmAllSections({{ $churchService->id }})"
                        wire:loading.attr="disabled"
                        loading-label="Confirming..."
                    >
                        Confirm all remaining ({{ $confirmableSectionCount }})
                    </x-form-button>
                @endif

                @if($sectionPublishingEnabled && $pendingApprovalCount > 0)
                    <x-form-button
                        type="button"
                        variant="primary"
                        size="sm"
                        wire:click="approvePendingPublications({{ $churchService->id }})"
                        wire:target="approvePendingPublications({{ $churchService->id }})"
                        wire:loading.attr="disabled"
                    >
                        Approve pending publications ({{ $pendingApprovalCount }})
                    </x-form-button>
                @endif
            </div>
        @endif
    </x-card>

    @if($evidenceProposals->isNotEmpty())
        @include('livewire.admin.church-services.partials.evidence-review', [
            'proposals' => $evidenceProposals,
            'changedSinceLoad' => $evidenceChangedSinceLoad,
        ])
    @elseif($pendingMerge)
        @include('livewire.admin.church-services.partials.pending-structure-merge', [
            'pendingMerge' => $pendingMerge,
            'pendingMergeSource' => $pendingMergeSource,
        ])
    @endif

    @if($warnings !== [])
        <div class="space-y-2" role="alert">
            @foreach($warnings as $warning)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $warning }}
                </div>
            @endforeach
        </div>
    @endif

    @if($edit)
        <section class="space-y-3" aria-labelledby="edit-plan-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="edit-plan-heading" class="font-display text-2xl text-gray-900">Edit plan</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <x-form-button type="button" variant="outline" size="sm" wire:click="cancelEditingOrderOfService">
                        Cancel
                    </x-form-button>
                    <x-form-button type="button" variant="primary" size="sm" icon="check" wire:click="save" loading-label="Saving...">
                        Save plan
                    </x-form-button>
                </div>
            </div>
            <x-card>
                <x-admin.church-services.planned-items-editor
                    :items="$items"
                    :section-type-options="$sectionTypeOptions"
                    :song-suggestions="$songSuggestions"
                    :linked-song-titles="$linkedSongTitles" />
            </x-card>
        </section>
    @endif

    <section id="service-record" class="space-y-3 scroll-mt-6" aria-labelledby="service-record-heading">
        <div>
            <h2 id="service-record-heading" class="font-display text-2xl text-gray-900">Service record</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-600">{{ $planSourceNote }}</p>
        </div>

        <x-card>
            @if($primaryProcessingRunView)
                @include('livewire.admin.church-services.partials.processing-run-card', [
                    'processingRunView' => $primaryProcessingRunView,
                    'isPrimary' => true,
                ])
            @else
                @include('livewire.admin.church-services.partials.planned-only-list', [
                    'items' => $churchService->items,
                ])
            @endif
        </x-card>
    </section>

    @if($otherProcessingRunViews !== [])
        <details class="rounded-lg border border-gray-200 bg-white">
            <summary class="min-h-11 cursor-pointer px-4 py-3 text-sm font-semibold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal">
                Other uploads ({{ count($otherProcessingRunViews) }})
            </summary>
            <div class="space-y-4 border-t border-gray-200 p-4">
                @foreach($otherProcessingRunViews as $processingRunView)
                    @include('livewire.admin.church-services.partials.processing-run-card', [
                        'processingRunView' => $processingRunView,
                        'isPrimary' => false,
                    ])
                @endforeach
            </div>
        </details>
    @endif

    <details class="rounded-lg border border-gray-200 bg-white">
        <summary class="min-h-11 cursor-pointer px-4 py-3 text-sm font-semibold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal">
            Import details
        </summary>
        <dl class="grid gap-4 border-t border-gray-200 p-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500">Parse method</dt>
                <dd class="font-medium text-gray-900">{{ $importMetadata['parse_method'] ?? 'Unknown' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Confidence</dt>
                <dd class="font-medium text-gray-900">{{ $confidenceScore !== null ? number_format($confidenceScore * 100, 0).'%' : 'Unknown' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Filename mismatch</dt>
                <dd class="font-medium text-gray-900">{{ ($importMetadata['filename_mismatch'] ?? false) ? 'Yes' : 'No' }}</dd>
            </div>
        </dl>
    </details>
</x-admin.page>
