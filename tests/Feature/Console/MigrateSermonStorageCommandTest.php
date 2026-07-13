<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateSermonStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public_images');
        Storage::fake('do_spaces');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.legacy_disk', 'public');
    }

    public function test_it_migrates_legacy_sermons_via_shared_storage_service(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);
        Storage::disk('public_images')->put('media/sermons/legacy-sermon.mp3', 'legacy-content');

        $this->artisan('sermons:migrate-storage', ['--target' => 'do_spaces', '--pattern' => 'legacy'])
            ->expectsOutputToContain('Migrated: 1')
            ->assertSuccessful();

        Storage::disk('do_spaces')->assertExists('legacy/sermons/legacy-sermon.mp3');
        $this->assertSame('legacy/sermons/legacy-sermon.mp3', $sermon->refresh()->audio_file_path);
    }
}
