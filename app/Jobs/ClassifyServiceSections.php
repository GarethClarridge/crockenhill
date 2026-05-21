<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ServiceSectionClassifier;
use App\Services\ServiceSectionSyncService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyServiceSections extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        private MediaProcessingLog $processingLog,
        private bool $preserveRunStatus = false
    ) {}

    public function handle(ServiceSectionClassifier $classifier, ServiceSectionSyncService $syncService): void
    {
        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;
            $this->startProcessingJob($this->processingLog, $this->job ?? null, $this->attempts());

            if ($this->processingLog->isCancelled()) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS, 'Processing cancelled');

                Log::info('ClassifyServiceSections job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            if (! (bool) config('media-processing.section_classification.enabled', true)) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS, 'Section classification disabled');

                if (! $this->preserveRunStatus) {
                    $this->updateProcessingRunStep($this->processingLog, 'section_classification_skipped');
                }

                Log::info('Service section classification skipped: feature disabled', [
                    'processing_id' => $this->processingLog->processing_id,
                    'preserve_status' => $this->preserveRunStatus,
                ]);

                return;
            }

            $this->logStepStart(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS);

            if (! $this->preserveRunStatus) {
                $this->markProcessingRunAsProcessing($this->processingLog, 'classifying_sections');
            }

            $result = $classifier->classify($this->processingLog);
            $skipped = $result['skipped'];

            if ($skipped) {
                $skipReason = $result['skip_reason'] ?? 'skipped';
                $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS, (string) $skipReason);

                if (! $this->preserveRunStatus) {
                    $this->updateProcessingRunStep($this->processingLog, 'section_classification_skipped');
                }

                Log::info('Service section classification skipped', [
                    'processing_id' => $this->processingLog->processing_id,
                    'reason' => $skipReason,
                    'preserve_status' => $this->preserveRunStatus,
                ]);

                return;
            }

            $sections = $result['sections'];
            $syncService->sync($this->processingLog, $sections);

            $identifiedCount = 0;
            $manualReviewCount = 0;
            $highConfidenceCount = 0;
            $lowConfidenceCount = 0;
            $noneConfidenceCount = 0;

            foreach ($sections as $section) {
                if ($section['status'] === 'identified') {
                    $identifiedCount++;
                }

                if ($section['needs_manual_review'] === true) {
                    $manualReviewCount++;
                }

                $confidenceLevel = $section['metadata']['confidence_level'];
                if ($confidenceLevel === 'high') {
                    $highConfidenceCount++;
                }

                if ($confidenceLevel === 'low') {
                    $lowConfidenceCount++;
                }

                if ($confidenceLevel === 'none') {
                    $noneConfidenceCount++;
                }
            }

            if (! $this->preserveRunStatus) {
                $this->updateProcessingRunStep($this->processingLog, 'section_classification_complete');
            }

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
                sprintf('Stored %d classified section(s)', count($sections))
            );

            Log::info('Service section classification completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sections_count' => count($sections),
                'identified_count' => $identifiedCount,
                'manual_review_count' => $manualReviewCount,
                'high_confidence_count' => $highConfidenceCount,
                'low_confidence_count' => $lowConfidenceCount,
                'none_confidence_count' => $noneConfidenceCount,
                'preserve_status' => $this->preserveRunStatus,
            ]);
        } catch (\Throwable $exception) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepFailed(
                ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
                $exception->getMessage()
            );

            Log::error('Service section classification failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'preserve_status' => $this->preserveRunStatus,
            ]);

            if (! $this->preserveRunStatus) {
                $this->markProcessingRunAsFailed(
                    $this->processingLog,
                    'Service section classification failed: '.$exception->getMessage(),
                    'classifying_sections'
                );
            }

            throw $exception;
        }
    }

    public function preservesRunStatus(): bool
    {
        return $this->preserveRunStatus;
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
            'Service section classification failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        if (! $this->preserveRunStatus) {
            $this->markProcessingRunAsFailed(
                $this->processingLog,
                'Service section classification failed after '.$this->tries.' attempts: '.$exception->getMessage(),
                'classifying_sections'
            );
        }
    }
}
