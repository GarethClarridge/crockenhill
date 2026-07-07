<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Jobs\AlignWithOos;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\ReclassifyIntroOutroSections;
use App\Jobs\ResolveReadingReferences;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Jobs\TranscribeSpeechSegments;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the exact job list per service_structure.mode. Off-mode lists are
 * pinned by ProcessingPipelineBuilderTest — these tests cover the shadow and
 * primary branches plus the mode-independence of the escape hatches.
 */
class ProcessingPipelineBuilderModeTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingPipelineBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ProcessingPipelineBuilder;
    }

    #[Test]
    public function shadow_mode_inserts_the_llm_jobs_after_the_heuristic_cluster(): void
    {
        config(['media-processing.service_structure.mode' => 'shadow']);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $this->assertSame([
            AnalyzeSegments::class,
            ClassifyServiceSections::class,
            TranscribeSpeechSegments::class,
            ClassifySpeechSections::class,
            ProjectLivestreamServiceStructure::class,
            AlignWithOos::class,
            ResolveReadingReferences::class,
            MatchSongsFromTranscript::class,
            ReclassifyIntroOutroSections::class,
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ], array_map(fn (object $job): string => get_class($job), $jobs));
    }

    #[Test]
    public function primary_mode_replaces_the_heuristic_cluster_with_the_llm_path(): void
    {
        config(['media-processing.service_structure.mode' => 'primary']);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $jobs = $this->builder->buildLivestreamChainJobs($log);

        $this->assertSame([
            AnalyzeSegments::class,
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ProjectLivestreamServiceStructure::class,
            ResolveReadingReferences::class,
            MatchSongsFromTranscript::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ], array_map(fn (object $job): string => get_class($job), $jobs));
    }

    #[Test]
    public function shadow_mode_appends_the_llm_jobs_to_the_reclassification_chain(): void
    {
        config(['media-processing.service_structure.mode' => 'shadow']);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        $jobs = $this->builder->buildSectionReclassificationChainJobs($log);
        $classes = array_map(fn (object $job): string => get_class($job), $jobs);

        $this->assertSame(ClassifyServiceSections::class, $classes[0]);
        $this->assertSame(
            [TranscribeFullService::class, DetectServiceStructure::class],
            array_slice($classes, 8, 2),
            'The LLM jobs run after the heuristic cluster, before ExtractSermon.'
        );
        $this->assertSame(ExtractSermon::class, $classes[10]);
    }

    #[Test]
    public function primary_mode_reclassification_skips_segment_analysis_but_keeps_the_tail(): void
    {
        config(['media-processing.service_structure.mode' => 'primary']);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        $jobs = $this->builder->buildSectionReclassificationChainJobs($log);
        $classes = array_map(fn (object $job): string => get_class($job), $jobs);

        $this->assertSame([
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ProjectLivestreamServiceStructure::class,
            ResolveReadingReferences::class,
            MatchSongsFromTranscript::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
        ], $classes);
    }

    #[Test]
    public function post_review_chains_are_identical_across_modes(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $chains = [];

        foreach (['off', 'shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            $chains[$mode] = array_map(
                fn (object $job): string => get_class($job),
                $this->builder->buildLivestreamPostReviewChainJobs($log)
            );
        }

        $this->assertSame($chains['off'], $chains['shadow']);
        $this->assertSame($chains['off'], $chains['primary']);
        $this->assertNotContains(DetectServiceStructure::class, $chains['primary']);
    }

    #[Test]
    public function the_auto_trim_pipeline_keeps_the_heuristic_path_in_every_mode(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);

        $chains = [];

        foreach (['off', 'shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            $chains[$mode] = array_map(
                fn (object $job): string => get_class($job),
                $this->builder->buildAutoTrimVideoPipeline($log)
            );
        }

        $this->assertSame($chains['off'], $chains['shadow']);
        $this->assertSame($chains['off'], $chains['primary']);
        $this->assertContains(ClassifyServiceSections::class, $chains['primary']);
        $this->assertNotContains(DetectServiceStructure::class, $chains['primary']);
    }

    #[Test]
    public function an_unknown_mode_throws_instead_of_silently_falling_back(): void
    {
        config(['media-processing.service_structure.mode' => 'stealth']);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stealth');

        $this->builder->buildLivestreamChainJobs($log);
    }
}
