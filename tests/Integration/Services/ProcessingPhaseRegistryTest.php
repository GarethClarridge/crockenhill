<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
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
            'send_notification',
            'notification_complete',
            'cleanup',
        ], array_column($phases, 'key'));
    }

    #[Test]
    public function it_maps_manual_review_and_notification_steps_to_registry_progress(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $this->assertSame(56, $registry->progressForStep('manual_review_confirmed'));
        $this->assertSame(10, $registry->progressForStep('initiated_from_livestream:abc123'));
        $this->assertSame(10, $registry->progressForStep('restarting_from_beginning'));
        $this->assertSame(92, $registry->progressForStep('sending_notification'));
        $this->assertSame(93, $registry->progressForStep('notification_sent'));
    }

    #[Test]
    public function it_prefers_media_specific_progress_mappings_for_shared_step_names(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

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
            'job_offset' => 9,
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
            'job_offset' => 13,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
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
            'job_offset' => 6,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));

        foreach (['transcribe_full_service' => 3, 'detect_service_structure' => 4] as $step => $expectedOffset) {
            $processingLog->update(['current_step' => $step]);

            $this->assertSame($expectedOffset, $registry->retryPlanFor($processingLog->refresh())['job_offset']);
        }
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
                    3 => TranscribeFullService::class,
                    4 => DetectServiceStructure::class,
                    5 => ExtractSermon::class,
                    6 => EnhanceAudio::class,
                    7 => CreateSermonRecord::class,
                    9 => TranscribeAudio::class,
                    10 => ProcessTranscriptWithAI::class,
                    11 => AssessSermonVideoQuality::class,
                    12 => GenerateThumbnail::class,
                    13 => SendCompletionNotification::class,
                    14 => CleanupTemporaryFiles::class,
                ],
            ],
            [
                'pipeline' => 'livestream',
                'jobs' => $builder->buildLivestreamChainJobs($livestreamLog),
                'expectations' => [
                    0 => AnalyzeSegments::class,
                    1 => TranscribeFullService::class,
                    2 => DetectServiceStructure::class,
                    5 => ExtractSermon::class,
                    6 => SubmitToProcessing::class,
                    9 => TranscribeAudio::class,
                    10 => ProcessTranscriptWithAI::class,
                    11 => AssessSermonVideoQuality::class,
                    12 => GenerateThumbnail::class,
                    13 => PrepareSectionPublicationCandidates::class,
                    14 => SendCompletionNotification::class,
                    15 => CleanupTemporaryFiles::class,
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

    #[Test]
    public function livestream_retry_offsets_follow_the_service_structure_mode(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);
        $builder = app(ProcessingPipelineBuilder::class);

        // Every retryable livestream phase must resume at the job it is
        // anchored to in the chain the current mode actually builds.
        $stepToJobClass = [
            'project_livestream_service_structure' => ProjectLivestreamServiceStructure::class,
            'match_songs_from_transcript' => MatchSongsFromTranscript::class,
            'extraction' => ExtractSermon::class,
            'cleanup' => CleanupTemporaryFiles::class,
        ];

        foreach (['shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);

            $chainClasses = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildLivestreamChainJobs(MediaProcessingLog::factory()->livestream()->make())
            );

            foreach ($stepToJobClass as $step => $jobClass) {
                $log = MediaProcessingLog::factory()->livestream()->create([
                    'status' => ProcessingStatus::Failed,
                    'current_step' => $step,
                ]);

                $plan = $registry->retryPlanFor($log);

                $this->assertSame('dispatch_livestream_chain', $plan['action'], "step {$step} in mode {$mode}");
                $this->assertSame(
                    array_search($jobClass, $chainClasses, true),
                    $plan['job_offset'],
                    "Retrying step {$step} in mode {$mode} must resume at {$jobClass}"
                );
            }
        }
    }

    #[Test]
    public function llm_structure_steps_are_retryable_when_their_jobs_are_in_the_chain(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        config(['media-processing.service_structure.mode' => 'shadow']);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribe_full_service',
        ]);

        $plan = $registry->retryPlanFor($log);

        $this->assertSame('dispatch_livestream_chain', $plan['action']);
        $this->assertSame(1, $plan['job_offset']);

        $detectLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'detect_service_structure',
        ]);

        $this->assertSame(2, $registry->retryPlanFor($detectLog)['job_offset']);
    }
}
