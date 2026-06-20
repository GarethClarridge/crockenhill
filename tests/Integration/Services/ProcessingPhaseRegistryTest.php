<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ResolveReadingReferences;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingPhaseRegistry;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingPhaseRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_audio_phases_in_pipeline_order(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $phases = $registry->phasesForPipeline('audio');

        $this->assertSame([
            'initiated_from_livestream',
            'audio_initiated',
            'validate_audio',
            'create_sermon_record',
            'transcribe_audio',
            'analyze_transcript',
            'update_sermon_record',
            'send_notification',
            'notification_complete',
            'cleanup',
        ], array_column($phases, 'key'));
    }

    #[Test]
    public function it_maps_legacy_and_manual_review_steps_to_registry_progress(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $this->assertSame(56, $registry->progressForStep('manual_review_confirmed'));
        $this->assertSame(10, $registry->progressForStep('initiated_from_livestream:abc123'));
        $this->assertSame(10, $registry->progressForStep('restarting_from_beginning'));
        $this->assertSame(53, $registry->progressForStep('transcribe_speech_segments', MediaType::Livestream));
        $this->assertSame(54, $registry->progressForStep('classify_speech_sections', MediaType::Livestream));
        $this->assertSame(55, $registry->progressForStep('align_with_oos', MediaType::Livestream));
        $this->assertSame(87, $registry->progressForStep('updating_sermon_record'));
        $this->assertSame(92, $registry->progressForStep('sending_notification'));
        $this->assertSame(93, $registry->progressForStep('notification_sent'));
    }

    #[Test]
    public function it_prefers_media_specific_progress_mappings_for_shared_step_names(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $this->assertSame(87, $registry->progressForStep('updating_sermon_record', MediaType::Audio));
        $this->assertSame(89, $registry->progressForStep('generating_thumbnail', MediaType::Video));
        $this->assertSame(90, $registry->progressForStep('generating_thumbnail', MediaType::Livestream));
        $this->assertSame(88, $registry->progressForStep('assessing_video_quality', MediaType::Video));
        $this->assertSame(89, $registry->progressForStep('assessing_video_quality', MediaType::Livestream));
    }

    #[Test]
    public function it_retries_livestream_runs_from_the_active_phase_cursor(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
        ]);

        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 13,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
    }

    #[Test]
    public function it_maps_section_publication_preparation_to_a_retryable_livestream_phase(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'preparing_section_publication_candidates',
        ]);

        $this->assertSame(91, $registry->progressForStep('preparing_section_publication_candidates', MediaType::Livestream));
        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 17,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
    }

    #[Test]
    public function it_retries_speech_segment_transcription_and_alignment_from_their_own_livestream_phase(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $transcriptionLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribe_speech_segments',
        ]);

        $alignmentLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'align_with_oos',
        ]);

        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 2,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($transcriptionLog));

        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 5,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($alignmentLog));
    }

    #[Test]
    public function it_maps_auto_trim_video_runs_to_their_dedicated_pipeline_phases(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $processingLog = MediaProcessingLog::factory()->video()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'audio_enhancement_complete',
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $this->assertSame(65, $registry->progressForLog($processingLog));
        $this->assertSame([
            'action' => 'dispatch_chain',
            'pipeline' => 'video_auto_trim',
            'job_offset' => 8,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
    }

    #[Test]
    public function it_registry_job_offsets_match_the_actual_pipeline_arrays(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);
        $builder = app(ProcessingPipelineBuilder::class);

        $audioLog = MediaProcessingLog::factory()->audio()->pending()->create();
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $autoTrimLog = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        // Map each pipeline to its job array and the expected first job class at key phase offsets.
        // Only phases with a meaningful retry offset are checked; initiation/validation phases
        // that share offset 0 are not individually asserted.
        $scenarios = [
            [
                'pipeline' => 'audio',
                'jobs' => $builder->buildAudioPipeline($audioLog),
                'expectations' => [
                    // offset => expected job class
                    2 => CreateSermonRecord::class,
                    4 => TranscribeAudio::class,
                    5 => ProcessTranscriptWithAI::class,
                    6 => SendCompletionNotification::class,
                    7 => CleanupTemporaryFiles::class,
                ],
            ],
            [
                'pipeline' => 'video',
                'jobs' => $builder->buildDirectVideoPipeline($videoLog),
                'expectations' => [
                    1 => ExtractAudioFromVideo::class,
                    3 => CreateSermonRecord::class,
                    5 => TranscribeAudio::class,
                    6 => ProcessTranscriptWithAI::class,
                    7 => AssessSermonVideoQuality::class,
                    8 => GenerateThumbnail::class,
                    9 => SendCompletionNotification::class,
                    10 => CleanupTemporaryFiles::class,
                ],
            ],
            [
                'pipeline' => 'video_auto_trim',
                'jobs' => $builder->buildAutoTrimVideoPipeline($autoTrimLog),
                'expectations' => [
                    2 => AnalyzeSegments::class,
                    3 => ClassifyServiceSections::class,
                    7 => ExtractSermon::class,
                    8 => EnhanceAudio::class,
                    9 => CreateSermonRecord::class,
                    11 => TranscribeAudio::class,
                    12 => ProcessTranscriptWithAI::class,
                    13 => AssessSermonVideoQuality::class,
                    14 => GenerateThumbnail::class,
                    15 => SendCompletionNotification::class,
                    16 => CleanupTemporaryFiles::class,
                ],
            ],
            [
                'pipeline' => 'livestream',
                'jobs' => $builder->buildLivestreamChainJobs($livestreamLog),
                'expectations' => [
                    0 => AnalyzeSegments::class,
                    1 => ClassifyServiceSections::class,
                    6 => ResolveReadingReferences::class,
                    9 => ExtractSermon::class,
                    10 => SubmitToProcessing::class,
                    13 => TranscribeAudio::class,
                    14 => ProcessTranscriptWithAI::class,
                    15 => AssessSermonVideoQuality::class,
                    16 => GenerateThumbnail::class,
                    17 => PrepareSectionPublicationCandidates::class,
                    18 => SendCompletionNotification::class,
                    19 => CleanupTemporaryFiles::class,
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            foreach ($scenario['expectations'] as $offset => $expectedClass) {
                $sliced = array_slice($scenario['jobs'], $offset);
                $this->assertNotEmpty($sliced, "No job at offset {$offset} in {$scenario['pipeline']} pipeline");
                $this->assertInstanceOf(
                    $expectedClass,
                    $sliced[0],
                    "Expected {$expectedClass} at offset {$offset} in {$scenario['pipeline']} pipeline, got ".get_class($sliced[0])
                );
            }
        }
    }
}
