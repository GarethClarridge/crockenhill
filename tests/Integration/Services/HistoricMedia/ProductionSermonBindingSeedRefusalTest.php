<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Exceptions\SeedResidueException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Services\HistoricMedia\ProductionSermonBinding;
use App\Services\Import\ProductionCensusExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionSermonBindingSeedRefusalTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = base_path('storage/scratch/seeded-residue-20261115-wp47/test-'.bin2hex(random_bytes(8)));
        mkdir($this->folder, 0700, true);
        Storage::fake('binding_old', ['root' => $this->folder.'/old']);
        config([
            'media-processing.storage.sermon_disk' => 'binding_old',
            'media-processing.storage.transcript_disk' => 'binding_old',
            'thumbnail-generation.storage.disk' => 'binding_old',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteFolder($this->folder);
        parent::tearDown();
    }

    #[Test]
    public function a_bundle_carrying_a_seeded_title_and_slug_is_refused(): void
    {
        $bundle = $this->bundle([
            'title' => 'Walking in the Light', 'slug' => 'walking-in-the-light',
        ]);

        try {
            $this->bind($bundle);
            $this->fail('A seeded bundle was bound.');
        } catch (SeedResidueException $exception) {
            $this->assertStringContainsString('2024-12-08 morning sermon', $exception->getMessage());
            $this->assertStringContainsString("field title carries the seeded value 'Walking in the Light'", $exception->getMessage());
            $this->assertStringContainsString('field slug', $exception->getMessage());
        }
    }

    #[Test]
    public function a_bundle_carrying_the_seeded_run_is_refused(): void
    {
        $bundle = $this->bundle(['title' => 'The lost older son', 'slug' => 'the-lost-older-son'], 'seed-prodigal-son-processing');

        $this->expectException(SeedResidueException::class);
        $this->expectExceptionMessage('seed-prodigal-son-processing');

        $this->bind($bundle, 'seed-prodigal-son-processing');
    }

    #[Test]
    public function a_bundle_with_real_values_on_a_seeded_date_binds(): void
    {
        $bound = $this->bind($this->bundle(['title' => 'The lost older son', 'slug' => 'the-lost-older-son']));

        $this->assertSame('approved-run', $bound['production_binding']['entries'][0]['processing_key']);
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function bundle(array $values, string $processingId = 'approved-run'): array
    {
        $source = Sermon::factory()->create([
            ...$values,
            'date' => '2024-12-08', 'service' => 'morning', 'content_type' => 'sermon',
            'reference' => null, 'series' => null, 'points' => null, 'preacher' => 'Unknown', 'preacher_id' => null,
            'audio_file_path' => 'approved.mp3', 'video_file_path' => null,
            'transcript_file_path' => null, 'thumbnail_file_path' => null,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId, 'sermon_id' => $source->id,
            'file_hash' => str_repeat('e', 64),
            'audio_file_path' => null, 'video_file_path' => null, 'transcript_file_path' => null,
            'processing_metadata' => ['service_artifacts' => []], 'church_service_id' => null,
        ]);
        $graph = app(HistoricProcessingResultInventory::class)->build($run);
        $source->deleteQuietly();
        $run->deleteQuietly();
        app(ProductionCensusExporter::class)->export($this->folder.'/census.jsonl', false);

        return app(HistoricProcessingResultBundle::class)->make(str_repeat('a', 64), ['version' => 1], [[
            'date' => '2024-12-08', 'service' => 'morning',
            'source_manifest_hash' => str_repeat('b', 64), 'evidence_set_hash' => str_repeat('c', 64),
            'pre_review_hash' => str_repeat('d', 64), 'media_graph' => $graph,
            'livestream_source_revision' => [], 'assets' => [[
                'path' => 'approved.mp3', 'size' => 14, 'sha256' => hash('sha256', 'approved audio'),
                'kind' => 'audio', 'roles' => ['publication:main:audio_file_path'],
            ]],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function bind(array $bundle, string $processingKey = 'approved-run'): array
    {
        return app(ProductionSermonBinding::class)->bind($bundle, $this->folder.'/census.jsonl', [
            ['processing_key' => $processingKey, 'target_sermon_id' => null],
        ]);
    }

    private function deleteFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
