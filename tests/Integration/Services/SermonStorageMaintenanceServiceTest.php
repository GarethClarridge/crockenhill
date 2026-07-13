<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Sermon;
use App\Services\Sermon\SermonStorageMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonStorageMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonStorageMaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Sermon::query()->delete();

        Storage::fake('public');
        Storage::fake('do_spaces');
        Storage::fake('public_images');
        Storage::fake('local');

        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.legacy_disk', 'public');

        $this->service = app(SermonStorageMaintenanceService::class);
    }

    #[Test]
    public function it_migrates_storage_copies_legacy_sermons_through_shared_service(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);
        Storage::disk('public_images')->put('media/sermons/legacy-sermon.mp3', 'legacy-content');

        $result = $this->service->migrateStorage(
            targetDisk: 'do_spaces',
            patterns: ['legacy'],
            dryRun: false,
            batchSize: 10,
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('do_spaces')->assertExists('legacy/sermons/legacy-sermon.mp3');
        $this->assertSame('legacy/sermons/legacy-sermon.mp3', $sermon->refresh()->audio_file_path);
    }

    #[Test]
    public function it_canonicalises_a_legacy_path_when_the_target_file_already_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);
        Storage::disk('do_spaces')->put('legacy/sermons/legacy-sermon.mp3', 'legacy-content');

        $dryRunResult = $this->service->migrateStorage(
            targetDisk: 'do_spaces',
            patterns: ['legacy'],
            dryRun: true,
            batchSize: 10,
        );

        $this->assertSame(1, $dryRunResult['summary']['migrated']);
        $this->assertSame('legacy-sermon', $sermon->refresh()->audio_file_path);

        $result = $this->service->migrateStorage(
            targetDisk: 'do_spaces',
            patterns: ['legacy'],
            dryRun: false,
            batchSize: 10,
        );

        $this->assertSame(1, $result['summary']['migrated']);
        $this->assertSame('legacy/sermons/legacy-sermon.mp3', $sermon->refresh()->audio_file_path);
    }

    #[Test]
    public function it_canonicalises_every_legacy_path_across_multiple_batches(): void
    {
        $sermons = Sermon::factory()->count(3)->sequence(
            ['audio_file_path' => 'legacy-one', 'filetype' => 'mp3'],
            ['audio_file_path' => 'legacy-two', 'filetype' => 'mp3'],
            ['audio_file_path' => 'legacy-three', 'filetype' => 'mp3'],
        )->create();

        foreach (['legacy-one', 'legacy-two', 'legacy-three'] as $filename) {
            Storage::disk('do_spaces')->put("legacy/sermons/{$filename}.mp3", 'legacy-content');
        }

        $result = $this->service->migrateStorage(
            targetDisk: 'do_spaces',
            patterns: ['legacy'],
            dryRun: false,
            batchSize: 1,
        );

        $this->assertSame(3, $result['summary']['migrated']);
        $this->assertSame(
            [
                'legacy/sermons/legacy-one.mp3',
                'legacy/sermons/legacy-two.mp3',
                'legacy/sermons/legacy-three.mp3',
            ],
            $sermons->map(fn (Sermon $sermon): ?string => $sermon->refresh()->audio_file_path)->all(),
        );
    }

    #[Test]
    public function it_migrates_livestream_audio_can_cleanup_after_success(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/2026/03/example.mp3',
        ]);
        Storage::disk('local')->put('sermons/2026/03/example.mp3', 'audio-content');

        $result = $this->service->migrateLivestreamAudio(
            dryRun: false,
            cleanup: true,
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('public')->assertExists('sermons/2026/03/example.mp3');
        Storage::disk('local')->assertMissing('sermons/2026/03/example.mp3');
    }

    #[Test]
    public function it_verifies_storage_reports_missing_files_through_shared_service(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/present.mp3',
        ]);
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing.mp3',
        ]);
        Storage::disk('public')->put('sermons/present.mp3', 'present');

        $result = $this->service->verifyStorage('public');

        $this->assertSame(1, $result['summary']['accessible']);
        $this->assertSame(1, $result['summary']['missing']);
        $this->assertCount(1, $result['missing']);
    }

    #[Test]
    public function it_verifies_only_sermons_with_audio_on_their_canonical_disk(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/public.mp3',
        ]);
        Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/childrens-talk.mp3',
        ]);
        Sermon::factory()->create([
            'audio_file_path' => null,
        ]);

        Storage::disk('public')->put('sermons/public.mp3', 'public audio');
        Storage::disk('local')->put('private/sermons/childrens-talk.mp3', 'private audio');

        $result = $this->service->verifyStorage('public');

        $this->assertSame(2, $this->service->countVerifiableSermons());
        $this->assertSame(2, $result['summary']['accessible']);
        $this->assertSame(0, $result['summary']['missing']);
        $this->assertSame(0, $result['storage_stats']['missing_files']);
        $this->assertSame(1, $result['storage_stats']['patterns']['private']);
    }

    #[Test]
    public function it_migrates_local_files_to_target_disk(): void
    {
        Storage::disk('public')->put('sermons/file1.mp3', 'content1');
        Storage::disk('public')->put('sermons/file2.mp3', 'content2');

        $result = $this->service->migrateLocalFiles(
            dryRun: false,
            sourceDisk: 'public',
            targetDisk: 'do_spaces'
        );

        $this->assertSame(2, $result['summary']['migrated']);
        Storage::disk('do_spaces')->assertExists('sermons/file1.mp3');
        Storage::disk('do_spaces')->assertExists('sermons/file2.mp3');
        $this->assertEquals('content1', Storage::disk('do_spaces')->get('sermons/file1.mp3'));
    }

    #[Test]
    public function it_respects_dry_run_for_local_files(): void
    {
        Storage::disk('public')->put('sermons/dry-run.mp3', 'content');

        $result = $this->service->migrateLocalFiles(
            dryRun: true,
            sourceDisk: 'public',
            targetDisk: 'do_spaces'
        );

        $this->assertSame(1, $result['summary']['migrated']);
        $this->assertTrue($result['dry_run']);
        Storage::disk('do_spaces')->assertMissing('sermons/dry-run.mp3');
    }

    #[Test]
    public function it_skips_existing_files_in_local_migration_unless_forced(): void
    {
        Storage::disk('public')->put('sermons/skip.mp3', 'new-content');
        Storage::disk('do_spaces')->put('sermons/skip.mp3', 'old-content');

        // Without force
        $result = $this->service->migrateLocalFiles(
            dryRun: false,
            sourceDisk: 'public',
            targetDisk: 'do_spaces',
            force: false
        );

        $this->assertSame(1, $result['summary']['skipped']);
        $this->assertEquals('old-content', Storage::disk('do_spaces')->get('sermons/skip.mp3'));

        // With force
        $result = $this->service->migrateLocalFiles(
            dryRun: false,
            sourceDisk: 'public',
            targetDisk: 'do_spaces',
            force: true
        );

        $this->assertSame(1, $result['summary']['migrated']);
        $this->assertEquals('new-content', Storage::disk('do_spaces')->get('sermons/skip.mp3'));
    }

    #[Test]
    public function it_migrates_referenced_sermon_audio_only(): void
    {
        // Referenced in DB
        Sermon::factory()->create(['audio_file_path' => 'sermons/referenced.mp3']);
        Storage::disk('public')->put('sermons/referenced.mp3', 'referenced-content');

        // Not referenced in DB but exists in storage
        Storage::disk('public')->put('sermons/unreferenced.mp3', 'unreferenced-content');

        $result = $this->service->migrateReferencedSermonAudio(
            dryRun: false,
            sourceDisk: 'public',
            targetDisk: 'do_spaces'
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('do_spaces')->assertExists('sermons/referenced.mp3');
        Storage::disk('do_spaces')->assertMissing('sermons/unreferenced.mp3');
    }

    #[Test]
    public function it_migrates_referenced_sermon_audio_with_filters(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'sermons/2023/test.mp3']);
        Sermon::factory()->create(['audio_file_path' => 'sermons/2024/test.mp3']);
        Storage::disk('public')->put('sermons/2023/test.mp3', '2023');
        Storage::disk('public')->put('sermons/2024/test.mp3', '2024');

        $result = $this->service->migrateReferencedSermonAudio(
            dryRun: false,
            sourceDisk: 'public',
            targetDisk: 'do_spaces',
            pathPrefix: 'sermons/2024'
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('do_spaces')->assertExists('sermons/2024/test.mp3');
        Storage::disk('do_spaces')->assertMissing('sermons/2023/test.mp3');
    }

    #[Test]
    public function it_counts_storage_candidates_accurately(): void
    {
        // Legacy: audio_file_path does NOT contain a slash
        Sermon::factory()->create(['audio_file_path' => 'legacyfile']);

        // Storage: audio_file_path contains a slash
        Sermon::factory()->create(['audio_file_path' => 'sermons/storage.mp3']);

        // Processing: transcript_file_path or video_file_path is set
        Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/test.txt',
            'audio_file_path' => null,
        ]);

        $this->assertEquals(1, $this->service->countStorageCandidates(['legacy']));
        $this->assertEquals(1, $this->service->countStorageCandidates(['storage']));
        $this->assertEquals(1, $this->service->countStorageCandidates(['processing']));
    }

    #[Test]
    public function it_counts_local_files_accurately(): void
    {
        Storage::disk('public')->put('sermons/file1.mp3', 'c1');
        Storage::disk('public')->put('sermons/file2.mp3', 'c2');

        $this->assertEquals(2, $this->service->countLocalFiles(sourceDisk: 'public'));
        $this->assertEquals(1, $this->service->countLocalFiles(sourceDisk: 'public', startAfter: 'sermons/file1.mp3'));
    }

    #[Test]
    public function it_counts_referenced_sermon_audio_candidates_accurately(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'sermons/ref1.mp3']);
        Sermon::factory()->create(['audio_file_path' => 'sermons/ref2.mp3']);

        $this->assertEquals(2, $this->service->countReferencedSermonAudioCandidates());
    }

    #[Test]
    public function it_counts_livestream_audio_candidates_accurately(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'sermons/2024/01/livestream.mp3']);

        $this->assertEquals(1, $this->service->countLivestreamAudioCandidates());
    }
}
