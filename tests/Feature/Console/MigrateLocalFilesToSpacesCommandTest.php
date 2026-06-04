<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use App\Services\Sermon\SermonStorageMaintenanceService;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Tests\TestCase;

class MigrateLocalFilesToSpacesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('do_spaces');
    }

    public function test_it_migrates_local_public_sermon_files_via_shared_service(): void
    {
        Storage::disk('public')->put('sermons/example.mp3', 'audio-content');

        $this->artisan('sermons:migrate-local-files')
            ->expectsOutputToContain('Migrated: 1')
            ->assertSuccessful();

        Storage::disk('do_spaces')->assertExists('sermons/example.mp3');
    }

    public function test_force_option_overwrites_existing_remote_files(): void
    {
        Storage::disk('public')->put('sermons/example.mp3', 'fresh-audio');
        Storage::disk('do_spaces')->put('sermons/example.mp3', 'stale-audio');

        $this->artisan('sermons:migrate-local-files', ['--force' => true])
            ->expectsOutputToContain('Migrated: 1')
            ->assertSuccessful();

        $this->assertSame('fresh-audio', Storage::disk('do_spaces')->get('sermons/example.mp3'));
    }

    public function test_referenced_sermon_audio_mode_only_migrates_unique_sermon_audio_paths(): void
    {
        Storage::disk('public')->put('sermons/audio/2013/03/imported-a.mp3', 'audio-a');
        Storage::disk('public')->put('sermons/audio/2013/03/imported-b.mp3', 'audio-b');
        Storage::disk('public')->put('sermons/audio/2013/03/unreferenced.mp3', 'audio-c');
        Storage::disk('public')->put('sermons/thumbnails/example.webp', 'thumbnail');
        Storage::disk('public')->put('sermons/29/video.mkv', 'video');

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio/2013/03/imported-a.mp3',
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio/2013/03/imported-a.mp3',
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio/2013/03/imported-b.mp3',
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);

        $this->artisan('sermons:migrate-local-files', ['--referenced-sermon-audio' => true])
            ->expectsOutputToContain('Mode: sermon-referenced audio')
            ->expectsOutputToContain('Migrated: 2')
            ->assertSuccessful();

        Storage::disk('do_spaces')->assertExists('sermons/audio/2013/03/imported-a.mp3');
        Storage::disk('do_spaces')->assertExists('sermons/audio/2013/03/imported-b.mp3');
        Storage::disk('do_spaces')->assertMissing('sermons/audio/2013/03/unreferenced.mp3');
        Storage::disk('do_spaces')->assertMissing('sermons/thumbnails/example.webp');
        Storage::disk('do_spaces')->assertMissing('sermons/29/video.mkv');
    }

    public function test_referenced_sermon_audio_mode_continues_after_target_existence_check_failure(): void
    {
        $failingPath = 'sermons/audio/2013/03/imported-a.mp3';
        $successfulPath = 'sermons/audio/2013/03/imported-b.mp3';

        Storage::disk('public')->put($failingPath, 'audio-a');
        Storage::disk('public')->put($successfulPath, 'audio-b');

        Sermon::factory()->create([
            'audio_file_path' => $failingPath,
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => $successfulPath,
            'filetype' => 'mp3',
        ]);

        $service = new class(new SermonStorageService) extends SermonStorageMaintenanceService
        {
            protected function targetFileExists(string $targetDisk, string $path): bool
            {
                if ($path === 'sermons/audio/2013/03/imported-a.mp3') {
                    throw UnableToCheckFileExistence::forLocation($path);
                }

                return parent::targetFileExists($targetDisk, $path);
            }
        };

        $this->app->instance(SermonStorageMaintenanceService::class, $service);

        $this->artisan('sermons:migrate-local-files', ['--referenced-sermon-audio' => true])
            ->expectsOutputToContain('Failed: 1')
            ->expectsOutputToContain('Migrated: 1')
            ->assertExitCode(1);

        Storage::disk('do_spaces')->assertMissing($failingPath);
        Storage::disk('do_spaces')->assertExists($successfulPath);
    }

    public function test_referenced_sermon_audio_mode_can_resume_after_a_specific_path(): void
    {
        $firstPath = 'sermons/audio/2013/03/imported-a.mp3';
        $secondPath = 'sermons/audio/2013/03/imported-b.mp3';
        $thirdPath = 'sermons/audio/2013/03/imported-c.mp3';

        Sermon::factory()->create([
            'audio_file_path' => $firstPath,
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => $secondPath,
            'filetype' => 'mp3',
        ]);

        Sermon::factory()->create([
            'audio_file_path' => $thirdPath,
            'filetype' => 'mp3',
        ]);

        $this->artisan('sermons:migrate-local-files', [
            '--referenced-sermon-audio' => true,
            '--start-after' => $firstPath,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain("Start after: {$firstPath}")
            ->expectsOutputToContain("Would migrate {$secondPath} to do_spaces")
            ->expectsOutputToContain("Would migrate {$thirdPath} to do_spaces")
            ->doesntExpectOutputToContain("Would migrate {$firstPath} to do_spaces")
            ->expectsOutputToContain('Examined: 2')
            ->assertSuccessful();
    }
}
