@php
    /** @var \App\Data\ChurchServiceProcessingRunView $processingRunView */

    $run = $processingRunView->run;
@endphp

<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <p class="text-sm font-medium">Run {{ $run->processing_id }}</p>
        <p class="text-xs text-gray-500">
            Status: {{ $run->status->label() }} · Updated {{ $run->updated_at?->diffForHumans() ?? 'Unknown' }}
        </p>
    </div>

    <div class="flex flex-wrap gap-2">
        <x-form-button
            type="button"
            variant="danger"
            size="sm"
            icon="trash"
            wire:click="deleteUpload({{ $run->id }})"
            wire:target="deleteUpload({{ $run->id }})"
            wire:confirm="Delete this livestream upload? This will remove the processing run, projected service items, and any sermons or assets created from it."
        >
            Delete upload
        </x-form-button>
    </div>
</div>
