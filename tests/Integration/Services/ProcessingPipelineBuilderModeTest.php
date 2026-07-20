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
    public function livestream_and_reclassification_chains_use_the_llm_path_in_every_mode(): void
    {
        $builder = new ProcessingPipelineBuilder;
        $livestreamLog = MediaProcessingLog::factory()->livestream()->pending()->create();

        $chains = [];
        $reclassificationChains = [];

        foreach (['off', 'shadow', 'primary'] as $mode) {
            config(['media-processing.service_structure.mode' => $mode]);
            $chains[$mode] = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildLivestreamChainJobs($livestreamLog)
            );
            $reclassificationChains[$mode] = array_map(
                static fn (object $job): string => $job::class,
                $builder->buildSectionReclassificationChainJobs($livestreamLog)
            );
        }

        $this->assertSame($chains['off'], $chains['shadow']);
        $this->assertSame($chains['off'], $chains['primary']);
        $this->assertContains(TranscribeFullService::class, $chains['primary']);
        $this->assertContains(DetectServiceStructure::class, $chains['primary']);
        $this->assertSame($reclassificationChains['off'], $reclassificationChains['shadow']);
        $this->assertSame($reclassificationChains['off'], $reclassificationChains['primary']);
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

        foreach (['off', 'shadow', 'primary'] as $mode) {
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

        $this->assertSame($autoTrimChains['off'], $autoTrimChains['shadow']);
        $this->assertSame($autoTrimChains['off'], $autoTrimChains['primary']);
        $this->assertContains(TranscribeFullService::class, $autoTrimChains['primary']);
        $this->assertContains(DetectServiceStructure::class, $autoTrimChains['primary']);
        $this->assertSame($postReviewChains['off'], $postReviewChains['shadow']);
        $this->assertSame($postReviewChains['off'], $postReviewChains['primary']);
        $this->assertNotContains(DetectServiceStructure::class, $postReviewChains['primary']);
    }
}
