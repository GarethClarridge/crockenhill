<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingFingerprintTest extends TestCase
{
    #[Test]
    public function it_pins_the_media_configuration_without_execution_widths_or_a_git_commit(): void
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
        $this->assertArrayNotHasKey('throughput', $fingerprint);
        $this->assertArrayNotHasKey('git_commit', $fingerprint);
        $this->assertSame(
            2,
            app(HistoricProcessingThroughput::class)->executionProfile()['ffmpeg']['worker_width'],
        );

        app(HistoricProcessingFingerprint::class)->assertMatchesCurrentConfiguration($context, $fingerprint);
    }

    #[Test]
    public function it_normalizes_the_known_legacy_throughput_field_before_comparison(): void
    {
        $fingerprintService = app(HistoricProcessingFingerprint::class);
        $fingerprint = $fingerprintService->forStagingContext($this->context());
        $fingerprint['throughput'] = [
            'ffmpeg' => ['routing_fingerprint' => str_repeat('a', 64), 'worker_width' => 1],
        ];

        $normalized = $fingerprintService->normalize($fingerprint);

        $this->assertArrayNotHasKey('throughput', $normalized);
        $fingerprintService->assertMatchesCurrentConfiguration($this->context(), $fingerprint);
    }

    #[Test]
    public function changing_a_worker_width_does_not_change_the_durable_fingerprint(): void
    {
        $context = $this->context();
        $first = app(HistoricProcessingFingerprint::class)->forStagingContext($context);

        config()->set('media-processing.historic_import.stages.ffmpeg.workers', 2);
        $second = app(HistoricProcessingFingerprint::class)->forStagingContext($context);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function historic_steps_have_explicit_stage_ownership_and_unknown_steps_stay_unknown(): void
    {
        $throughput = app(HistoricProcessingThroughput::class);

        $this->assertSame('ffmpeg', $throughput->stageForStep('extract_sermon'));
        $this->assertSame('ffmpeg', $throughput->stageForStep('assessing_video_quality'));
        $this->assertSame('whisper', $throughput->stageForStep('transcribing'));
        $this->assertSame('llm', $throughput->stageForStep('analyzing'));
        $this->assertSame('whisper', $throughput->stageForStep('creating_sermon_transcript'));
        $this->assertSame('unknown', $throughput->stageForStep('future_step'));
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
