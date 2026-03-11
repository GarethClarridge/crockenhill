<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;

class ChurchServiceReconciliationDispatcher
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $triggerContext
     */
    public function dispatchForChurchService(ChurchService $churchService, array $triggerContext = []): int
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
            $this->recordTriggerContext($processingLog, $triggerContext);

            ReconcileServiceSections::dispatch($processingLog, $churchService)
                ->onQueue($queue);
        }

        return $matchingRuns->count();
    }

    /**
     * @param  array<string, mixed>  $triggerContext
     */
    private function recordTriggerContext(MediaProcessingLog $processingLog, array $triggerContext): void
    {
        if ($triggerContext === []) {
            return;
        }

        $processingMetadata = is_array($processingLog->processing_metadata) ? $processingLog->processing_metadata : [];
        $history = $processingMetadata['reconciliation_triggers'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = array_merge([
            'triggered_at' => now()->toIso8601String(),
        ], $triggerContext);

        $processingMetadata['reconciliation_triggers'] = $history;

        $processingLog->forceFill([
            'processing_metadata' => $processingMetadata,
        ])->saveQuietly();
    }
}
