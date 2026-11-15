<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\ChurchServiceSource;
use App\Exceptions\SeedResidueException;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricProcessingResultBundleExporter;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricProcessingResultBundleExporterSeedRefusalTest extends TestCase
{
    use RefreshDatabase;

    private HistoricStagingContext $stagingContext;

    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_a_run_whose_sermon_carries_seeded_values(): void
    {
        $run = $this->readyRun([
            'date' => '2024-12-15', 'service' => 'morning', 'content_type' => 'sermon',
            'title' => 'The Birth of Our Saviour', 'slug' => 'the-birth-of-our-saviour',
            'series' => 'Christmas Messages', 'reference' => 'Luke 2:1-20',
        ]);

        $this->assertRefused($run, [
            "field title carries the seeded value 'The Birth of Our Saviour'",
            "field series carries the seeded value 'Christmas Messages'",
            'sermon 2024-12-15 morning sermon',
        ]);
    }

    #[Test]
    public function it_refuses_a_run_whose_preacher_carries_the_guest_speaker_alias(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Visiting Speaker', 'slug' => 'visiting-speaker']);
        PreacherAlias::query()->create(['preacher_id' => $preacher->id, 'alias' => 'guest speaker']);
        $run = $this->readyRun(['preacher' => 'Visiting Speaker', 'preacher_id' => $preacher->id]);

        $this->assertRefused($run, ["preacher alias 'guest speaker'"]);
    }

    #[Test]
    public function it_refuses_the_seeded_sample_run(): void
    {
        $run = $this->readyRun([], 'seed-prodigal-son-processing');

        $this->assertRefused($run, ["processing run 'seed-prodigal-son-processing'"]);
    }

    #[Test]
    public function it_exports_a_real_sermon_that_shares_a_seeded_date(): void
    {
        $run = $this->readyRun([
            'date' => '2024-12-15', 'service' => 'morning', 'content_type' => 'sermon',
            'title' => 'God’s promises to Mary', 'slug' => 'gods-promises-to-mary',
            'series' => null, 'reference' => 'Luke 1:26-56',
        ]);

        $result = app(HistoricProcessingResultBundleExporter::class)->export([$run->processing_id], $this->stagingContext->manifestHash, $this->path);

        $this->assertSame(1, $result['service_count']);
    }

    /** @param list<string> $messages */
    private function assertRefused(MediaProcessingLog $run, array $messages): void
    {
        try {
            app(HistoricProcessingResultBundleExporter::class)->export([$run->processing_id], $this->stagingContext->manifestHash, $this->path);
            $this->fail('A seeded run was exported.');
        } catch (SeedResidueException $exception) {
            foreach ($messages as $message) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertFileDoesNotExist($this->path);
    }

    /** @param array<string, mixed> $sermonOverrides */
    private function readyRun(array $sermonOverrides, string $processingId = 'historic-processing'): MediaProcessingLog
    {
        Storage::fake('local');
        foreach (['sermon_disk', 'historic_quarantine_disk', 'transcript_disk', 'temp_disk', 'historic_staging_disk'] as $key) {
            config()->set('media-processing.storage.'.$key, 'local');
        }
        config()->set('thumbnail-generation.storage.disk', 'local');
        config()->set('thumbnail-generation.processing.temp_disk', 'local');

        $this->stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(str_repeat('2', 64), str_repeat('1', 64));
        $processingFingerprint = app(HistoricProcessingFingerprint::class)->forStagingContext($this->stagingContext);

        $service = ChurchService::factory()->create([
            'date' => '2026-08-02', 'service' => 'morning',
            'canonical_hash' => str_repeat('9', 64), 'canonical_revision' => 3, 'reviewed_canonical_revision' => 3,
        ]);
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id, 'base_canonical_hash' => str_repeat('8', 64), 'completed_at' => now(),
        ]);
        $source = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id, 'source' => ChurchServiceSource::Livestream,
            'source_key' => 'historic-processing|v1', 'revision_hash' => str_repeat('7', 64),
            'input_hash' => str_repeat('6', 64),
            'processing_fingerprint' => ['format' => 'livestream-projection', 'version' => 1],
            'service_content' => ['summary' => 'Reviewed locally', 'notices' => [], 'chapter_markers' => []],
        ]);
        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $source->id, 'assertion_key' => 'historic-processing:section:1',
        ]);
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'shared/full-service.mp3',
            'video_file_path' => 'historic-staging/sermon/video.mp4',
            'transcript_file_path' => 'historic-staging/sermon/transcript.txt',
            'thumbnail_file_path' => 'historic-staging/sermon/thumbnail.webp',
            ...$sermonOverrides,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId, 'church_service_id' => $service->id, 'sermon_id' => $sermon->id,
            'audio_file_path' => 'shared/full-service.mp3',
            'processing_metadata' => [
                'historic_import' => [
                    'tag' => 'livestream',
                    'sources' => [['path' => '/Volumes/archive/video.mkv', 'size' => 5, 'sha256' => str_repeat('5', 64)]],
                    'staging_context' => $this->stagingContext->toArray(),
                ],
                'processing_fingerprint' => $processingFingerprint,
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'service-transcripts/historic/rms.json', 'sha256' => hash('sha256', 'rms')],
                ],
            ],
        ]);

        app(HistoricStagingContextRegistry::class)->within($this->stagingContext, function () use ($sermon, $run): void {
            Storage::disk('local')->put($sermon->video_file_path, 'video');
            Storage::disk('local')->put($sermon->audio_file_path, 'audio');
            Storage::disk('local')->put($sermon->transcript_file_path, 'transcript');
            Storage::disk('local')->put($sermon->thumbnail_file_path, 'thumbnail');
            Storage::disk('local')->put($run->audio_file_path, 'full service audio');
            Storage::disk('local')->put('service-transcripts/historic/rms.json', 'rms');
        });

        File::ensureDirectoryExists(storage_path('scratch'));
        $this->path = storage_path('scratch/historic-seed-refusal-export-test-'.bin2hex(random_bytes(4)).'.json');

        return $run;
    }
}
