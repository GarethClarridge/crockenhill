<x-admin.page
    title="Choose sermon segment"
    description="Confirm which speech segment contains the sermon so processing can continue."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.upload-recording') }}" variant="outline" inline>
            Back to recording upload
        </x-button>
    </x-slot:actions>

    <x-card>
        <div class="mb-5 space-y-1">
            <h2 class="font-display text-lg text-gray-900">{{ $processingLog->original_filename }}</h2>
            @if(($processingLog->manualReviewMetadata()['reason_message'] ?? null) !== null)
                <p class="text-sm text-gray-600">
                    {{ $processingLog->manualReviewMetadata()['reason_message'] }}
                </p>
            @endif
        </div>

        @unless($sourceAvailable)
            <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                The source video is no longer available, so this run cannot be resumed.
            </div>
        @endunless

        @include('livewire.admin.church-services.partials.segment-confirmation', [
            'segments' => $segments,
            'confirmedSegmentId' => $confirmedSegmentId,
            'requiresReview' => $requiresReview,
            'sourceAvailable' => $sourceAvailable,
            'confirming' => false,
            'confirmCall' => fn ($segment): string => "confirmSegment({$segment->id})",
            'returnLink' => [
                'href' => route('admin.services.upload-recording'),
                'label' => 'Return to recording upload.',
            ],
        ])
    </x-card>
</x-admin.page>
