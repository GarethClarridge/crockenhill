<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use App\Services\Sermon\SermonPromotionBundleFiles;
use App\Services\Sermon\SermonPromotionBundleImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SermonPromotionBundleCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $bundlePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('promotion-test');
        config([
            'media-processing.storage.sermon_disk' => 'promotion-test',
            'media-processing.storage.transcript_disk' => 'promotion-test',
            'thumbnail-generation.storage.disk' => 'promotion-test',
            'services.api_bible.default_bible_id' => 'test-bible',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->bundlePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_exports_a_private_portable_bundle_without_environment_specific_ids(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->newBundlePath();

        $this->artisan('sermons:export-promotion-bundle', [
            '--ids' => (string) $source['sermon']->id,
            '--output' => $path,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Sermon promotion bundle exported.');

        $bundle = $this->readBundle($path);
        $entry = $bundle['sermons'][0];

        $this->assertSame('crockenhill-sermon-promotion', $bundle['format']);
        $this->assertSame(1, $bundle['version']);
        $this->assertSame($source['sermon']->id, $entry['local_id']);
        $this->assertSame($source['processing_log']->processing_id, $entry['provenance']['processing_id']);
        $this->assertSame($source['processing_log']->file_hash, $entry['provenance']['file_hash']);
        $this->assertArrayNotHasKey('id', $entry['sermon']);
        $this->assertArrayNotHasKey('download_count', $entry['sermon']);
        $this->assertArrayNotHasKey('preacher_id', $entry['sermon']);
        $this->assertArrayNotHasKey('scripture_passage_id', $entry['sermon']);
        $this->assertArrayNotHasKey('owner_user_id', $entry['provenance']);
        $this->assertArrayNotHasKey('church_service_id', $entry['provenance']);
        $this->assertArrayNotHasKey('job_id', $entry['provenance']);
        $this->assertArrayNotHasKey('queue_name', $entry['provenance']);
        $this->assertSame(5, count($entry['assets']));
        $this->assertSame(64, strlen($entry['assets'][0]['sha256']));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    #[Test]
    public function it_rejects_export_when_only_failed_provenance_exists_or_an_asset_is_missing(): void
    {
        $failedSource = $this->createExportableSermon(completedProvenance: false);
        $failedPath = $this->newBundlePath();

        $this->artisan('sermons:export-promotion-bundle', [
            '--ids' => (string) $failedSource['sermon']->id,
            '--output' => $failedPath,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('has no completed processing provenance');

        $missingSource = $this->createExportableSermon(slug: 'missing-transcript-sermon');
        Storage::disk('promotion-test')->delete((string) $missingSource['sermon']->transcript_file_path);
        $missingPath = $this->newBundlePath();

        $this->artisan('sermons:export-promotion-bundle', [
            '--ids' => (string) $missingSource['sermon']->id,
            '--output' => $missingPath,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('transcript asset is missing');
    }

    #[Test]
    public function it_imports_create_only_data_with_remapped_relationships_and_is_idempotent(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $bundle = $this->readBundle($path);
        $this->clearPromotableRecords();
        $passage = $this->createScripturePassage('John 3:16');

        $result = app(SermonPromotionBundleImporter::class)->import($path, verifyHashes: true, apply: true);

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame(1, Sermon::query()->count());
        $this->assertSame(1, Preacher::query()->count());

        $importedSermon = Sermon::query()->firstOrFail();
        $importedLog = MediaProcessingLog::query()->firstOrFail();

        $this->assertNotSame($bundle['sermons'][0]['local_id'], $importedSermon->id);
        $this->assertSame('Alice Preacher', $importedSermon->preacher);
        $this->assertNotNull($importedSermon->preacher_id);
        $this->assertSame($passage->id, $importedSermon->scripture_passage_id);
        $this->assertSame(0, $importedSermon->download_count);
        $this->assertSame($importedSermon->id, $importedLog->sermon_id);
        $this->assertNull($importedLog->owner_user_id);
        $this->assertNull($importedLog->church_service_id);
        $this->assertNull($importedLog->job_id);
        $this->assertSame(1, $importedLog->processingSteps()->count());
        $this->assertSame($bundle['sermons'][0]['scripture_filters'], $importedSermon->scriptureFilters()
            ->get(['bible_book', 'bible_chapter'])
            ->map(fn ($filter): array => [
                'bible_book' => $filter->bible_book,
                'bible_chapter' => $filter->bible_chapter,
            ])
            ->all());

        $secondRun = app(SermonPromotionBundleImporter::class)->import($path, verifyHashes: true);

        $this->assertSame(1, $secondRun['counts']['already_present']);
        $this->assertSame(0, $secondRun['counts']['create']);
        $this->assertSame(0, $secondRun['counts']['conflict']);
        $this->assertSame(1, Sermon::query()->count());
        $this->assertSame(1, MediaProcessingLog::query()->count());
    }

    #[Test]
    public function it_resolves_an_existing_preacher_by_alias_without_creating_another_preacher(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $this->clearPromotableRecords();
        $canonicalPreacher = Preacher::factory()->create([
            'name' => 'Alice Canonical',
            'slug' => 'alice-canonical',
        ]);
        $canonicalPreacher->aliases()->create(['alias' => 'alice preacher']);

        app(SermonPromotionBundleImporter::class)->import($path, apply: true);

        $importedSermon = Sermon::query()->firstOrFail();

        $this->assertSame($canonicalPreacher->id, $importedSermon->preacher_id);
        $this->assertSame($canonicalPreacher->name, $importedSermon->preacher);
        $this->assertSame(1, Preacher::query()->count());
    }

    #[Test]
    public function a_non_unique_date_service_content_identity_does_not_block_a_distinct_create(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $this->clearPromotableRecords();
        $existingPreacher = Preacher::factory()->create();
        Sermon::factory()->withPreacher($existingPreacher)->create([
            'date' => '2024-01-07',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'slug' => 'different-sermon-on-the-same-date',
            'audio_file_path' => 'sermons/audio/different.mp3',
        ]);

        $preflight = app(SermonPromotionBundleImporter::class)->import($path);

        $this->assertSame(1, $preflight['counts']['create']);
        $this->assertSame(0, $preflight['counts']['conflict']);
    }

    #[Test]
    public function it_blocks_a_slug_collision_without_a_strong_identity_match(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $this->clearPromotableRecords();
        $existingPreacher = Preacher::factory()->create();
        Sermon::factory()->withPreacher($existingPreacher)->create([
            'slug' => 'alice-preaches-john',
            'audio_file_path' => 'sermons/audio/unrelated.mp3',
        ]);

        $preflight = app(SermonPromotionBundleImporter::class)->import($path);

        $this->assertSame(1, $preflight['counts']['conflict']);
        $this->assertStringContainsString('slug', $preflight['entries'][0]['reason']);
    }

    #[Test]
    public function it_blocks_asset_hash_and_processing_uuid_identities_that_disagree(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $bundle = $this->readBundle($path);
        $this->clearPromotableRecords();
        $preacher = Preacher::factory()->create();
        $assetOwner = Sermon::factory()->withPreacher($preacher)->create([
            'slug' => 'asset-owner',
            'audio_file_path' => $bundle['sermons'][0]['sermon']['audio_file_path'],
        ]);
        $hashOwner = Sermon::factory()->withPreacher($preacher)->create([
            'slug' => 'hash-owner',
            'audio_file_path' => 'sermons/audio/hash-owner.mp3',
        ]);
        MediaProcessingLog::factory()->audio()->withSermon($hashOwner)->create([
            'processing_id' => $bundle['sermons'][0]['provenance']['processing_id'],
            'file_hash' => $bundle['sermons'][0]['provenance']['file_hash'],
            'file_size' => $bundle['sermons'][0]['provenance']['file_size'],
        ]);

        $preflight = app(SermonPromotionBundleImporter::class)->import($path);

        $this->assertSame(1, $preflight['counts']['conflict']);
        $this->assertStringContainsString('different production sermons', $preflight['entries'][0]['reason']);
        $this->assertModelExists($assetOwner);
        $this->assertModelExists($hashOwner);
    }

    #[Test]
    public function it_blocks_a_processing_uuid_collision_without_an_asset_or_hash_match(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $bundle = $this->readBundle($path);
        $this->clearPromotableRecords();
        $preacher = Preacher::factory()->create();
        $uuidOwner = Sermon::factory()->withPreacher($preacher)->create([
            'slug' => 'uuid-owner',
            'audio_file_path' => 'sermons/audio/uuid-owner.mp3',
        ]);
        MediaProcessingLog::factory()->audio()->withSermon($uuidOwner)->create([
            'processing_id' => $bundle['sermons'][0]['provenance']['processing_id'],
            'file_hash' => hash('sha256', 'different-source'),
            'file_size' => 16,
        ]);

        $preflight = app(SermonPromotionBundleImporter::class)->import($path);

        $this->assertSame(1, $preflight['counts']['conflict']);
        $this->assertStringContainsString('Processing UUID', $preflight['entries'][0]['reason']);
    }

    #[Test]
    public function it_blocks_missing_assets_and_streamed_hash_mismatches_before_writes(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $this->clearPromotableRecords();
        Storage::disk('promotion-test')->delete($source['audio_path']);

        $missing = app(SermonPromotionBundleImporter::class)->import($path, verifyHashes: true, apply: true);

        $this->assertSame(1, $missing['counts']['conflict']);
        $this->assertSame(0, Sermon::query()->count());

        Storage::disk('promotion-test')->put($source['audio_path'], str_repeat('x', strlen($source['audio_contents'])));
        $mismatch = app(SermonPromotionBundleImporter::class)->import($path, verifyHashes: true, apply: true);

        $this->assertSame(1, $mismatch['counts']['conflict']);
        $this->assertStringContainsString('hash does not match', $mismatch['entries'][0]['reason']);
        $this->assertSame(0, Sermon::query()->count());
    }

    #[Test]
    public function it_rolls_back_the_entire_import_when_persistence_fails_after_the_sermon_insert(): void
    {
        $source = $this->createExportableSermon();
        $path = $this->export($source['sermon']);
        $bundle = $this->readBundle($path);
        $this->clearPromotableRecords();
        $filterService = Mockery::mock(SermonScriptureFilterIndexService::class);
        $filterService->shouldReceive('entriesForReference')
            ->once()
            ->andReturn($bundle['sermons'][0]['scripture_filters']);
        $filterService->shouldReceive('syncForSermon')
            ->once()
            ->andThrow(new RuntimeException('Injected persistence failure.'));
        $this->app->instance(SermonScriptureFilterIndexService::class, $filterService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Injected persistence failure.');

        try {
            app(SermonPromotionBundleImporter::class)->import($path, apply: true);
        } finally {
            $this->assertSame(0, Sermon::query()->count());
            $this->assertSame(0, Preacher::query()->count());
            $this->assertSame(0, MediaProcessingLog::query()->count());
            $this->assertSame(0, SermonProcessingStep::query()->count());
        }
    }

    #[Test]
    public function it_rejects_bundle_paths_outside_private_application_storage(): void
    {
        $source = $this->createExportableSermon();

        $this->artisan('sermons:export-promotion-bundle', [
            '--ids' => (string) $source['sermon']->id,
            '--output' => base_path('promotion-bundle.json'),
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('must stay under the application storage directory');
    }

    /**
     * @return array{
     *     sermon: Sermon,
     *     processing_log: MediaProcessingLog,
     *     audio_path: string,
     *     audio_contents: string
     * }
     */
    private function createExportableSermon(string $slug = 'alice-preaches-john', bool $completedProvenance = true): array
    {
        $preacher = Preacher::query()->firstOrCreate(
            ['slug' => 'alice-preacher'],
            ['name' => 'Alice Preacher', 'is_active' => true],
        );
        $preacher->aliases()->firstOrCreate(['alias' => 'a preacher']);

        $audioPath = "sermons/audio/{$slug}.mp3";
        $transcriptPath = "transcripts/{$slug}.json";
        $thumbnailPath = "sermons/thumbnails/{$slug}.webp";
        $candidatePath = "sermons/thumbnails/{$slug}-candidate.webp";
        $audioContents = 'portable-audio-'.$slug;

        Storage::disk('promotion-test')->put($audioPath, $audioContents);
        Storage::disk('promotion-test')->put($transcriptPath, '{"text":"portable transcript"}');
        Storage::disk('promotion-test')->put($thumbnailPath, 'thumbnail-'.$slug);
        Storage::disk('promotion-test')->put($candidatePath, 'candidate-'.$slug);

        $sermon = Sermon::factory()->withPreacher($preacher)->create([
            'date' => '2024-01-07',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'source_type' => SermonSourceType::AudioUpload,
            'audio_file_path' => $audioPath,
            'transcript_file_path' => $transcriptPath,
            'thumbnail_file_path' => $thumbnailPath,
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => $thumbnailPath,
                'thumbnail_candidates' => [[
                    'id' => 'candidate-1',
                    'timestamp' => 42.0,
                    'score' => 0.9,
                    'plain_path' => $candidatePath,
                ]],
            ],
            'title' => 'Alice preaches John',
            'slug' => $slug,
            'reference' => 'John 3:16',
            'summary' => 'A portable summary.',
            'meta_description' => 'A portable description.',
            'download_count' => 42,
        ]);
        app(SermonScriptureFilterIndexService::class)->syncForSermon($sermon);

        $processingLog = MediaProcessingLog::factory()->audio()->withSermon($sermon)->create([
            'processing_id' => (string) Str::uuid(),
            'status' => $completedProvenance ? ProcessingStatus::Completed : ProcessingStatus::Failed,
            'current_step' => $completedProvenance ? 'completed' : 'audio_transcription',
            'error_message' => $completedProvenance ? null : 'Processing failed.',
            'original_filename' => "{$slug}.mp3",
            'file_hash' => hash('sha256', $audioContents),
            'file_size' => strlen($audioContents),
            'source_file_path' => $audioPath,
            'completed_at' => $completedProvenance ? now()->subDay() : null,
            'started_at' => now()->subDay()->subMinute(),
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $processingLog->processing_id,
            'step' => 'sermon_creation',
            'status' => $completedProvenance ? ProcessingStatus::Completed : ProcessingStatus::Failed,
            'started_at' => now()->subDay()->subMinute(),
            'completed_at' => now()->subDay(),
        ]);

        return [
            'sermon' => $sermon,
            'processing_log' => $processingLog,
            'audio_path' => $audioPath,
            'audio_contents' => $audioContents,
        ];
    }

    private function export(Sermon $sermon): string
    {
        $path = $this->newBundlePath();

        $this->artisan('sermons:export-promotion-bundle', [
            '--ids' => (string) $sermon->id,
            '--output' => $path,
        ])->assertExitCode(0);

        return $path;
    }

    private function clearPromotableRecords(): void
    {
        SermonProcessingStep::query()->delete();
        MediaProcessingLog::query()->delete();
        Sermon::query()->delete();
        PreacherAlias::query()->delete();
        Preacher::query()->delete();
    }

    private function createScripturePassage(string $reference): ScripturePassage
    {
        return ScripturePassage::query()->create([
            'bible_id' => (string) config('services.api_bible.default_bible_id'),
            'normalized_reference' => $reference,
            'display_reference' => $reference,
            'html_content' => '<p>For God so loved the world.</p>',
            'copyright' => 'Test scripture copyright.',
            'fetched_at' => now(),
        ]);
    }

    private function newBundlePath(): string
    {
        $path = storage_path('scratch/promotion-'.Str::uuid().'.json');
        $this->bundlePaths[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readBundle(string $path): array
    {
        $json = app(SermonPromotionBundleFiles::class)->read($path);
        $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($bundle);

        return $bundle;
    }
}
