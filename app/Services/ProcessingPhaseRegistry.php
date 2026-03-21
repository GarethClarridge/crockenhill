<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;

class ProcessingPhaseRegistry
{
    /**
     * @return list<array{
     *     key: string,
     *     progress: int,
     *     job_offset: int,
     *     steps: list<string>
     * }>
     */
    public function phasesForPipeline(string $pipeline): array
    {
        return match ($pipeline) {
            'audio' => $this->audioPhases(),
            'video' => $this->videoPhases(),
            'livestream' => $this->livestreamPhases(),
            default => [],
        };
    }

    public function progressForStep(?string $step, ?MediaType $mediaType = null): int
    {
        $normalizedStep = $this->normalizeStep($step);

        if ($normalizedStep === null) {
            return 50;
        }

        $pipelines = ['audio', 'video', 'livestream'];

        if ($mediaType !== null) {
            $preferredPipeline = $this->pipelineForMediaType($mediaType);
            $pipelines = array_values(array_unique([$preferredPipeline, ...$pipelines]));
        }

        foreach ($pipelines as $pipeline) {
            foreach ($this->phasesForPipeline($pipeline) as $phase) {
                if (in_array($normalizedStep, $phase['steps'], true)) {
                    return $phase['progress'];
                }
            }
        }

        return 50;
    }

    public function progressForLog(MediaProcessingLog $processingLog): int
    {
        return $this->progressForStep($processingLog->current_step, $processingLog->processing_type);
    }

    /**
     * @return array{
     *     action: 'dispatch_chain'|'restart_livestream'|'manual_review',
     *     pipeline?: 'audio'|'video',
     *     job_offset?: int,
     *     reason_code?: string,
     *     reason_message?: string
     * }
     */
    public function retryPlanFor(MediaProcessingLog $processingLog): array
    {
        $normalizedStep = $this->normalizeStep($processingLog->current_step);

        if ($processingLog->processing_type === MediaType::Livestream) {
            if (in_array($normalizedStep, ['preparing', 'retry_initiated'], true)) {
                return $this->manualReviewPlan(
                    reasonCode: 'early_processing_failure',
                    reasonMessage: sprintf(
                        'Early processing failure detected. Source type: %s. File may need to be re-uploaded. Original filename: %s.',
                        $processingLog->processing_type->value,
                        $processingLog->original_filename
                    )
                );
            }

            // Livestream retries always restart the segmented pipeline, even when
            // the run has already reached downstream sermon-processing steps. The
            // child-audio work depends on upstream extracted artifacts and review
            // state that are safer to rebuild than to resume piecemeal.
            return ['action' => 'restart_livestream'];
        }

        if (in_array($normalizedStep, ['preparing', 'retry_initiated'], true)) {
            return $this->manualReviewPlan(
                reasonCode: 'early_processing_failure',
                reasonMessage: sprintf(
                    'Early processing failure detected. Source type: %s. File may need to be re-uploaded. Original filename: %s.',
                    $processingLog->processing_type->value,
                    $processingLog->original_filename
                )
            );
        }

        if (in_array($normalizedStep, ['creating_sermon_record', 'creating_sermon_record_failed'], true)) {
            return $this->manualReviewPlan(
                reasonCode: 'sermon_record_creation_failed',
                reasonMessage: 'Failed during sermon record creation — requires manual intervention.'
            );
        }

        $pipeline = $processingLog->processing_type === MediaType::Video ? 'video' : 'audio';

        foreach ($this->phasesForPipeline($pipeline) as $phase) {
            if (in_array($normalizedStep, $phase['steps'], true)) {
                return [
                    'action' => 'dispatch_chain',
                    'pipeline' => $pipeline,
                    'job_offset' => $phase['job_offset'],
                ];
            }
        }

        return $this->manualReviewPlan(
            reasonCode: 'unknown_processing_step',
            reasonMessage: sprintf('Unknown processing step: %s.', $processingLog->current_step ?? 'unknown')
        );
    }

    /**
     * @return array{
     *     action: 'manual_review',
     *     reason_code: string,
     *     reason_message: string
     * }
     */
    private function manualReviewPlan(string $reasonCode, string $reasonMessage): array
    {
        return [
            'action' => 'manual_review',
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
        ];
    }

    private function normalizeStep(?string $step): ?string
    {
        if ($step === null || $step === '') {
            return null;
        }

        if (str_starts_with($step, 'initiated_from_livestream:')) {
            return 'initiated_from_livestream';
        }

        return $step;
    }

    private function pipelineForMediaType(MediaType $mediaType): string
    {
        return match ($mediaType) {
            MediaType::Audio => 'audio',
            MediaType::Video => 'video',
            MediaType::Livestream => 'livestream',
        };
    }

    /**
     * @return list<array{
     *     key: string,
     *     progress: int,
     *     job_offset: int,
     *     steps: list<string>
     * }>
     */
    private function audioPhases(): array
    {
        return [
            [
                'key' => 'initiated_from_livestream',
                'progress' => 10,
                'job_offset' => 0,
                'steps' => [
                    'initiated_from_livestream',
                ],
            ],
            [
                'key' => 'audio_initiated',
                'progress' => 10,
                'job_offset' => 0,
                'steps' => [
                    'audio_processing_initiated',
                ],
            ],
            [
                'key' => 'validate_audio',
                'progress' => 15,
                'job_offset' => 0,
                'steps' => [
                    'validating',
                    'audio_validation_complete',
                ],
            ],
            [
                'key' => 'create_sermon_record',
                'progress' => 60,
                'job_offset' => 1,
                'steps' => [
                    'sermon_creation',
                    'creating_sermon',
                    'creating_sermon_record',
                    'sermon_record_created',
                ],
            ],
            [
                'key' => 'transcribe_audio',
                'progress' => 70,
                'job_offset' => 3,
                'steps' => [
                    'transcribing_audio',
                    'transcribing_audio_failed',
                    'transcription_completed',
                    'transcription',
                ],
            ],
            [
                'key' => 'analyze_transcript',
                'progress' => 85,
                'job_offset' => 4,
                'steps' => [
                    'analyzing_transcript',
                    'analyzing_transcript_failed',
                    'ai_analysis_completed',
                    'ai_analysis_fallback',
                ],
            ],
            [
                'key' => 'update_sermon_record',
                'progress' => 87,
                'job_offset' => 5,
                'steps' => [
                    'updating_sermon_record',
                    'updating_sermon_record_failed',
                ],
            ],
            [
                'key' => 'send_notification',
                'progress' => 92,
                'job_offset' => 5,
                'steps' => [
                    'sending_notification',
                ],
            ],
            [
                'key' => 'notification_complete',
                'progress' => 93,
                'job_offset' => 5,
                'steps' => [
                    'notification_sent',
                    'notification_skipped',
                    'notification_failed',
                    'notification_failed_permanently',
                ],
            ],
            [
                'key' => 'cleanup',
                'progress' => 95,
                'job_offset' => 6,
                'steps' => [
                    'cleanup',
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     progress: int,
     *     job_offset: int,
     *     steps: list<string>
     * }>
     */
    private function videoPhases(): array
    {
        return [
            [
                'key' => 'video_initiated',
                'progress' => 10,
                'job_offset' => 0,
                'steps' => [
                    'video_processing_initiated',
                ],
            ],
            [
                'key' => 'validate_video',
                'progress' => 15,
                'job_offset' => 0,
                'steps' => [
                    'validating',
                    'video_validation_complete',
                ],
            ],
            [
                'key' => 'extract_audio',
                'progress' => 25,
                'job_offset' => 1,
                'steps' => [
                    'extracting_audio',
                    'audio_extraction_complete',
                ],
            ],
            [
                'key' => 'create_sermon_record',
                'progress' => 60,
                'job_offset' => 2,
                'steps' => [
                    'sermon_creation',
                    'creating_sermon',
                    'creating_sermon_record',
                    'sermon_record_created',
                ],
            ],
            [
                'key' => 'transcribe_audio',
                'progress' => 70,
                'job_offset' => 4,
                'steps' => [
                    'transcribing_audio',
                    'transcribing_audio_failed',
                    'transcription_completed',
                    'transcription',
                ],
            ],
            [
                'key' => 'analyze_transcript',
                'progress' => 85,
                'job_offset' => 5,
                'steps' => [
                    'analyzing_transcript',
                    'analyzing_transcript_failed',
                    'ai_analysis_completed',
                    'ai_analysis_fallback',
                ],
            ],
            [
                'key' => 'generate_thumbnail',
                'progress' => 88,
                'job_offset' => 6,
                'steps' => [
                    'generating_thumbnail',
                ],
            ],
            [
                'key' => 'update_sermon_record',
                'progress' => 90,
                'job_offset' => 7,
                'steps' => [
                    'updating_sermon_record',
                    'updating_sermon_record_failed',
                ],
            ],
            [
                'key' => 'send_notification',
                'progress' => 92,
                'job_offset' => 7,
                'steps' => [
                    'sending_notification',
                ],
            ],
            [
                'key' => 'notification_complete',
                'progress' => 93,
                'job_offset' => 7,
                'steps' => [
                    'notification_sent',
                    'notification_skipped',
                    'notification_failed',
                    'notification_failed_permanently',
                ],
            ],
            [
                'key' => 'cleanup',
                'progress' => 95,
                'job_offset' => 8,
                'steps' => [
                    'cleanup',
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     progress: int,
     *     job_offset: int,
     *     steps: list<string>
     * }>
     */
    private function livestreamPhases(): array
    {
        return [
            [
                'key' => 'parallel_start',
                'progress' => 10,
                'job_offset' => 0,
                'steps' => [
                    'livestream_processing_initiated',
                    'visual_analysis',
                    'restarting_from_beginning',
                ],
            ],
            [
                'key' => 'rms_generation',
                'progress' => 20,
                'job_offset' => 0,
                'steps' => [
                    'rms_generation',
                ],
            ],
            [
                'key' => 'analyze_segments',
                'progress' => 30,
                'job_offset' => 0,
                'steps' => [
                    'segmentation',
                ],
            ],
            [
                'key' => 'segment_analysis',
                'progress' => 40,
                'job_offset' => 0,
                'steps' => [
                    'segmenting',
                    'analyzing_segments',
                ],
            ],
            [
                'key' => 'classify_sections',
                'progress' => 45,
                'job_offset' => 1,
                'steps' => [
                    'classifying_sections',
                ],
            ],
            [
                'key' => 'classified_sections',
                'progress' => 52,
                'job_offset' => 1,
                'steps' => [
                    'section_classification_complete',
                    'section_classification_skipped',
                ],
            ],
            [
                'key' => 'manual_review',
                'progress' => 53,
                'job_offset' => 5,
                'steps' => [
                    'manual_review_required',
                ],
            ],
            [
                'key' => 'manual_review_confirmed',
                'progress' => 54,
                'job_offset' => 5,
                'steps' => [
                    'manual_review_confirmed',
                ],
            ],
            [
                'key' => 'extract_sermon',
                'progress' => 55,
                'job_offset' => 5,
                'steps' => [
                    'extraction',
                    'extracting_sermon',
                ],
            ],
            [
                'key' => 'extraction_complete',
                'progress' => 58,
                'job_offset' => 5,
                'steps' => [
                    'extraction_complete',
                ],
            ],
            [
                'key' => 'submit_to_processing',
                'progress' => 60,
                'job_offset' => 6,
                'steps' => [
                    'sermon_submitted',
                    'sermon_creation',
                ],
            ],
            [
                'key' => 'transcription',
                'progress' => 70,
                'job_offset' => 8,
                'steps' => [
                    'transcribing_audio',
                    'transcribing_audio_failed',
                    'transcription_completed',
                    'transcription',
                ],
            ],
            [
                'key' => 'analysis',
                'progress' => 85,
                'job_offset' => 9,
                'steps' => [
                    'analyzing_transcript',
                    'analyzing_transcript_failed',
                    'ai_analysis_completed',
                    'ai_analysis_fallback',
                ],
            ],
            [
                'key' => 'thumbnail',
                'progress' => 90,
                'job_offset' => 10,
                'steps' => [
                    'generating_thumbnail',
                ],
            ],
            [
                'key' => 'send_notification',
                'progress' => 92,
                'job_offset' => 12,
                'steps' => [
                    'sending_notification',
                ],
            ],
            [
                'key' => 'notification_complete',
                'progress' => 93,
                'job_offset' => 12,
                'steps' => [
                    'notification_sent',
                    'notification_skipped',
                    'notification_failed',
                    'notification_failed_permanently',
                ],
            ],
            [
                'key' => 'cleanup',
                'progress' => 95,
                'job_offset' => 13,
                'steps' => [
                    'cleanup',
                ],
            ],
        ];
    }
}
