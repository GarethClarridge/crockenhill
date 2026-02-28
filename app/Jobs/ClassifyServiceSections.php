<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ServiceSectionClassifier;
use App\Services\ServiceSectionSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyServiceSections implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(ServiceSectionClassifier $classifier, ServiceSectionSyncService $syncService): void
    {
        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;

            if ($this->processingLog->isCancelled()) {
                Log::info('ClassifyServiceSections job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            if (! (bool) config('media-processing.section_classification.enabled', true)) {
                $this->processingLog->updateStep('section_classification_skipped');

                Log::info('Service section classification skipped: feature disabled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            $this->processingLog->markAsProcessing('classifying_sections');

            $result = $classifier->classify($this->processingLog);
            $skipped = $result['skipped'];

            if ($skipped) {
                $skipReason = $result['skip_reason'] ?? 'skipped';

                $this->processingLog->updateStep('section_classification_skipped');

                Log::info('Service section classification skipped', [
                    'processing_id' => $this->processingLog->processing_id,
                    'reason' => $skipReason,
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

                $confidenceLevel = $section['metadata']['confidence_level'] ?? null;
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

            $this->processingLog->updateStep('section_classification_complete');

            Log::info('Service section classification completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sections_count' => count($sections),
                'identified_count' => $identifiedCount,
                'manual_review_count' => $manualReviewCount,
                'high_confidence_count' => $highConfidenceCount,
                'low_confidence_count' => $lowConfidenceCount,
                'none_confidence_count' => $noneConfidenceCount,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Service section classification failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $this->processingLog->markAsFailed(
                'Service section classification failed: '.$exception->getMessage(),
                'classifying_sections'
            );

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ClassifyServiceSections job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->processingLog->markAsFailed(
            'Service section classification failed after '.$this->tries.' attempts: '.$exception->getMessage(),
            'classifying_sections'
        );
    }
}
