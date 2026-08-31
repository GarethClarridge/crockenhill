<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ProcessingPipelineBuilderTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private ProcessingPipelineBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ProcessingPipelineBuilder;
    }

    // --- buildAudioPipeline() ---

    #[Test]
    public function it_builds_audio_pipeline_with_correct_job_sequence(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $jobs = $this->builder->buildAudioPipeline($log);

        $this->assertCount(9, $jobs);
        $this->assertInstanceOf(ValidateAudioFile::class, $jobs[0]);
        $this->assertInstanceOf(EnhanceAudio::class, $jobs[1]);
        $this->assertInstanceOf(CreateSermonRecord::class, $jobs[2]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[3]);
        $this->assertInstanceOf(TranscribeAudio::class, $jobs[4]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[5]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[6]);
        $this->assertInstanceOf(PromoteHistoricAssets::class, $jobs[7]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[8]);
    }

    #[Test]
    public function it_returns_array_of_jobs_for_audio_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $jobs = $this->builder->buildAudioPipeline($log);

        $this->assertIsArray($jobs);
        $this->assertNotEmpty($jobs);
    }

    // --- buildDirectVideoPipeline() ---

    #[Test]
    public function it_builds_direct_video_pipeline_with_correct_job_sequence(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $jobs = $this->builder->buildDirectVideoPipeline($log);

        $this->assertCount(12, $jobs);
        $this->assertInstanceOf(ValidateVideoFile::class, $jobs[0]);
        $this->assertInstanceOf(ExtractAudioFromVideo::class, $jobs[1]);
        $this->assertInstanceOf(EnhanceAudio::class, $jobs[2]);
        $this->assertInstanceOf(CreateSermonRecord::class, $jobs[3]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[4]);
        $this->assertInstanceOf(TranscribeAudio::class, $jobs[5]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[6]);
        $this->assertInstanceOf(AssessSermonVideoQuality::class, $jobs[7]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[8]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[9]);
        $this->assertInstanceOf(PromoteHistoricAssets::class, $jobs[10]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[11]);
    }

    #[Test]
    public function it_includes_extract_audio_step_in_video_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $jobs = $this->builder->buildDirectVideoPipeline($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(ExtractAudioFromVideo::class, $jobClasses);
    }

    #[Test]
    public function it_includes_thumbnail_generation_in_video_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $jobs = $this->builder->buildDirectVideoPipeline($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(GenerateThumbnail::class, $jobClasses);
    }

    #[Test]
    public function it_builds_auto_trim_video_pipeline_with_segmentation_then_sermon_processing_jobs(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $jobs = $this->builder->buildAutoTrimVideoPipeline($log);

        $this->assertCount(16, $jobs);
        $this->assertInstanceOf(ValidateVideoFile::class, $jobs[0]);
        $this->assertInstanceOf(GenerateRmsLog::class, $jobs[1]);
        $this->assertInstanceOf(AnalyzeSegments::class, $jobs[2]);
        $this->assertInstanceOf(TranscribeFullService::class, $jobs[3]);
        $this->assertInstanceOf(DetectServiceStructure::class, $jobs[4]);
        $this->assertInstanceOf(ExtractSermon::class, $jobs[5]);
        $this->assertInstanceOf(EnhanceAudio::class, $jobs[6]);
        $this->assertInstanceOf(CreateSermonRecord::class, $jobs[7]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[8]);
        $this->assertInstanceOf(CreateSermonTranscriptFromService::class, $jobs[9]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[10]);
        $this->assertInstanceOf(AssessSermonVideoQuality::class, $jobs[11]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[12]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[13]);
        $this->assertInstanceOf(PromoteHistoricAssets::class, $jobs[14]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[15]);
    }

    // --- buildLivestreamParallelJobs() ---

    #[Test]
    public function it_always_includes_rms_generation_in_parallel_jobs(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamParallelJobs($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(GenerateRmsLog::class, $jobClasses);
    }

    // --- buildLivestreamChainJobs() ---

    #[Test]
    public function it_builds_livestream_chain_jobs_with_correct_sequence(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $this->assertCount(19, $jobs);
        $this->assertInstanceOf(AnalyzeSegments::class, $jobs[0]);
        $this->assertInstanceOf(TranscribeFullService::class, $jobs[1]);
        $this->assertInstanceOf(DetectServiceStructure::class, $jobs[2]);
        $this->assertInstanceOf(ProjectLivestreamServiceStructure::class, $jobs[3]);
        $this->assertInstanceOf(MatchSongsFromTranscript::class, $jobs[4]);
        $this->assertInstanceOf(MergeSongContinuations::class, $jobs[5]);
        // Second pass: song matching has resolved catalogue songs by now, so the
        // merge can anchor on song identity instead of automated title text.
        $this->assertInstanceOf(ProjectLivestreamServiceStructure::class, $jobs[6]);
        $this->assertInstanceOf(ExtractSermon::class, $jobs[7]);
        $this->assertInstanceOf(SubmitToProcessing::class, $jobs[8]);
        $this->assertInstanceOf(EnhanceAudio::class, $jobs[9]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[10]);
        $this->assertInstanceOf(CreateSermonTranscriptFromService::class, $jobs[11]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[12]);
        $this->assertInstanceOf(AssessSermonVideoQuality::class, $jobs[13]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[14]);
        $this->assertInstanceOf(PrepareSectionPublicationCandidates::class, $jobs[15]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[16]);
        $this->assertInstanceOf(PromoteHistoricAssets::class, $jobs[17]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[18]);
    }

    #[Test]
    public function historic_livestreams_wait_for_video_storage_before_video_outputs(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'historic_import_operation_id' => $operation->id,
        ]);

        $jobs = $this->builder->buildLivestreamChainJobs($log);
        $classes = array_map(static fn (object $job): string => $job::class, $jobs);

        $analysisPosition = array_search(ProcessTranscriptWithAI::class, $classes, true);
        $waitPosition = array_search(AwaitHistoricSermonVideoStorage::class, $classes, true);
        $qualityPosition = array_search(AssessSermonVideoQuality::class, $classes, true);
        $thumbnailPosition = array_search(GenerateThumbnail::class, $classes, true);
        $promotionPosition = array_search(PromoteHistoricAssets::class, $classes, true);
        $cleanupPosition = array_search(CleanupTemporaryFiles::class, $classes, true);

        $this->assertIsInt($analysisPosition);
        $this->assertIsInt($waitPosition);
        $this->assertIsInt($qualityPosition);
        $this->assertIsInt($thumbnailPosition);
        $this->assertIsInt($promotionPosition);
        $this->assertIsInt($cleanupPosition);
        $this->assertGreaterThan($analysisPosition, $waitPosition);
        $this->assertGreaterThan($waitPosition, $qualityPosition);
        $this->assertGreaterThan($qualityPosition, $thumbnailPosition);
        $this->assertGreaterThan($thumbnailPosition, $promotionPosition);
        $this->assertGreaterThan($promotionPosition, $cleanupPosition);
    }

    #[Test]
    public function it_includes_sermon_extraction_in_livestream_chain_jobs(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(TranscribeFullService::class, $jobClasses);
        $this->assertContains(DetectServiceStructure::class, $jobClasses);
        $this->assertContains(ExtractSermon::class, $jobClasses);
        $this->assertContains(SubmitToProcessing::class, $jobClasses);
        $this->assertContains(PrepareSectionPublicationCandidates::class, $jobClasses);
    }

    #[Test]
    public function it_includes_cleanup_as_last_step_in_livestream_chain_jobs(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $lastJob = end($jobs);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $lastJob);
    }

    #[Test]
    public function it_includes_identify_speaker_after_submit_to_processing_in_livestream_chain_jobs(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);
        $classes = array_map(fn ($job) => get_class($job), $jobs);

        $submitPos = array_search(SubmitToProcessing::class, $classes);
        $identifyPos = array_search(IdentifySpeaker::class, $classes);
        $transcriptPos = array_search(CreateSermonTranscriptFromService::class, $classes);

        $this->assertGreaterThan($submitPos, $identifyPos, 'IdentifySpeaker must come after SubmitToProcessing');
        $this->assertGreaterThan($identifyPos, $transcriptPos, 'The sermon transcript must be created after IdentifySpeaker');
    }

    // --- buildLivestreamPostReviewChainJobs() ---

    #[Test]
    public function it_builds_post_review_chain_starting_at_extract_sermon(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamPostReviewChainJobs($log);

        $this->assertCount(12, $jobs);
        $this->assertInstanceOf(ExtractSermon::class, $jobs[0]);
        $this->assertInstanceOf(SubmitToProcessing::class, $jobs[1]);
        $this->assertInstanceOf(EnhanceAudio::class, $jobs[2]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[3]);
        $this->assertInstanceOf(CreateSermonTranscriptFromService::class, $jobs[4]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[5]);
        $this->assertInstanceOf(AssessSermonVideoQuality::class, $jobs[6]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[7]);
        $this->assertInstanceOf(PrepareSectionPublicationCandidates::class, $jobs[8]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[9]);
        $this->assertInstanceOf(PromoteHistoricAssets::class, $jobs[10]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[11]);
    }

    #[Test]
    public function it_excludes_upstream_segmentation_jobs_from_post_review_chain(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamPostReviewChainJobs($log);
        $classes = array_map(fn ($job) => get_class($job), $jobs);

        $this->assertNotContains(AnalyzeSegments::class, $classes);
        $this->assertNotContains(DetectServiceStructure::class, $classes);
    }

    #[Test]
    public function it_ends_post_review_chain_with_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamPostReviewChainJobs($log);

        $this->assertInstanceOf(CleanupTemporaryFiles::class, end($jobs));
    }

    // --- Shared pipeline checks ---

    #[Test]
    public function it_ends_with_cleanup_in_audio_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $jobs = $this->builder->buildAudioPipeline($log);

        $lastJob = end($jobs);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $lastJob);
    }

    #[Test]
    public function it_ends_with_cleanup_in_video_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $jobs = $this->builder->buildDirectVideoPipeline($log);

        $lastJob = end($jobs);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $lastJob);
    }

    #[Test]
    public function video_pipeline_has_more_jobs_than_audio_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();
        $audioJobs = $this->builder->buildAudioPipeline($log);

        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $videoJobs = $this->builder->buildDirectVideoPipeline($videoLog);

        $this->assertGreaterThan(count($audioJobs), count($videoJobs));
    }

    #[Test]
    public function all_pipelines_include_transcription_step(): void
    {
        $audioLog = MediaProcessingLog::factory()->audio()->pending()->create();
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $audioPipeline = $this->builder->buildAudioPipeline($audioLog);
        $videoPipeline = $this->builder->buildDirectVideoPipeline($videoLog);
        $livestreamPipeline = array_merge(
            $this->builder->buildLivestreamParallelJobs($livestreamLog),
            $this->builder->buildLivestreamChainJobs($livestreamLog),
        );

        foreach ([$audioPipeline, $videoPipeline] as $pipeline) {
            $classes = array_map(fn ($job) => get_class($job), $pipeline);
            $this->assertContains(TranscribeAudio::class, $classes);
        }

        $livestreamClasses = array_map(fn ($job) => get_class($job), $livestreamPipeline);
        $this->assertContains(CreateSermonTranscriptFromService::class, $livestreamClasses);
        $this->assertNotContains(TranscribeAudio::class, $livestreamClasses);
    }

    #[Test]
    public function all_pipelines_include_ai_analysis_step(): void
    {
        $audioLog = MediaProcessingLog::factory()->audio()->pending()->create();
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $audioPipeline = $this->builder->buildAudioPipeline($audioLog);
        $videoPipeline = $this->builder->buildDirectVideoPipeline($videoLog);
        $livestreamPipeline = array_merge(
            $this->builder->buildLivestreamParallelJobs($livestreamLog),
            $this->builder->buildLivestreamChainJobs($livestreamLog),
        );

        foreach ([$audioPipeline, $videoPipeline, $livestreamPipeline] as $pipeline) {
            $classes = array_map(fn ($job) => get_class($job), $pipeline);
            $this->assertContains(ProcessTranscriptWithAI::class, $classes);
        }
    }

    #[Test]
    public function all_pipelines_include_speaker_identification_step(): void
    {
        $audioLog = MediaProcessingLog::factory()->audio()->pending()->create();
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $audioPipeline = $this->builder->buildAudioPipeline($audioLog);
        $videoPipeline = $this->builder->buildDirectVideoPipeline($videoLog);
        $livestreamPipeline = array_merge(
            $this->builder->buildLivestreamParallelJobs($livestreamLog),
            $this->builder->buildLivestreamChainJobs($livestreamLog),
        );

        foreach ([$audioPipeline, $videoPipeline, $livestreamPipeline] as $pipeline) {
            $classes = array_map(fn ($job) => get_class($job), $pipeline);
            $this->assertContains(IdentifySpeaker::class, $classes);
        }
    }

    #[Test]
    public function all_pipelines_include_completion_notification_step(): void
    {
        $audioLog = MediaProcessingLog::factory()->audio()->pending()->create();
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create();
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $audioPipeline = $this->builder->buildAudioPipeline($audioLog);
        $videoPipeline = $this->builder->buildDirectVideoPipeline($videoLog);
        $livestreamPipeline = array_merge(
            $this->builder->buildLivestreamParallelJobs($livestreamLog),
            $this->builder->buildLivestreamChainJobs($livestreamLog),
        );

        foreach ([$audioPipeline, $videoPipeline, $livestreamPipeline] as $pipeline) {
            $classes = array_map(fn ($job) => get_class($job), $pipeline);
            $this->assertContains(SendCompletionNotification::class, $classes);
        }
    }

    #[Test]
    public function it_includes_identify_speaker_after_create_sermon_in_audio_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $jobs = $this->builder->buildAudioPipeline($log);
        $classes = array_map(fn ($job) => get_class($job), $jobs);

        $createPos = array_search(CreateSermonRecord::class, $classes);
        $identifyPos = array_search(IdentifySpeaker::class, $classes);
        $transcribePos = array_search(TranscribeAudio::class, $classes);

        $this->assertGreaterThan($createPos, $identifyPos, 'IdentifySpeaker must come after CreateSermonRecord');
        $this->assertGreaterThan($identifyPos, $transcribePos, 'TranscribeAudio must come after IdentifySpeaker');
    }

    #[Test]
    public function it_includes_identify_speaker_after_create_sermon_in_video_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $jobs = $this->builder->buildDirectVideoPipeline($log);
        $classes = array_map(fn ($job) => get_class($job), $jobs);

        $createPos = array_search(CreateSermonRecord::class, $classes);
        $identifyPos = array_search(IdentifySpeaker::class, $classes);
        $transcribePos = array_search(TranscribeAudio::class, $classes);

        $this->assertGreaterThan($createPos, $identifyPos, 'IdentifySpeaker must come after CreateSermonRecord');
        $this->assertGreaterThan($identifyPos, $transcribePos, 'TranscribeAudio must come after IdentifySpeaker');
    }
}
