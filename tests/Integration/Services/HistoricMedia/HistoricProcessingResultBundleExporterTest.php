<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleExporter;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultBundleExporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exports_the_same_set_to_the_same_logical_bundle(): void
    {
        Storage::fake('local');
        config()->set('media-processing.storage.sermon_disk', 'local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        config()->set('media-processing.storage.temp_disk', 'local');
        config()->set('media-processing.storage.historic_staging_disk', 'local');
        config()->set('thumbnail-generation.storage.disk', 'local');
        config()->set('thumbnail-generation.processing.temp_disk', 'local');

        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('2', 64),
            str_repeat('1', 64),
        );
        $processingFingerprint = app(HistoricProcessingFingerprint::class)->forStagingContext($stagingContext);

        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
            'canonical_hash' => str_repeat('9', 64),
            'canonical_revision' => 3,
            'reviewed_canonical_revision' => 3,
        ]);
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'base_canonical_hash' => str_repeat('8', 64),
            'completed_at' => now(),
        ]);
        $source = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Livestream,
            'source_key' => 'historic-processing|v1',
            'revision_hash' => str_repeat('7', 64),
            'input_hash' => str_repeat('6', 64),
            'processing_fingerprint' => ['format' => 'livestream-projection', 'version' => 1],
            'service_content' => ['summary' => 'Reviewed locally', 'notices' => [], 'chapter_markers' => []],
        ]);
        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $source->id,
            'assertion_key' => 'historic-processing:section:1',
        ]);
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'shared/full-service.mp3',
            'video_file_path' => 'historic-staging/sermon/video.mp4',
            'transcript_file_path' => 'historic-staging/sermon/transcript.txt',
            'thumbnail_file_path' => 'historic-staging/sermon/thumbnail.webp',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'historic-processing',
            'church_service_id' => $service->id,
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'shared/full-service.mp3',
            'processing_metadata' => [
                'historic_import' => [
                    'tag' => 'livestream',
                    'sources' => [['path' => '/Volumes/archive/video.mkv', 'size' => 5, 'sha256' => str_repeat('5', 64)]],
                    'staging_context' => $stagingContext->toArray(),
                ],
                'processing_fingerprint' => $processingFingerprint,
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'service-transcripts/historic/rms.json', 'sha256' => hash('sha256', 'rms')],
                ],
            ],
        ]);

        app(HistoricStagingContextRegistry::class)->within($stagingContext, function () use ($sermon, $run): void {
            Storage::disk('local')->put($sermon->video_file_path, 'video');
            if (is_string($sermon->audio_file_path)) {
                Storage::disk('local')->put($sermon->audio_file_path, 'audio');
            }
            Storage::disk('local')->put($sermon->transcript_file_path, 'transcript');
            Storage::disk('local')->put($sermon->thumbnail_file_path, 'thumbnail');
            Storage::disk('local')->put($run->audio_file_path, 'full service audio');
            Storage::disk('local')->put('service-transcripts/historic/rms.json', 'rms');
        });

        File::ensureDirectoryExists(storage_path('scratch'));
        $path = storage_path('scratch/historic-processing-export-test.json');
        $exporter = app(HistoricProcessingResultBundleExporter::class);

        try {
            $first = $exporter->export(
                [$run->processing_id],
                $stagingContext->manifestHash,
                $path,
            );
            $second = $exporter->export(
                [$run->processing_id],
                $stagingContext->manifestHash,
                $path,
            );

            $this->assertSame($first['bundle_hash'], $second['bundle_hash']);
            $this->assertSame(1, $first['service_count']);
            $this->assertFileExists($path);
            $this->assertSame(0600, fileperms($path) & 0777);

            $json = File::get($path);
            $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            app(HistoricProcessingResultBundle::class)->decode($json);
            $this->assertSame($processingFingerprint['source_manifest_hash'], $bundle['processing_fingerprint']['source_manifest_hash']);
            $this->assertSame(
                $processingFingerprint['throughput']['ffmpeg']['worker_width'],
                $bundle['processing_fingerprint']['throughput']['ffmpeg']['worker_width'],
            );
            $sharedAsset = collect($bundle['services'][0]['assets'])
                ->sole(fn (array $asset): bool => $asset['path'] === 'shared/full-service.mp3');

            $this->assertSame([
                'publication:historic-processing:main:sermon:audio_file_path',
                'run_audio_file_path',
            ], $sharedAsset['roles']);
            $this->assertStringNotContainsString('/Volumes/', $json);
            $this->assertStringNotContainsString('owner_user_id', $json);
        } finally {
            File::delete($path);
        }
    }

    /**
     * The manifest is immutable for the life of a batch. Amending it mid-pass
     * re-hashes the plan and moves the batch root, so the runs from before and
     * after the amendment are two approved batches and export as two bundles.
     * The refusal has to name which runs belong to which, or the operator has no
     * way to act on it.
     */
    #[Test]
    public function it_names_the_plan_hashes_when_an_amended_manifest_splits_a_batch(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'local');

        $original = $this->stagingContextForPlan(str_repeat('1', 64));
        $amended = $this->stagingContextForPlan(str_repeat('7', 64));

        $before = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'run-before-amendment',
            'processing_metadata' => [
                'historic_import' => ['staging_context' => $original],
                'processing_fingerprint' => ['format' => 'test'],
            ],
        ]);
        $after = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'run-after-amendment',
            'processing_metadata' => [
                'historic_import' => ['staging_context' => $amended],
                'processing_fingerprint' => ['format' => 'test'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('span 2 of them');

        try {
            app(HistoricProcessingResultBundleExporter::class)->export(
                [$before->processing_id, $after->processing_id],
                str_repeat('2', 64),
                storage_path('scratch/split-batch.json'),
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('run-before-amendment', $exception->getMessage());
            $this->assertStringContainsString('run-after-amendment', $exception->getMessage());
            $this->assertStringContainsString('export each plan hash as its own Bundle A', $exception->getMessage());

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function stagingContextForPlan(string $planHash): array
    {
        return [
            'manifest_hash' => str_repeat('2', 64),
            'plan_hash' => $planHash,
            'staging_disk' => 'local',
            'batch_root' => "historic-batches/{$planHash}",
            'storage_identity' => [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => hash('sha256', '/private/historic'),
                'prefix_fingerprint' => hash('sha256', ''),
            ],
        ];
    }

    #[Test]
    public function it_refuses_public_or_unreviewed_exports(): void
    {
        File::ensureDirectoryExists(storage_path('scratch'));

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultBundleExporter::class)->export(
            ['missing-run'],
            str_repeat('4', 64),
            storage_path('app/public/bundle.json'),
        );
    }
}
