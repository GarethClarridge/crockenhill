<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\StandardProcessingResponse;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;

class GetMediaProcessingStatus
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function get(string $processingId): StandardProcessingResponse
    {
        $log = $this->find($processingId);

        if ($log === null) {
            return StandardProcessingResponse::notFound();
        }

        return StandardProcessingResponse::fromProcessingLog($log);
    }

    public function getWithLogs(string $processingId, int $logLimit = 20): StandardProcessingResponse
    {
        $log = $this->find($processingId);

        if ($log === null) {
            return StandardProcessingResponse::notFound();
        }

        $log->load('processingSteps');
        $diagnostics = [
            'processing_steps' => $this->processingSteps($log, $logLimit),
        ];

        if ($log->processing_metadata !== null) {
            $diagnostics['processing_metadata'] = $log->processing_metadata->toArray();
        }

        foreach (['queue_name', 'job_id', 'attempt_count'] as $column) {
            if ($log->{$column} !== null) {
                $diagnostics[$column] = $log->{$column};
            }
        }

        $status = StandardProcessingResponse::fromProcessingLog($log);

        return StandardProcessingResponse::found(
            processingId: $log->processing_id,
            status: $log->status->value,
            currentStep: $status->currentStep,
            progressPercentage: $status->progressPercentage,
            errorMessage: $status->errorMessage,
            sermonId: $status->sermonId,
            sermonUrl: $status->sermonUrl,
            startedAt: $status->startedAt,
            updatedAt: $status->updatedAt,
            estimatedCompletion: $status->estimatedCompletion,
            additionalData: array_merge($status->additionalData, $diagnostics),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function processingSteps(MediaProcessingLog $log, int $limit): array
    {
        return $log->processingSteps
            ->sortByDesc(fn (SermonProcessingStep $step): int => $step->started_at?->getTimestamp()
                ?? $step->updated_at->getTimestamp())
            ->take(max(1, min($limit, 100)))
            ->values()
            ->map(fn (SermonProcessingStep $step): array => [
                'step' => $step->step,
                'status' => $step->status->value,
                'message' => $step->message,
                'started_at' => $step->started_at?->toISOString(),
                'completed_at' => $step->completed_at?->toISOString(),
                'duration_seconds' => $step->started_at !== null && $step->completed_at !== null
                    ? $step->completed_at->diffInSeconds($step->started_at)
                    : null,
            ])
            ->all();
    }

    public function canHandle(string $processingId): bool
    {
        return $this->find($processingId) !== null;
    }

    public function find(string $processingId): ?MediaProcessingLog
    {
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->first();
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function processingLogQuery(): Builder
    {
        $query = MediaProcessingLog::query();
        $user = $this->auth->user();

        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query;
    }
}
