<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditHistoricImportAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
    }

    #[Test]
    public function it_passes_when_every_recorded_artifact_is_present(): void
    {
        $log = $this->completedRunWithArtifacts();
        $report = $this->writeReport([$log->processing_id]);

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertSuccessful()
            ->expectsOutputToContain('fully retained');
    }

    #[Test]
    public function it_fails_when_a_recorded_artifact_has_been_swept(): void
    {
        $log = $this->completedRunWithArtifacts();

        $artifacts = ServiceArtifactStorage::recordedFor($log);
        $this->assertNotEmpty($artifacts);
        Storage::disk($artifacts[0]['disk'])->delete($artifacts[0]['path']);

        $report = $this->writeReport([$log->processing_id]);

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertFailed()
            ->expectsOutputToContain('missing from storage');
    }

    #[Test]
    public function it_audits_promoted_sermon_assets_on_the_sermons_recorded_disk(): void
    {
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');
        config([
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
        $sermon = Sermon::factory()->create([
            'asset_disk' => 'historic_quarantine',
            'audio_file_path' => 'sermons/audio/promoted.mp3',
            'video_file_path' => 'sermons/123/video.mp4',
            'transcript_file_path' => 'transcripts/sermon_123.md',
            'thumbnail_file_path' => null,
        ]);
        Storage::disk('historic_quarantine')->put($sermon->audio_file_path, 'audio');
        Storage::disk('historic_quarantine')->put($sermon->video_file_path, 'video');
        Storage::disk('historic_quarantine')->put($sermon->transcript_file_path, 'transcript');
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => $sermon->id,
            'processing_metadata' => ['historic_import' => ['label' => 'archive recording']],
        ]);

        $this->artisan('audit:historic-import-assets', ['report' => $this->writeReport([$log->processing_id])])
            ->assertSuccessful()
            ->expectsOutputToContain('fully retained');
    }

    #[Test]
    public function it_fails_when_the_report_names_an_unknown_processing_run(): void
    {
        $report = $this->writeReport(['not-a-real-processing-id']);

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertFailed()
            ->expectsOutputToContain('no database row');
    }

    #[Test]
    public function it_warns_rather_than_fails_for_a_run_that_never_completed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create();
        $report = $this->writeReport([$log->processing_id]);

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertSuccessful()
            ->expectsOutputToContain('did not complete');
    }

    #[Test]
    public function it_rejects_a_report_that_is_not_an_import_manifest(): void
    {
        $path = storage_path('framework/testing/not-a-report-'.uniqid().'.json');
        file_put_contents($path, json_encode(['format' => 'something-else'], JSON_THROW_ON_ERROR));

        $this->artisan('audit:historic-import-assets', ['report' => $path])
            ->assertFailed()
            ->expectsOutputToContain('missing or invalid');
    }

    private function completedRunWithArtifacts(): MediaProcessingLog
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => null,
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => $sermon->id,
            'extracted_date' => '2026-03-22',
            'processing_metadata' => [
                'historic_import' => ['label' => 'archive recording'],
            ],
        ]);

        Storage::disk('local')->put('temp/rms.log', 'lavfi.astats.Overall.RMS_level=-20.0');

        $storage = app(ServiceArtifactStorage::class);
        $storage->putJson($log->processing_id, 'normalized', ['cues' => []]);
        $storage->putJson($log->processing_id, 'raw', ['segments' => []]);
        $storage->archiveRms($log->processing_id, 'temp/rms.log');

        return $log->refresh();
    }

    /**
     * @param  list<string>  $processingIds
     */
    private function writeReport(array $processingIds): string
    {
        $path = storage_path('framework/testing/historic-import-report-'.uniqid().'.json');

        file_put_contents($path, json_encode([
            'format' => 'crockenhill.historic-import-report',
            'items' => array_map(
                static fn (string $id): array => [
                    'decision' => 'dispatched',
                    'label' => 'archive recording',
                    'processing_id' => $id,
                ],
                $processingIds,
            ),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
