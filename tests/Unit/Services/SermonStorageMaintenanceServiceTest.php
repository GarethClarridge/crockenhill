<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\SermonStorageMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonStorageMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('do_spaces');
        Storage::fake('public_images');
        Storage::fake('local');

        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.legacy_disk', 'public');
    }

    public function test_migrate_storage_copies_legacy_sermons_through_shared_service(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);
        Storage::disk('public_images')->put('media/sermons/legacy-sermon.mp3', 'legacy-content');

        $result = app(SermonStorageMaintenanceService::class)->migrateStorage(
            targetDisk: 'do_spaces',
            patterns: ['legacy'],
            dryRun: false,
            batchSize: 10,
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('do_spaces')->assertExists('legacy/sermons/legacy-sermon.mp3');
    }

    public function test_migrate_livestream_audio_can_cleanup_after_success(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/2026/03/example.mp3',
        ]);
        Storage::disk('local')->put('sermons/2026/03/example.mp3', 'audio-content');

        $result = app(SermonStorageMaintenanceService::class)->migrateLivestreamAudio(
            dryRun: false,
            cleanup: true,
        );

        $this->assertSame(1, $result['summary']['migrated']);
        Storage::disk('public')->assertExists('sermons/2026/03/example.mp3');
        Storage::disk('local')->assertMissing('sermons/2026/03/example.mp3');
    }

    public function test_verify_storage_reports_missing_files_through_shared_service(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/present.mp3',
        ]);
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing.mp3',
        ]);
        Storage::disk('public')->put('sermons/present.mp3', 'present');

        $result = app(SermonStorageMaintenanceService::class)->verifyStorage('public');

        $this->assertSame(1, $result['summary']['accessible']);
        $this->assertSame(1, $result['summary']['missing']);
        $this->assertCount(1, $result['missing']);
    }
}
