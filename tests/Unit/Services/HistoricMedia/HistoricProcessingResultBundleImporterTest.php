<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultBundleImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function it_preflights_the_whole_service_without_writing(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        $bundle = $this->bundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);

        $first = $importer->prepareService($bundle);
        $second = $importer->prepareService($bundle);

        $this->assertSame('create', $first->classification);
        $this->assertSame($first->planHash, $second->planHash);
        Storage::disk('local')->assertMissing('historic/run/video.mp4');
        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function it_rejects_a_staged_asset_hash_mismatch(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'different');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultBundleImporter::class)->prepareService($this->bundle());
    }

    #[Test]
    public function asset_copy_is_create_only_and_cleans_only_attempt_created_paths(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        $assets = $this->bundle()['services'][0]['assets'];
        $transfer = app(HistoricProcessingResultAssetTransfer::class);

        $created = $transfer->copyCreateOnly($assets);
        $second = $transfer->copyCreateOnly($assets);

        $this->assertSame(['historic/run/video.mp4'], $created);
        $this->assertSame([], $second);
        $transfer->cleanup($created);
        Storage::disk('local')->assertMissing('historic/run/video.mp4');
    }

    #[Test]
    public function asset_copy_never_overwrites_different_content(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        Storage::disk('local')->put('historic/run/video.mp4', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultAssetTransfer::class)->copyCreateOnly(
            $this->bundle()['services'][0]['assets'],
        );
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        $service = [
            'date' => '2026-08-02',
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('1', 64),
            'evidence_set_hash' => str_repeat('2', 64),
            'pre_review_hash' => str_repeat('3', 64),
            'media_graph' => [
                'processing_key' => 'historic-run',
                'run' => ['file_hash' => str_repeat('4', 64)],
                'logical_hash' => str_repeat('5', 64),
            ],
            'livestream_source_revision' => [],
            'assets' => [[
                'role' => 'main_video',
                'path' => 'historic/run/video.mp4',
                'size' => 5,
                'sha256' => hash('sha256', 'video'),
            ]],
        ];

        return (new HistoricProcessingResultBundle)->make(
            str_repeat('6', 64),
            ['pipeline_version' => 1],
            [$service],
        );
    }
}
