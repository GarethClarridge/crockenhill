<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifySermonStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.legacy_disk', 'public');
    }

    public function test_it_reports_missing_files_through_shared_storage_service(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'sermons/present.mp3']);
        Sermon::factory()->create(['audio_file_path' => 'sermons/missing.mp3']);
        Storage::disk('public')->put('sermons/present.mp3', 'audio-content');

        $this->artisan('sermons:verify-storage', ['--disk' => 'public'])
            ->expectsOutputToContain('Missing files: 1')
            ->assertExitCode(1);
    }
}
