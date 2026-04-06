<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessingRunOrchestrator
{
    public function __construct(
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingPhaseRegistry $phaseRegistry,
        private readonly ProcessingPhaseResetService $phaseResetService,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
        private readonly VideoStorageService $videoStorageService,
    ) {}

    public function start(MediaProcessingLog $processingLog): void
    {
        match ($processingLog->processing_type) {
            MediaType::Audio => $this->dispatchChain(
                $this->pipelineBuilder->buildAudioPipeline($processingLog),
                $this->audioQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_AUDIO
            ),
            MediaType::Video => $this->dispatchChain(
                $this->pipelineBuilder->buildDirectVideoPipeline($processingLog),
                $this->videoQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_VIDEO
            ),
            MediaType::Livestream => $this->dispatchLivestreamStart($processingLog),
        };
    }

    public function resumeAfterManualReview(MediaProcessingLog $processingLog): void
    {
        $this->dispatchChain(
            $this->pipelineBuilder->buildLivestreamPostReviewChainJobs($processingLog),
            $this->livestreamQueue(),
            $processingLog->processing_id,
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
        );
    }

    public function reclassify(MediaProcessingLog $processingLog): void
    {
        $this->dispatchChain(
            $this->pipelineBuilder->buildSectionReclassificationChainJobs($processingLog),
            $this->livestreamQueue(),
            $processingLog->processing_id,
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
        );
    }

    public function retry(MediaProcessingLog $processingLog): ProcessingResult
    {
        try {
            if (! $processingLog->status->isRetryable()) {
                return ProcessingResult::failure(
                    processingId: $processingLog->processing_id,
                    message: 'Processing is not in failed or cancelled state',
                    errorCode: 'PROCESSING_NOT_FAILED'
                );
            }

            $retryPlan = $this->phaseRegistry->retryPlanFor($processingLog);

            return match ($retryPlan['action']) {
                'dispatch_chain', 'dispatch_livestream_chain' => $this->retryWithChainFromPlan($processingLog, $retryPlan),
                'restart_livestream' => $this->restartLivestream($processingLog),
                'manual_review' => $this->markForManualReviewFromPlan($processingLog, $retryPlan),
            };
        } catch (\Throwable $exception) {
            Log::error('Failed to retry processing run', [
                'processing_id' => $processingLog->processing_id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $message = $exception instanceof ProvidesSafeMessage
                ? $exception->getSafeMessage()
                : 'An internal error occurred while attempting to retry processing.';

            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: "Failed to retry processing: {$message}",
                errorCode: 'RETRY_FAILED'
            );
        }
    }

    public function cancel(MediaProcessingLog $processingLog): bool
    {
        if ($processingLog->status === ProcessingStatus::COMPLETED) {
            return false;
        }

        $cancelled = $this->processingRunTransitions->markAsCancelled(
            $processingLog,
            'Processing cancelled by user'
        );

        if (! $cancelled) {
            return false;
        }

        if ($processingLog->processing_type === MediaType::Livestream) {
            $this->cleanupLivestreamFiles($processingLog->fresh() ?? $processingLog);
        }

        return true;
    }

    /**
     * @param  array<int, object>  $jobs
     */
    private function dispatchChain(array $jobs, string $queueName, string $processingId, string $failureProfile): void
    {
        if (! $this->shouldDispatch($processingId)) {
            return;
        }

        Bus::chain($jobs)
            ->catch(function (\Throwable $exception) use ($processingId, $failureProfile): void {
                app(ProcessingRunFailureHandler::class)->handle($processingId, $exception, $failureProfile);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    private function dispatchLivestreamStart(MediaProcessingLog $processingLog): void
    {
        $queueName = $this->livestreamQueue();
        $processingId = $processingLog->processing_id;
        $parallelJobs = $this->pipelineBuilder->buildLivestreamParallelJobs($processingLog);
        $chainJobs = $this->pipelineBuilder->buildLivestreamChainJobs($processingLog);

        Bus::batch($parallelJobs)
            ->then(function (Batch $batch) use ($chainJobs, $queueName, $processingId): void {
                if (! $this->shouldDispatch($processingId)) {
                    return;
                }

                Bus::chain($chainJobs)
                    ->catch(function (\Throwable $exception) use ($processingId): void {
                        app(ProcessingRunFailureHandler::class)->handle(
                            $processingId,
                            $exception,
                            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
                        );
                    })
                    ->onQueue($queueName)
                    ->dispatch();
            })
            ->catch(function (Batch $batch, \Throwable $exception) use ($processingId): void {
                app(ProcessingRunFailureHandler::class)->handle(
                    $processingId,
                    $exception,
                    ProcessingRunFailureHandler::PROFILE_LIVESTREAM
                );
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    private function retryWithChain(MediaProcessingLog $processingLog, string $pipeline, int $jobOffset): ProcessingResult
    {
        $this->processingRunTransitions->resetForRetry($processingLog);

        $freshLog = $processingLog->fresh() ?? $processingLog;

        $jobs = match ($pipeline) {
            'audio' => array_slice($this->pipelineBuilder->buildAudioPipeline($freshLog), $jobOffset),
            'video' => array_slice($this->pipelineBuilder->buildDirectVideoPipeline($freshLog), $jobOffset),
            'livestream' => array_slice($this->pipelineBuilder->buildLivestreamChainJobs($freshLog), $jobOffset),
            default => [],
        };

        if ($jobs === []) {
            Log::warning('Retry plan resolved to an empty job chain', [
                'processing_id' => $processingLog->processing_id,
                'pipeline' => $pipeline,
                'job_offset' => $jobOffset,
            ]);

            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'No retryable work remained for this processing run',
                errorCode: 'EMPTY_RETRY_PLAN'
            );
        }

        $this->dispatchChain(
            $jobs,
            match ($pipeline) {
                'video' => $this->videoQueue(),
                'livestream' => $this->livestreamQueue(),
                default => $this->audioQueue(),
            },
            $processingLog->processing_id,
            match ($pipeline) {
                'video' => ProcessingRunFailureHandler::PROFILE_VIDEO,
                'livestream' => ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
                default => ProcessingRunFailureHandler::PROFILE_AUDIO,
            }
        );

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    /**
     * @param  array{
     *     action: 'dispatch_chain'|'dispatch_livestream_chain',
     *     pipeline?: 'audio'|'video'|'livestream',
     *     job_offset?: int,
     *     rerun_strategy?: 'safe_to_rerun'|'targeted_reset'|'full_restart',
     *     reset_scope?: 'analyze_segments'|'submit_to_processing'|'none',
     *     reason_code?: string,
     *     reason_message?: string
     * }  $retryPlan
     */
    private function retryWithChainFromPlan(MediaProcessingLog $processingLog, array $retryPlan): ProcessingResult
    {
        $pipeline = $retryPlan['pipeline'] ?? null;
        $jobOffset = $retryPlan['job_offset'] ?? null;

        if (! is_string($pipeline) || ! is_int($jobOffset)) {
            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'Retry plan was incomplete for this processing run',
                errorCode: 'INVALID_RETRY_PLAN'
            );
        }

        $this->phaseResetService->resetForRetry($processingLog, $retryPlan);

        return $this->retryWithChain($processingLog, $pipeline, $jobOffset);
    }

    private function restartLivestream(MediaProcessingLog $processingLog): ProcessingResult
    {
        $this->processingRunTransitions->resetForRetry($processingLog);
        $processingLog->segments()->delete();

        $this->start($processingLog->fresh() ?? $processingLog);

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    private function markForManualReview(
        MediaProcessingLog $processingLog,
        string $reasonCode,
        string $reasonMessage
    ): ProcessingResult {
        $this->processingRunTransitions->resetForRetry($processingLog);
        $this->processingRunTransitions->updateStep($processingLog, 'restarting_from_beginning');
        $this->processingRunTransitions->markForManualReview($processingLog, $reasonCode, $reasonMessage);

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    /**
     * @param  array{
     *     action: 'manual_review',
     *     pipeline?: 'audio'|'video'|'livestream',
     *     job_offset?: int,
     *     rerun_strategy?: 'safe_to_rerun'|'targeted_reset'|'full_restart',
     *     reset_scope?: 'analyze_segments'|'submit_to_processing'|'none',
     *     reason_code?: string,
     *     reason_message?: string
     * }  $retryPlan
     */
    private function markForManualReviewFromPlan(MediaProcessingLog $processingLog, array $retryPlan): ProcessingResult
    {
        $reasonCode = $retryPlan['reason_code'] ?? null;
        $reasonMessage = $retryPlan['reason_message'] ?? null;

        if (! is_string($reasonCode) || ! is_string($reasonMessage)) {
            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'Retry plan was incomplete for this processing run',
                errorCode: 'INVALID_RETRY_PLAN'
            );
        }

        return $this->markForManualReview($processingLog, $reasonCode, $reasonMessage);
    }

    private function cleanupLivestreamFiles(MediaProcessingLog $processingLog): void
    {
        $tempFiles = [];

        if (is_string($processingLog->source_file_path) && $processingLog->source_file_path !== '') {
            $tempFiles[] = $processingLog->source_file_path;
        }

        $metadata = $processingLog->processing_metadata?->toArray() ?? [];

        foreach (['extracted_segment_path', 'extracted_audio_path', 'temp_video_path'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                $tempFiles[] = $metadata[$key];
            }
        }

        if ($tempFiles !== []) {
            $this->videoStorageService->cleanupTemporaryFiles($tempFiles);
        }
    }

    private function audioQueue(): string
    {
        return (string) config('media-processing.queues.audio', 'audio-processing');
    }

    private function videoQueue(): string
    {
        return (string) config('media-processing.queues.video', config('media-processing.types.video.queue', 'video-processing'));
    }

    private function livestreamQueue(): string
    {
        return (string) config('media-processing.queues.livestream', 'livestream-processing');
    }

    private function shouldDispatch(string $processingId): bool
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return false;
        }

        if ($processingLog->isCancelled()) {
            Log::info('Skipping downstream dispatch for cancelled processing run', [
                'processing_id' => $processingId,
            ]);

            return false;
        }

        return true;
    }
}
