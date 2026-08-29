<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;

/**
 * @phpstan-import-type RetryPlan from ProcessingPhaseRegistry
 */
class ProcessingPhaseResetService
{
    /**
     * @param  RetryPlan  $retryPlan
     */
    public function resetForRetry(MediaProcessingLog $processingLog, array $retryPlan): void
    {
        $resetScope = $retryPlan['reset_scope'] ?? 'none';

        match ($resetScope) {
            'analyze_segments' => $this->resetAnalyzeSegments($processingLog),
            'service_structure_validation' => $this->resetServiceStructureValidation($processingLog),
            'submit_to_processing' => $this->resetSubmitToProcessing($processingLog),
            default => null,
        };
    }

    private function resetServiceStructureValidation(MediaProcessingLog $processingLog): void
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        unset($metadata['manual_review']);

        $processingLog->update(['processing_metadata' => $metadata]);
    }

    private function resetAnalyzeSegments(MediaProcessingLog $processingLog): void
    {
        $processingLog->segments()->delete();

        $processingLog->update([
            'sermon_start_time' => null,
            'sermon_end_time' => null,
            'threshold_method' => null,
            'adaptive_threshold' => null,
            'rms_stats' => null,
        ]);
    }

    private function resetSubmitToProcessing(MediaProcessingLog $processingLog): void
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        unset($metadata['final_video_path'], $metadata['sermon_creation_completed_at']);

        $existingSermon = $processingLog->sermon;

        if (! $existingSermon instanceof Sermon) {
            $existingSermon = Sermon::query()
                ->where('livestream_processing_id', $processingLog->processing_id)
                ->latest('id')
                ->first();
        }

        $attributes = [
            'processing_metadata' => $metadata,
        ];

        if ($existingSermon instanceof Sermon) {
            $attributes['sermon_id'] = $existingSermon->id;
        }

        $processingLog->update($attributes);
    }
}
