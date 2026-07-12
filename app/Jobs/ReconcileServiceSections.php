<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ServiceStructureMode;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\OosAlignmentService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileServiceSections implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly MediaProcessingLog $processingLog,
        public readonly ChurchService $churchService,
    ) {}

    public function handle(
        MediaProcessingIdentityResolver $identityResolver,
        OosAlignmentService $alignmentService,
    ): void {
        $processingLog = $this->processingLog->fresh();
        $churchService = $this->churchService->fresh();

        if (! $processingLog instanceof MediaProcessingLog || ! $churchService instanceof ChurchService) {
            return;
        }

        if ($processingLog->processing_type !== MediaType::Livestream || ! $processingLog->isComplete()) {
            return;
        }

        if (! $identityResolver->matchesService($processingLog, $churchService->date->toDateString(), $churchService->service)) {
            return;
        }

        if ($processingLog->church_service_id !== $churchService->id) {
            $processingLog->forceFill([
                'church_service_id' => $churchService->id,
            ])->saveQuietly();
            $processingLog->unsetRelation('churchService');
        }

        if (! $churchService->items()->exists()) {
            Log::info('Service section reconciliation deferred: church service has no items yet', [
                'processing_id' => $processingLog->processing_id,
                'church_service_id' => $churchService->id,
            ]);

            return;
        }

        // In primary mode the LLM structure owns OoS anchoring; letting the
        // heuristic aligner rewrite it would degrade the run. Re-detect from
        // the stored transcript artifact with the newly-arrived items instead.
        // Legacy runs without an artifact (or non-primary modes) still take
        // the heuristic path until the cluster retires.
        if (ServiceStructureMode::fromConfig() === ServiceStructureMode::Primary
            && $processingLog->hasStoredServiceTranscript()) {
            DetectServiceStructure::dispatch($processingLog, true)
                ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

            Log::info('Service section reconciliation delegated to structure re-detection', [
                'processing_id' => $processingLog->processing_id,
                'church_service_id' => $churchService->id,
            ]);

            return;
        }

        $result = $alignmentService->alignForProcessingLog($processingLog, $churchService, lateArrival: true);

        Log::info('Service section reconciliation completed', [
            'processing_id' => $processingLog->processing_id,
            'church_service_id' => $churchService->id,
            'aligned' => $result['aligned'],
            'review_triggers' => $result['review_triggers'],
        ]);
    }

    public function uniqueId(): string
    {
        return $this->processingLog->getKey().'-'.$this->churchService->getKey();
    }

    public function processingLogId(): int
    {
        return $this->processingLog->getKey();
    }

    public function churchServiceId(): int
    {
        return $this->churchService->getKey();
    }
}
