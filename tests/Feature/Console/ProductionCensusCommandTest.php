<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\ChurchServiceReviewDecision;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use App\Models\SongVideo;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Services\Import\ProductionCensusExporter;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = storage_path('scratch/census-export-20261114-wp26/test-'.bin2hex(random_bytes(8)));
        mkdir($this->folder, 0700, true);
        Storage::fake('census', ['root' => $this->folder.'/disk']);
        config([
            'media-processing.storage.sermon_disk' => 'census',
            'media-processing.storage.transcript_disk' => 'census',
            'thumbnail-generation.storage.disk' => 'census',
        ]);
    }

    #[Test]
    public function it_exports_the_complete_identity_graph_without_vectors_or_lyrics(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceReviewDecision::factory()->create();
        ChurchServiceProposalDecisionRule::factory()->create();
        ChurchServiceProposalClassReview::factory()->create();
        $song = Song::factory()->create(['import_metadata' => ['openlp_id' => 42], 'lyrics_plain' => 'Private lyric']);
        $author = SongAuthor::factory()->create();
        $book = SongBook::factory()->create();
        $song->authors()->attach($author, ['author_type' => 'words']);
        $song->books()->attach($book, ['entry' => '12']);
        $item = ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'song_id' => $song->id]);
        $deleted = ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'position' => 2]);
        $deleted->delete();
        $source = ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        ChurchServiceItemAssertion::factory()->create(['source_record_id' => $source->id]);
        ChurchServiceMergeProposal::factory()->create(['church_service_id' => $service->id, 'trigger_source_record_id' => $source->id]);
        $sermon = Sermon::factory()->create([
            'title' => 'God’s grace', 'series' => 'Romans', 'partial_recording' => true,
            'audio_file_path' => 'audio.mp3', 'video_file_path' => 'video.mp4',
            'transcript_file_path' => 'text.txt', 'thumbnail_file_path' => 'thumb.webp', 'asset_disk' => 'census',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'plain.webp', 'thumbnail_candidates' => [['id' => 'one', 'plain_path' => 'candidate.webp']]],
        ]);
        $run = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'church_service_id' => $service->id]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id, 'church_service_item_id' => $item->id,
            'extracted_video_path' => 'section.mp4', 'extracted_audio_path' => 'section.mp3', 'asset_disk' => 'census',
        ]);
        SongVideo::factory()->create(['song_id' => $song->id, 'church_service_id' => $service->id,
            'service_section_id' => $section->id, 'video_file_path' => 'clip.mp4', 'asset_disk' => 'census']);
        $profile = SpeakerProfile::factory()->create(['centroid_embedding' => [0.125, 0.875]]);
        SpeakerSample::factory()->create(['speaker_profile_id' => $profile->id, 'embedding' => [0.25, 0.75]]);
        $profile->preacher->aliases()->create(['alias' => 'census preacher alias']);
        foreach (['audio.mp3', 'video.mp4', 'text.txt', 'thumb.webp', 'plain.webp', 'candidate.webp', 'section.mp4', 'section.mp3', 'clip.mp4'] as $path) {
            Storage::disk('census')->put($path, 'bytes-'.$path);
        }
        $rows = $this->export();
        $header = $rows[0];
        $this->assertSame(1, $header['version']);
        $this->assertSame('all_rows_including_soft_deleted', $header['scope']);
        $this->assertMatchesRegularExpression('/Z$/', $header['captured_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $header['code_revision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $header['schema_migration_sha256']);
        foreach (['sermons', 'church_services', 'church_service_items', 'church_service_source_records',
            'church_service_item_assertions', 'church_service_merge_proposals', 'service_sections', 'song_videos',
            'speaker_profiles', 'speaker_samples', 'songs', 'song_authors', 'song_books', 'song_author_song', 'song_book_song', 'media_processing_logs',
            'preachers', 'preacher_aliases', 'church_service_review_sessions', 'church_service_review_decisions',
            'church_service_proposal_decision_rules', 'church_service_proposal_class_reviews'] as $table) {
            $this->assertGreaterThan(0, $header['counts'][$table], $table);
            $this->assertNotEmpty(array_filter($rows, fn (array $r): bool => ($r['table'] ?? '') === $table));
        }
        $this->assertSame(0600, fileperms($this->folder.'/census.jsonl') & 0777);
        $out = $this->row($rows, 'sermons', $sermon->id);
        $this->assertSame('Romans', $out['series']);
        $this->assertSame(1, $out['partial_recording']);
        $asset = $out['_assets']['audio_file_path'];
        $this->assertSame('census', $asset['disk']);
        $this->assertTrue($asset['exists']);
        $this->assertSame(strlen('bytes-audio.mp3'), $asset['bytes']);
        $this->assertSame(hash('sha256', 'bytes-audio.mp3'), $asset['sha256']);
        $this->assertTrue($out['_assets']['thumbnail_metadata.plain_thumbnail_path']['exists']);
        $this->assertTrue($out['_assets']['thumbnail_metadata.thumbnail_candidates.0.plain_path']['exists']);
        $this->assertNotNull($this->row($rows, 'church_service_items', $deleted->id)['deleted_at']);
        $profileRow = $this->row($rows, 'speaker_profiles', $profile->id);
        $this->assertArrayNotHasKey('centroid_embedding', $profileRow);
        $this->assertSame(hash('sha256', '[0.125,0.875]'), $profileRow['centroid_embedding_sha256']);
        $songRow = $this->row($rows, 'songs', $song->id);
        $this->assertArrayNotHasKey('lyrics_plain', $songRow);
        $this->assertSame(hash('sha256', 'Private lyric'), $songRow['lyrics_plain_sha256']);
        $this->assertSame(42, $songRow['import_metadata']['openlp_id']);
    }

    #[Test]
    public function it_records_missing_and_unreadable_files_without_aborting(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'missing.mp3', 'video_file_path' => 'unreadable.mp4', 'asset_disk' => 'broken']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with('missing.mp3')->andReturnFalse();
        $disk->shouldReceive('exists')->with('unreadable.mp4')->andReturnTrue();
        $disk->shouldReceive('size')->with('unreadable.mp4')->andReturn(12);
        $disk->shouldReceive('readStream')->with('unreadable.mp4')->andThrow(new \RuntimeException('private endpoint'));
        Storage::shouldReceive('disk')->with('broken')->andReturn($disk);
        $rows = $this->export();
        $out = current(array_filter($rows, fn (array $r): bool => ($r['table'] ?? '') === 'sermons'))['row'];
        $this->assertFalse($out['_assets']['audio_file_path']['exists']);
        $this->assertSame('missing', $out['_assets']['audio_file_path']['status']);
        $this->assertTrue($out['_assets']['video_file_path']['exists']);
        $this->assertSame(12, $out['_assets']['video_file_path']['bytes']);
        $this->assertSame('unreadable', $out['_assets']['video_file_path']['status']);
        $this->assertStringNotContainsString('private endpoint', file_get_contents($this->folder.'/census.jsonl'));
    }

    #[Test]
    public function it_refuses_to_overwrite_even_a_symlink(): void
    {
        $path = $this->folder.'/existing.jsonl';
        file_put_contents($path, 'keep');
        $this->artisan('historic-import:production-census', ['--output' => $path])->assertFailed();
        $this->assertSame('keep', file_get_contents($path));
        symlink($path, $this->folder.'/link.jsonl');
        $this->artisan('historic-import:production-census', ['--output' => $this->folder.'/link.jsonl'])->assertFailed();
        $this->assertSame('keep', file_get_contents($path));
    }

    #[Test]
    public function it_issues_only_selects_and_never_writes_to_disks_or_dispatches(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'present.mp3', 'asset_disk' => 'census']);
        Storage::disk('census')->put('present.mp3', 'original');
        $before = Storage::disk('census')->allFiles();
        Bus::fake();
        $cacheStore = Cache::store();
        Cache::shouldReceive('driver')->andReturn($cacheStore);
        Cache::shouldReceive('store')->andReturn($cacheStore);
        foreach (['put', 'forever', 'forget', 'flush', 'increment', 'decrement', 'remember', 'lock'] as $method) {
            Cache::shouldReceive($method)->never();
        }
        $fake = Storage::disk('census');
        $guard = \Mockery::mock(FilesystemAdapter::class);
        foreach (['exists', 'size', 'readStream'] as $method) {
            $guard->shouldReceive($method)->andReturnUsing(fn (string $path): mixed => $fake->{$method}($path));
        }
        foreach (['put', 'write', 'writeStream', 'putFile', 'putFileAs', 'append', 'prepend', 'delete',
            'deleteDirectory', 'makeDirectory', 'move', 'copy', 'setVisibility', 'temporaryUploadUrl'] as $method) {
            $guard->shouldReceive($method)->never();
        }
        Storage::shouldReceive('disk')->with('census')->andReturn($guard);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $rows = $this->export();
        $sermon = current(array_filter($rows, fn (array $row): bool => ($row['table'] ?? '') === 'sermons'))['row'];
        $this->assertSame('hashed', $sermon['_assets']['audio_file_path']['status']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\s/i', $sql, $sql);
        }
        $this->assertSame($before, $fake->allFiles());
        $this->assertSame('original', $fake->get('present.mp3'));
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function its_footer_hashes_every_preceding_byte_and_counts_rows(): void
    {
        Preacher::factory()->create();
        $rows = $this->export();
        $lines = file($this->folder.'/census.jsonl');
        $footer = array_pop($rows);
        array_pop($lines);
        $this->assertSame('footer', $footer['tag']);
        $this->assertSame(0, $footer['counts']['songs']);
        $this->assertSame(hash('sha256', implode('', $lines)), $footer['sha256']);
        $this->assertSame($rows[0]['counts'], $footer['counts']);
        $this->assertSame(count($rows) - 1, $footer['rows']);
    }

    #[Test]
    public function skip_hashes_still_measures_sizes_and_does_not_open_streams(): void
    {
        $sermon = Sermon::factory()->create(['audio_file_path' => 'fast.mp3', 'asset_disk' => 'fast']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with('fast.mp3')->andReturnTrue();
        $disk->shouldReceive('size')->with('fast.mp3')->andReturn(123);
        $disk->shouldNotReceive('readStream');
        Storage::shouldReceive('disk')->with('fast')->andReturn($disk);
        $asset = $this->row($this->export(['--skip-hashes' => true]), 'sermons', $sermon->id)['_assets']['audio_file_path'];
        $this->assertSame(123, $asset['bytes']);
        $this->assertArrayNotHasKey('sha256', $asset);
        $this->assertSame('hash_skipped', $asset['status']);
    }

    #[Test]
    public function it_starts_a_real_mysql_read_only_consistent_snapshot_outside_test_transactions(): void
    {
        DB::rollBack();
        try {
            $rows = $this->export();
            $this->assertSame('mysql_read_only_consistent_snapshot', $rows[0]['snapshot']);
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::beginTransaction();
        }
    }

    #[Test]
    public function missing_optional_tables_are_explicitly_counted_and_reported(): void
    {
        $this->app->bind(ProductionCensusExporter::class, fn () => new class extends ProductionCensusExporter
        {
            protected function schema(Connection $connection): array
            {
                $schema = parent::schema($connection);
                unset($schema['church_service_merge_proposals'], $schema['church_service_source_records']);

                return $schema;
            }
        });
        $header = $this->export()[0];
        $this->assertContains('church_service_source_records', $header['missing_tables']);
        $this->assertSame(0, $header['counts']['church_service_source_records']);
        $this->assertContains('church_service_merge_proposals', $header['missing_tables']);
    }

    #[Test]
    public function it_never_creates_a_missing_local_disk_root(): void
    {
        $root = $this->folder.'/must-not-exist';
        config(['filesystems.disks.absent-local' => ['driver' => 'local', 'root' => $root]]);
        $sermon = Sermon::factory()->create(['audio_file_path' => 'audio.mp3', 'asset_disk' => 'absent-local']);
        $asset = $this->row($this->export(), 'sermons', $sermon->id)['_assets']['audio_file_path'];
        $this->assertSame('unreadable', $asset['status']);
        $this->assertDirectoryDoesNotExist($root);
    }

    #[Test]
    public function runs_retain_the_operation_identity_present_in_the_october_census(): void
    {
        $run = MediaProcessingLog::factory()->create(['historic_import_operation_id' => null]);
        $row = $this->row($this->export(), 'media_processing_logs', $run->id);
        $this->assertArrayHasKey('historic_import_operation_id', $row);
        $this->assertNull($row['historic_import_operation_id']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function export(array $options = []): array
    {
        $path = $this->folder.'/census.jsonl';
        $status = Artisan::call('historic-import:production-census', ['--output' => $path, ...$options]);
        $this->assertSame(0, $status, Artisan::output());

        return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function row(array $rows, string $table, int $id): array
    {
        foreach ($rows as $row) {
            if (($row['table'] ?? '') === $table && $row['row']['id'] === $id) {
                return $row['row'];
            }
        }
        $this->fail("Missing {$table}:{$id}");
    }
}
