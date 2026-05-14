<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingStep;

class SermonProcessingStepTransitions
{
    public function markAsStarted(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return SermonProcessingStep::updateOrCreate(
            [
                'processing_id' => $processingId,
                'step' => $step,
            ],
            [
                'status' => ProcessingStatus::Started->value,
                'message' => $message,
                'started_at' => now(),
                'completed_at' => null,
            ]
        );
    }

    public function markAsCompleted(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Completed,
            message: $message,
        );
    }

    public function markAsFailed(string $processingId, string $step, string $errorMessage): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Failed,
            message: $errorMessage,
        );
    }

    public function markAsSkipped(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Skipped,
            message: $message,
        );
    }

    public function markAsCancelled(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        $stepLog = SermonProcessingStep::firstOrNew([
            'processing_id' => $processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => ProcessingStatus::Cancelled->value,
            'message' => $message ?? 'Cancelled by user',
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

        return $stepLog;
    }

    private function fillAndSave(
        string $processingId,
        string $step,
        ProcessingStatus $status,
        ?string $message,
    ): SermonProcessingStep {
        $stepLog = SermonProcessingStep::firstOrNew([
            'processing_id' => $processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => $status->value,
            'message' => $message,
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

        return $stepLog;
    }
}
