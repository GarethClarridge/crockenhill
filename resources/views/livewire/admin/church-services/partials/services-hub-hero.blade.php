{{-- "This Sunday" hero card: expects $heroService, $heroRollup, $heroIsCurrent --}}
<x-card class="border-l-4 border-l-cbc-teal">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                {{ $heroIsCurrent ? 'This Sunday' : 'Most recent service' }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display text-2xl text-gray-900">
                    {{ $heroService->date->format('j M Y') }} — {{ $heroService->service->label() }}
                </h2>
                @if($heroRollup !== null)
                    @php $heroNeedsReview = $heroRollup['status'] === \App\Enums\ChurchServiceRollupStatus::NeedsReview; @endphp
                    <x-badge
                        :variant="match($heroRollup['status']) {
                            \App\Enums\ChurchServiceRollupStatus::NeedsReview => 'warning',
                            \App\Enums\ChurchServiceRollupStatus::Processing => 'sky',
                            \App\Enums\ChurchServiceRollupStatus::ProcessingFailed => 'danger',
                            \App\Enums\ChurchServiceRollupStatus::Ready => 'teal',
                            \App\Enums\ChurchServiceRollupStatus::Published => 'success',
                            default => 'default',
                        }"
                        size="xs"
                        :pulse="$heroNeedsReview"
                    >
                        {{ $heroRollup['status']->label() . ($heroNeedsReview ? " ({$heroRollup['attention_count']})" : '') }}
                    </x-badge>
                @endif
            </div>
            <p class="text-sm text-gray-600">
                {{ $heroService->items_count ?? $heroService->items->count() }} planned {{ \Illuminate\Support\Str::plural('item', $heroService->items_count ?? $heroService->items->count()) }}
                @if($heroRollup !== null)
                    · {{ $heroRollup['run_count'] }} {{ \Illuminate\Support\Str::plural('processing run', $heroRollup['run_count']) }}
                @endif
            </p>
            @if($heroRollup !== null)
                <x-admin.pipeline-steps :steps="$heroRollup['steps']" class="pt-1" />
            @endif
        </div>
        <x-button link="{{ route('admin.services.show', $heroService) }}" variant="outline" icon="arrow-right" iconPosition="trailing" inline>
            Open service
        </x-button>
    </div>
</x-card>
