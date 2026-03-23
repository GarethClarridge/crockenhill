<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StandardProcessingResponse;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;

class GetMediaProcessingStatus
{
    public function __construct(
        private readonly ProcessingLogService $processingLogService,
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
        $baseStatus = $this->get($processingId);

        if (! $baseStatus->found) {
            return $baseStatus;
        }

        if ($baseStatus->processingId === null || $baseStatus->status === null) {
            return $baseStatus;
        }

        return StandardProcessingResponse::withLogs(
            processingId: $baseStatus->processingId,
            status: $baseStatus->status,
            currentStep: $baseStatus->currentStep,
            progressPercentage: $baseStatus->progressPercentage,
            errorMessage: $baseStatus->errorMessage,
            sermonId: $baseStatus->sermonId,
            sermonUrl: $baseStatus->sermonUrl,
            startedAt: $baseStatus->startedAt,
            updatedAt: $baseStatus->updatedAt,
            estimatedCompletion: $baseStatus->estimatedCompletion,
            additionalData: $baseStatus->additionalData,
            logs: $this->processingLogService->getProcessingLogs($processingId, $logLimit),
            metrics: $this->processingLogService->getPerformanceMetrics($processingId),
        );
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
