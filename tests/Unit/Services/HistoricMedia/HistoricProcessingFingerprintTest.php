<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingFingerprintTest extends TestCase
{
    #[Test]
    public function it_pins_the_calibrated_media_configuration_without_a_git_commit(): void
    {
        config([
            'media-processing.historic_import.stages.ffmpeg.workers' => 2,
            'media-processing.historic_import.stages.whisper.workers' => 1,
            'media-processing.historic_import.stages.llm.workers' => 4,
            'media-processing.historic_import.stages.orchestration.workers' => 1,
        ]);

        $context = $this->context();
        $fingerprint = app(HistoricProcessingFingerprint::class)->forStagingContext($context);

        $this->assertSame($context->manifestHash, $fingerprint['source_manifest_hash']);
        $this->assertSame(2, $fingerprint['throughput']['ffmpeg']['worker_width']);
        $this->assertArrayNotHasKey('git_commit', $fingerprint);

        app(HistoricProcessingFingerprint::class)->assertMatchesCurrentConfiguration($context, $fingerprint);
    }

    #[Test]
    public function it_refuses_a_commit_scoped_processing_fingerprint(): void
    {
        $context = $this->context();
        $fingerprint = app(HistoricProcessingFingerprint::class)->forStagingContext($context);
        $fingerprint['git_commit'] = str_repeat('a', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not pin non-media input');

        app(HistoricProcessingFingerprint::class)->assertMatchesCurrentConfiguration($context, $fingerprint);
    }

    private function context(): HistoricStagingContext
    {
        return new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/'.str_repeat('b', 64),
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => hash('sha256', '/private/historic'),
                'prefix_fingerprint' => hash('sha256', ''),
            ],
        );
    }
}
