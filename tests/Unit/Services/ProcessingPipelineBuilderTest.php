<?php

namespace Tests\Unit\Services;

use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\PerformVisualAnalysis;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeSpeechSegments;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingPipelineBuilderTest extends TestCase
{
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

        $this->assertCount(7, $jobs);
        $this->assertInstanceOf(ValidateAudioFile::class, $jobs[0]);
        $this->assertInstanceOf(CreateSermonRecord::class, $jobs[1]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[2]);
        $this->assertInstanceOf(TranscribeAudio::class, $jobs[3]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[4]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[5]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[6]);
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

        $this->assertCount(9, $jobs);
        $this->assertInstanceOf(ValidateVideoFile::class, $jobs[0]);
        $this->assertInstanceOf(ExtractAudioFromVideo::class, $jobs[1]);
        $this->assertInstanceOf(CreateSermonRecord::class, $jobs[2]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[3]);
        $this->assertInstanceOf(TranscribeAudio::class, $jobs[4]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[5]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[6]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[7]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[8]);
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

    // --- buildLivestreamParallelJobs() ---

    #[Test]
    public function it_builds_livestream_parallel_jobs_with_visual_analysis_enabled(): void
    {
        config(['media-processing.visual_analysis.enabled' => true]);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamParallelJobs($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(PerformVisualAnalysis::class, $jobClasses);
        $this->assertContains(GenerateRmsLog::class, $jobClasses);
        $this->assertCount(2, $jobs);
    }

    #[Test]
    public function it_excludes_visual_analysis_from_parallel_jobs_when_disabled(): void
    {
        config(['media-processing.visual_analysis.enabled' => false]);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamParallelJobs($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertNotContains(PerformVisualAnalysis::class, $jobClasses);
        $this->assertCount(1, $jobs);
    }

    #[Test]
    public function it_always_includes_rms_generation_in_parallel_jobs(): void
    {
        config(['media-processing.visual_analysis.enabled' => false]);
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

        $this->assertCount(12, $jobs);
        $this->assertInstanceOf(AnalyzeSegments::class, $jobs[0]);
        $this->assertInstanceOf(ClassifyServiceSections::class, $jobs[1]);
        $this->assertInstanceOf(TranscribeSpeechSegments::class, $jobs[2]);
        $this->assertInstanceOf(ClassifySpeechSections::class, $jobs[3]);
        $this->assertInstanceOf(ExtractSermon::class, $jobs[4]);
        $this->assertInstanceOf(SubmitToProcessing::class, $jobs[5]);
        $this->assertInstanceOf(IdentifySpeaker::class, $jobs[6]);
        $this->assertInstanceOf(TranscribeAudio::class, $jobs[7]);
        $this->assertInstanceOf(ProcessTranscriptWithAI::class, $jobs[8]);
        $this->assertInstanceOf(GenerateThumbnail::class, $jobs[9]);
        $this->assertInstanceOf(SendCompletionNotification::class, $jobs[10]);
        $this->assertInstanceOf(CleanupTemporaryFiles::class, $jobs[11]);
    }

    #[Test]
    public function it_includes_sermon_extraction_in_livestream_chain_jobs(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $jobClasses = array_map(fn ($job) => get_class($job), $jobs);
        $this->assertContains(ClassifyServiceSections::class, $jobClasses);
        $this->assertContains(TranscribeSpeechSegments::class, $jobClasses);
        $this->assertContains(ClassifySpeechSections::class, $jobClasses);
        $this->assertContains(ExtractSermon::class, $jobClasses);
        $this->assertContains(SubmitToProcessing::class, $jobClasses);
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
        $transcribePos = array_search(TranscribeAudio::class, $classes);

        $this->assertGreaterThan($submitPos, $identifyPos, 'IdentifySpeaker must come after SubmitToProcessing');
        $this->assertGreaterThan($identifyPos, $transcribePos, 'TranscribeAudio must come after IdentifySpeaker');
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

        foreach ([$audioPipeline, $videoPipeline, $livestreamPipeline] as $pipeline) {
            $classes = array_map(fn ($job) => get_class($job), $pipeline);
            $this->assertContains(TranscribeAudio::class, $classes);
        }
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
