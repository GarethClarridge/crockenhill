<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;

class ChurchServiceObserver
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    public function created(ChurchService $churchService): void
    {
        $this->dispatchMatchingReconciliations($churchService);
    }

    public function updated(ChurchService $churchService): void
    {
        if (! $churchService->wasChanged(['date', 'service'])) {
            return;
        }

        $this->dispatchMatchingReconciliations($churchService);
    }

    private function dispatchMatchingReconciliations(ChurchService $churchService): void
    {
        $serviceDate = $churchService->date->toDateString();
        $queue = (string) config('media-processing.queues.livestream', 'livestream-processing');

        $matchingRuns = $this->identityResolver
            ->scopeMatchesIdentity(
                MediaProcessingLog::query()->livestream()->completed(),
                $serviceDate,
                $churchService->service
            )
            ->get();

        foreach ($matchingRuns as $processingLog) {
            ReconcileServiceSections::dispatch($processingLog, $churchService)
                ->onQueue($queue);
        }
    }
}
