<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Jobs\DetectServiceStructure;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingPipelineBuilderModeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function livestream_chain_uses_the_llm_path_in_every_mode(): void
    {
        $builder = new ProcessingPipelineBuilder;
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $chains = [];

        foreach (['shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            $chains[$mode] = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildLivestreamChainJobs($livestreamLog)
            );
        }

        $this->assertSame($chains['shadow'], $chains['primary']);
        $this->assertContains(TranscribeFullService::class, $chains['primary']);
        $this->assertContains(DetectServiceStructure::class, $chains['primary']);
    }

    #[Test]
    public function auto_trim_and_post_review_chains_are_mode_independent(): void
    {
        $builder = new ProcessingPipelineBuilder;
        $videoLog = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $autoTrimChains = [];
        $postReviewChains = [];

        foreach (['shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            $autoTrimChains[$mode] = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildAutoTrimVideoPipeline($videoLog)
            );
            $postReviewChains[$mode] = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildLivestreamPostReviewChainJobs($livestreamLog)
            );
        }

        $this->assertSame($autoTrimChains['shadow'], $autoTrimChains['primary']);
        $this->assertContains(TranscribeFullService::class, $autoTrimChains['primary']);
        $this->assertContains(DetectServiceStructure::class, $autoTrimChains['primary']);
        $this->assertSame($postReviewChains['shadow'], $postReviewChains['primary']);
        $this->assertNotContains(DetectServiceStructure::class, $postReviewChains['primary']);
    }
    #[Test]
    public function proposal_placement_follows_settled_structure_in_all_modes_and_resume_paths(): void
    {
        $builder = new ProcessingPipelineBuilder;
        $log = MediaProcessingLog::factory()->livestream()->make();
        foreach (['primary', 'shadow'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            foreach ([$builder->buildLivestreamChainJobs($log), $builder->buildLivestreamDetectionOnlyChainJobs($log), $builder->buildAutoTrimVideoPipeline($log), $builder->buildLivestreamPostReviewChainJobs($log), $builder->buildAutoTrimVideoPostReviewChainJobs($log)] as $jobs) {
                $classes = array_map(get_class(...), $jobs);
                $index = array_search(\App\Jobs\ProposeSectionTalkTypes::class, $classes, true);
                $this->assertIsInt($index);
                $this->assertSame(1, count(array_filter($classes, fn (string $class): bool => $class === \App\Jobs\ProposeSectionTalkTypes::class)));
                if ($index > 0) {
                    $this->assertContains($classes[$index - 1], [\App\Jobs\ProjectLivestreamServiceStructure::class, DetectServiceStructure::class]);
                }
                if (in_array(\App\Jobs\TranscribeOutputEdges::class, $classes, true)) {
                    $this->assertSame(\App\Jobs\TranscribeOutputEdges::class, $classes[$index + 1]);
                }
            }
            foreach ([$builder->buildAudioPipeline($log), $builder->buildDirectVideoPipeline($log), $builder->buildSermonlessServiceChainJobs($log), $builder->buildAdoptedSermonServiceChainJobs($log), $builder->buildLivestreamTranscriptionOnlyChainJobs($log)] as $jobs) {
                $this->assertNotContains(\App\Jobs\ProposeSectionTalkTypes::class, array_map(get_class(...), $jobs));
            }
        }
    }

}
