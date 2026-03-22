<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateLivestreamAudioFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_migrates_livestream_audio_and_can_cleanup_source_files(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/2026/03/example.mp3',
        ]);
        Storage::disk('local')->put('sermons/2026/03/example.mp3', 'audio-content');

        $this->artisan('sermons:migrate-livestream-audio', ['--cleanup' => true])
            ->expectsOutputToContain('- Migrated: 1 files')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('sermons/2026/03/example.mp3');
        Storage::disk('local')->assertMissing('sermons/2026/03/example.mp3');
    }
}
