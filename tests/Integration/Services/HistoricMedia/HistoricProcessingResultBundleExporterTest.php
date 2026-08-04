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
use App\Services\HistoricMedia\HistoricProcessingResultBundleExporter;
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
        config()->set('media-processing.storage.historic_staging_disk', 'local');

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
                ],
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'service-transcripts/historic/rms.json', 'sha256' => hash('sha256', 'rms')],
                ],
            ],
        ]);

        Storage::disk('local')->put($sermon->video_file_path, 'video');
        if (is_string($sermon->audio_file_path)) {
            Storage::disk('local')->put($sermon->audio_file_path, 'audio');
        }
        Storage::disk('local')->put($sermon->transcript_file_path, 'transcript');
        Storage::disk('local')->put($sermon->thumbnail_file_path, 'thumbnail');
        Storage::disk('local')->put($run->audio_file_path, 'full service audio');
        Storage::disk('local')->put('service-transcripts/historic/rms.json', 'rms');

        File::ensureDirectoryExists(storage_path('scratch'));
        $path = storage_path('scratch/historic-processing-export-test.json');
        $exporter = app(HistoricProcessingResultBundleExporter::class);

        try {
            $first = $exporter->export(
                [$run->processing_id],
                str_repeat('4', 64),
                ['git_commit' => str_repeat('3', 40), 'pipeline_version' => 1],
                $path,
            );
            $second = $exporter->export(
                [$run->processing_id],
                str_repeat('4', 64),
                ['git_commit' => str_repeat('3', 40), 'pipeline_version' => 1],
                $path,
            );

            $this->assertSame($first['bundle_hash'], $second['bundle_hash']);
            $this->assertSame(1, $first['service_count']);
            $this->assertFileExists($path);
            $this->assertSame(0600, fileperms($path) & 0777);

            $json = File::get($path);
            $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
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

    #[Test]
    public function it_refuses_public_or_unreviewed_exports(): void
    {
        File::ensureDirectoryExists(storage_path('scratch'));

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultBundleExporter::class)->export(
            ['missing-run'],
            str_repeat('4', 64),
            ['pipeline_version' => 1],
            storage_path('app/public/bundle.json'),
        );
    }
}
