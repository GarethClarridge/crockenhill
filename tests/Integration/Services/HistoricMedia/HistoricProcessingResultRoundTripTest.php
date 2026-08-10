<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * B8: the inventory hashes a graph whose paths are local to wherever it was built,
 * the persister remaps every one of those paths to a production destination, and
 * the result is then compared against the exported hash. Unless the hash ignores
 * paths and local row identities, that comparison can only ever fail.
 */
class HistoricProcessingResultRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private const PROCESSING_ID = 'roundtrip-run';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function logical_hash_survives_path_and_metadata_remapping(): void
    {
        $sourceGraph = $this->buildSourceGraph();
        $expectedHash = $sourceGraph['logical_hash'];
        $assets = $this->stageAssetsFor($sourceGraph);
        $sourcePaths = $this->recordedPaths($sourceGraph);

        $this->tearDownSourceGraph();

        $result = app(HistoricMediaGraphPersister::class)->persist(new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'Round trip the exported graph back through persistence.',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: ['media_graph' => $sourceGraph],
            assets: $assets,
        ));

        $targetGraph = app(HistoricProcessingResultInventory::class)
            ->build($result['processing_log']->fresh());
        $targetPaths = $this->recordedPaths($targetGraph);

        /**
         * The test is only meaningful if the remap actually happened: every
         * recorded path must have moved off staging onto a production destination.
         */
        $this->assertNotEquals($sourcePaths, $targetPaths);
        $this->assertNotEmpty($targetPaths);

        foreach ($targetPaths as $role => $path) {
            $this->assertStringStartsNotWith('staged/', $path, "Role {$role} is still recorded at its staging path.");
        }

        $this->assertSame($expectedHash, $targetGraph['logical_hash']);
    }

    /**
     * A representative live graph whose media all sits at staging-shaped paths and
     * whose rows carry the local ids the portable projection has to ignore.
     *
     * @return array<string, mixed>
     */
    private function buildSourceGraph(): array
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Round Trip Preacher',
            'slug' => 'round-trip-preacher',
        ]);
        $sermon = Sermon::factory()->create([
            'slug' => 'roundtrip-sermon',
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'audio_file_path' => 'staged/sermon-audio.mp3',
            'video_file_path' => 'staged/sermon-video.mp4',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => self::PROCESSING_ID,
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'staged/run-audio.mp3',
            'processing_metadata' => [
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'staged/rms.json', 'sha256' => hash('sha256', 'rms')],
                ],
            ],
        ]);
        $segment = $run->segments()->create([
            'segment_index' => 0,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'classification' => 'speech',
            'avg_rms' => 0.2,
            'peak_rms' => 0.4,
            'is_sermon_candidate' => true,
            'is_sermon_segment' => true,
            'segment_order' => 1,
            'metadata' => [],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => 'sermon',
            'title' => 'Sermon',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'source_segment_ids' => [$segment->id],
            'extracted_video_path' => 'staged/section-video.mp4',
            'extracted_audio_path' => 'staged/section-audio.mp3',
            'extracted_at' => now(),
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'published_sermon_id' => null,
            'published_at' => null,
        ]);

        return app(HistoricProcessingResultInventory::class)->build($run->fresh());
    }

    /**
     * Write each recorded path to the staging disk and describe it in the manifest
     * shape the importer verifies, one asset per logical role.
     *
     * @param  array<string, mixed>  $graph
     * @return list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>
     */
    private function stageAssetsFor(array $graph): array
    {
        $assets = [];

        foreach ($this->recordedPaths($graph) as $role => $path) {
            $contents = "contents of {$path}";
            Storage::disk('historic_staging')->put($path, $contents);

            $assets[] = [
                'path' => $path,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'kind' => 'media',
                'roles' => [$role],
            ];
        }

        return $assets;
    }

    /**
     * Every path the graph records, keyed by the asset role that owns it.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, string>
     */
    private function recordedPaths(array $graph): array
    {
        $paths = [];

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'rms_log_path'] as $field) {
            if (is_string($graph['run'][$field] ?? null)) {
                $paths["run_{$field}"] = $graph['run'][$field];
            }
        }

        foreach ($graph['publications'] as $publication) {
            foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
                if (is_string($publication[$field] ?? null)) {
                    $paths["publication:{$publication['publication_key']}:{$field}"] = $publication[$field];
                }
            }
        }

        foreach ($graph['sections'] as $section) {
            foreach (['extracted_video_path', 'extracted_audio_path'] as $field) {
                if (is_string($section[$field] ?? null)) {
                    $paths["section:{$section['section_key']}:{$field}"] = $section[$field];
                }
            }
        }

        foreach ($graph['metadata']['service_artifacts'] ?? [] as $artifact) {
            if (is_array($artifact) && is_string($artifact['path'] ?? null)) {
                $kind = is_string($artifact['kind'] ?? null) ? $artifact['kind'] : 'artifact';
                $paths["service_artifact:{$kind}:{$artifact['sha256']}"] = $artifact['path'];
            }
        }

        return $paths;
    }

    /**
     * Remove the rows the graph was read from so persistence allocates fresh local
     * ids. The portable hash must not notice.
     */
    private function tearDownSourceGraph(): void
    {
        $run = MediaProcessingLog::query()->where('processing_id', self::PROCESSING_ID)->first();

        if (! $run instanceof MediaProcessingLog) {
            throw new RuntimeException('The round-trip source run was never created.');
        }

        $sermonIds = $run->serviceSections()->pluck('published_sermon_id')
            ->push($run->sermon_id)
            ->filter()
            ->unique()
            ->values();

        $run->serviceSections()->update([
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'published_sermon_id' => null,
            'published_at' => null,
        ]);
        $run->update(['sermon_id' => null]);
        SermonScriptureFilter::query()->whereIn('sermon_id', $sermonIds)->delete();
        Sermon::query()->whereIn('id', $sermonIds)->delete();
        $run->serviceSections()->delete();
        $run->segments()->delete();
        $run->delete();
    }
}
