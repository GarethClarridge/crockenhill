<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\ProcessingStep;
use App\Models\LivestreamSegment as LivestreamSegmentModel;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Processing\SermonProcessingStepTransitions;
use App\Traits\ChecksCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

#[FailOnTimeout]
class AnalyzeSegments implements ShouldQueue
{
    use ChecksCancellation, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VideoSegmentationService $segmentationService,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null,
        ?SermonProcessingStepTransitions $processingStepTransitions = null,
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);
        $processingStepTransitions ??= app(SermonProcessingStepTransitions::class);

        if ($this->abortIfCancelled('AnalyzeSegments')) {
            if ($this->processingLog->isCancelled()) {
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    ProcessingStep::AnalyzingSegments->value,
                    'Processing cancelled before segment analysis.',
                );
            }

            return;
        }

        try {
            // Update status to show segmentation is starting
            $processingRunTransitions->markAsProcessing($this->processingLog, ProcessingStep::Segmentation->value);
            $processingStepTransitions->markAsStarted(
                $this->processingLog->processing_id,
                ProcessingStep::AnalyzingSegments->value,
                'Analyzing RMS segments.',
            );

            Log::info('Starting segment analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $this->processingLog->rms_log_path,
            ]);

            if (! $this->processingLog->rms_log_path) {
                throw new \Exception('RMS log path not found in processing log');
            }

            $analysisResult = $segmentationService->analyzeSegments($this->processingLog->rms_log_path);

            /** @var array<int, LivestreamSegment> $segments */
            $segments = $analysisResult['segments'];
            /** @var array<string, mixed> $thresholdMetadata */
            $thresholdMetadata = $analysisResult['threshold_metadata'];
            /** @var array{frame_count: int, rms_log_path: string}|null $silenceEvidence */
            $silenceEvidence = $analysisResult['silence_evidence'] ?? null;

            if ($silenceEvidence !== null) {
                $this->excludeForSilentAudio($silenceEvidence, $processingStepTransitions);

                return;
            }

            if (empty($segments)) {
                throw new \Exception('No segments found in analysis');
            }

            // Store threshold metadata if available
            if ($thresholdMetadata) {
                $this->storeThresholdMetadata($thresholdMetadata);
            }

            $this->storeSegments($segments);

            $baselineFields = ['current_step' => ProcessingStep::Segmenting->value];

            $sermonBaseline = $this->longestSpeechSegment($segments);
            if ($sermonBaseline !== null) {
                $baselineFields['sermon_start_time'] = $sermonBaseline->startTime;
                $baselineFields['sermon_end_time'] = $sermonBaseline->endTime;
            }

            $processingRunTransitions->updateRunFields($this->processingLog, $baselineFields);
            $processingStepTransitions->markAsCompleted(
                $this->processingLog->processing_id,
                ProcessingStep::AnalyzingSegments->value,
                'RMS segments analyzed.',
            );

        } catch (\Throwable $e) {
            Log::error('Segment analysis failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'Segment analysis failed: '.$e->getMessage());
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                ProcessingStep::AnalyzingSegments->value,
                'Segment analysis failed: '.$e->getMessage(),
            );

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $thresholdMetadata
     */
    private function storeThresholdMetadata(array $thresholdMetadata): void
    {
        $updateData = [
            'threshold_method' => $thresholdMetadata['method'] ?? 'unknown',
        ];

        // Add adaptive threshold value if present
        if (isset($thresholdMetadata['threshold'])) {
            $updateData['adaptive_threshold'] = $thresholdMetadata['threshold'];
        }

        // Add RMS statistics if present
        if (isset($thresholdMetadata['rms_stats'])) {
            $updateData['rms_stats'] = json_encode($thresholdMetadata['rms_stats']);
        }

        $this->processingLog->update($updateData);

        Log::info('Threshold metadata stored', [
            'processing_id' => $this->processingLog->processing_id,
            'threshold_method' => $thresholdMetadata['method'] ?? 'unknown',
            'threshold_value' => $thresholdMetadata['threshold'] ?? null,
        ]);
    }

    /**
     * The recording has no usable audio at all — every RMS sample is digital
     * silence. This is a first-class terminal outcome, not a failure: record
     * the evidence, complete the analyzing_segments step, and send the run
     * straight to cleanup instead of through TranscribeFullService and
     * DetectServiceStructure, which would have nothing to read.
     *
     * The remaining chain is cleared and CleanupTemporaryFiles is dispatched
     * directly — mirroring PrepareSectionPublicationCandidates's standalone
     * completion path — rather than relying on every downstream job (through
     * ExtractSermon, which requires a resolvable sermon baseline) to notice
     * and skip itself. Cleanup's own completion logic then marks the run
     * Completed, matching every other run that legitimately found nothing to
     * extract.
     *
     * @param  array{frame_count: int, rms_log_path: string}  $silenceEvidence
     */
    private function excludeForSilentAudio(
        array $silenceEvidence,
        SermonProcessingStepTransitions $processingStepTransitions,
    ): void {
        $sources = data_get($this->processingLog->processing_metadata?->toArray(), 'historic_import.sources');
        $firstSource = is_array($sources) ? ($sources[0] ?? null) : null;

        $evidence = $silenceEvidence + [
            'source_path' => is_array($firstSource) ? ($firstSource['path'] ?? null) : null,
            'source_sha256' => is_array($firstSource) ? ($firstSource['sha256'] ?? null) : null,
        ];

        $this->processingLog->putSilentAudioExclusion($evidence);

        app(ProcessingNotificationRouter::class)->suppressIfHistoric(
            $this->processingLog,
            'excluded_source_audio_silent',
            'warning',
            $evidence,
        );

        Log::warning('Source audio is digitally silent; excluding run from speech analysis', [
            'processing_id' => $this->processingLog->processing_id,
            'evidence' => $evidence,
        ]);

        $processingStepTransitions->markAsCompleted(
            $this->processingLog->processing_id,
            ProcessingStep::AnalyzingSegments->value,
            'Source audio is digitally silent; run excluded, nothing to extract.',
        );

        $this->chained = [];

        $cleanup = CleanupTemporaryFiles::dispatch($this->processingLog);

        if ($this->processingLog->historic_import_operation_id !== null) {
            $cleanup->onQueue(app(HistoricProcessingThroughput::class)->queueForClass(CleanupTemporaryFiles::class));
        }
    }

    /**
     * @param  array<int, LivestreamSegment>  $segments
     */
    private function storeSegments(array $segments): void
    {
        foreach ($segments as $segmentData) {
            $segmentRecord = [
                'media_processing_log_id' => $this->processingLog->id,
                'segment_index' => $segmentData->segmentOrder,
                'start_time' => $segmentData->startTime,
                'end_time' => $segmentData->endTime,
                'duration' => $segmentData->duration,
                'classification' => $segmentData->classification,
                'avg_rms' => $segmentData->avgRms,
                'peak_rms' => $segmentData->peakRms,
                'is_sermon_candidate' => $segmentData->isSermonCandidate,
                'segment_order' => $segmentData->segmentOrder,
                'metadata' => $segmentData->metadata,
            ];

            LivestreamSegmentModel::query()->updateOrCreate(
                [
                    'media_processing_log_id' => $this->processingLog->id,
                    'segment_index' => $segmentData->segmentOrder,
                ],
                $segmentRecord
            );
        }

        Log::info('Segments stored in database', [
            'processing_id' => $this->processingLog->processing_id,
            'segment_count' => count($segments),
        ]);
    }

    /**
     * The longest speech segment is the coarse RMS sermon-boundary baseline.
     * DetectServiceStructure overwrites it with the validated section's bounds
     * whenever the LLM structure yields an auto-extractable sermon; otherwise
     * SermonExtractionPlanResolver::baselinePlan() reads it, and ExtractSermon's
     * confidence guard re-derives the extraction candidate from the stored
     * speech segments — routing ambiguous runs to manual review. Plausible-length
     * gating therefore belongs to that guard, so this baseline is deliberately
     * unfiltered; runs with no speech at all leave it null and fail downstream,
     * matching the previous no-candidate behaviour.
     *
     * @param  array<int, LivestreamSegment>  $segments
     */
    private function longestSpeechSegment(array $segments): ?LivestreamSegment
    {
        $speechSegments = array_values(array_filter(
            $segments,
            static fn (LivestreamSegment $segment): bool => $segment->isSpeech()
        ));

        if ($speechSegments === []) {
            return null;
        }

        usort(
            $speechSegments,
            static fn (LivestreamSegment $a, LivestreamSegment $b): int => $b->duration <=> $a->duration
        );

        return $speechSegments[0];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeSegments job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $processingLog = $this->processingLog->fresh();
        $processingStepTransitions = app(SermonProcessingStepTransitions::class);

        if ($processingLog instanceof MediaProcessingLog && $processingLog->isCancelled()) {
            $processingStepTransitions->markAsSkipped(
                $this->processingLog->processing_id,
                ProcessingStep::AnalyzingSegments->value,
                'Processing cancelled before the final segment-analysis attempt.',
            );
        } else {
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                ProcessingStep::AnalyzingSegments->value,
                'Segment analysis failed after '.$this->tries.' attempts: '.$exception->getMessage(),
            );
        }

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog,
            'Segment analysis failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }
}
