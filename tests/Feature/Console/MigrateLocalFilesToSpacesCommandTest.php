<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
