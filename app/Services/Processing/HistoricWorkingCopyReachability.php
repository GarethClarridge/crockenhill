<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\ProcessingStatus;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use Illuminate\Database\Eloquent\Builder;

/**
 * Identifies historic work that still has a reference to a run's working copies.
 *
 * Cleanup and review-source reclamation must agree about this boundary. A queued,
 * running or retryable job can still need the source; a permanently failed nested
 * job is terminal and must be surfaced rather than deferred forever.
 */
final class HistoricWorkingCopyReachability
{
    /**
     * @return array{description: string, terminal: bool}|null
     */
    public function unsettledWork(MediaProcessingLog $processingLog): ?array
    {
        if ($processingLog->historic_import_operation_id === null) {
            return null;
        }

        $prepareJobKey = 'prepare-section-publication-candidates-'.$processingLog->processing_id;
        $prepareJobType = 'App\\Jobs\\PrepareSectionPublicationCandidates';

        $nestedStorage = HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $processingLog->historic_import_operation_id)
            ->where('media_processing_log_id', $processingLog->id)
            // Grouped deliberately: a raw OR is not parenthesised by the builder,
            // so SQL's precedence would bind the state filter to the second
            // branch alone and leave the first unscoped — reading a *completed*
            // store job as unsettled and deferring cleanup until the run failed.
            ->where(function (Builder $query) use ($processingLog, $prepareJobKey, $prepareJobType): void {
                $query
                    ->where(function (Builder $storage) use ($processingLog): void {
                        $storage
                            ->where('job_key', StoreSermonVideo::nestedJobKey($processingLog->processing_id))
                            ->where('job_type', StoreSermonVideo::class);
                    })
                    ->orWhere(function (Builder $prepare) use ($prepareJobKey, $prepareJobType): void {
                        $prepare
                            ->where('job_key', $prepareJobKey)
                            ->where('job_type', $prepareJobType);
                    });
            })
            ->whereIn('state', ['queued', 'running', 'retryable', 'failed'])
            ->first();

        if ($nestedStorage instanceof HistoricImportNestedJob) {
            return [
                'description' => $nestedStorage->job_key.' (state: '.$nestedStorage->state.')',
                'terminal' => $nestedStorage->state === 'failed',
            ];
        }

        /**
         * Only work that is still running can be orphaned by deleting its inputs.
         * A failed step has released them: IdentifySpeaker in particular treats a
         * deterministic failure as non-blocking on purpose -- it records the step
         * failed, falls back to `Visiting Speaker` and lets the chain continue --
         * so reading that row as unsettled would turn a tolerated soft failure
         * into a hard run failure and strand the working copies it was meant to
         * protect.
         */
        $activeStep = SermonProcessingStep::query()
            ->where('processing_id', $processingLog->processing_id)
            ->whereIn('step', ['assessing_video_quality', 'identifying_speaker'])
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ])
            ->first();

        if ($activeStep instanceof SermonProcessingStep) {
            return [
                'description' => $activeStep->step.' (state: '.$activeStep->status->value.')',
                'terminal' => false,
            ];
        }

        return null;
    }
}
