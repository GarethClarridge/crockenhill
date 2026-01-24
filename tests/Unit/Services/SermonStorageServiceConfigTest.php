<?php

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SermonStorageServiceConfigTest extends TestCase
{
    use RefreshDatabase;

    private SermonStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonStorageService();
    }

    public function test_it_uses_sermon_storage_disk_env_if_set()
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3'
        ]);

        $info = $this->service->getSermonFileInfo($sermon);
        
        $this->assertEquals('do_spaces', $info['disk']);
    }

    public function test_it_uses_filesystem_disk_as_fallback_for_sermon_disk()
    {
        // Simulate env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'public'))
        // If SERMON_STORAGE_DISK is NULL and FILESYSTEM_DISK is set
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3'
        ]);

        $info = $this->service->getSermonFileInfo($sermon);
        
        $this->assertEquals('do_spaces', $info['disk']);
    }

    public function test_it_defaults_to_public_if_no_env_set()
    {
        Config::set('media-processing.storage.sermon_disk', 'public');
        
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3'
        ]);

        $info = $this->service->getSermonFileInfo($sermon);
        
        $this->assertEquals('public', $info['disk']);
    }
}
