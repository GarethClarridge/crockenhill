<?php

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SermonStorageService();

        Storage::fake('public');
        Storage::fake('do_spaces');
        
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.legacy_disk', 'public');
    }

    #[Test]
    public function it_identifies_legacy_storage_pattern(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'old-sermon',
            'filetype' => 'mp3',
        ]);

        $info = $this->service->getSermonFileInfo($sermon);

        $this->assertEquals('legacy', $info['type']);
        $this->assertEquals('legacy/sermons/old-sermon.mp3', $info['path']);
    }

    #[Test]
    public function it_identifies_storage_pattern(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/2024/01/new-sermon.mp3',
            'filetype' => 'mp3',
        ]);

        $info = $this->service->getSermonFileInfo($sermon);

        $this->assertEquals('storage', $info['type']);
        $this->assertEquals('sermons/2024/01/new-sermon.mp3', $info['path']);
    }

    #[Test]
    public function it_generates_public_url_for_local_disk(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $url = $this->service->getPublicUrl($sermon);

        // Storage::fake('public')->url() typically returns /storage/path
        $this->assertStringContainsString('/storage/sermons/test.mp3', $url);
    }

    #[Test]
    public function it_generates_public_url_for_cdn(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/cdn-test.mp3',
        ]);

        $url = $this->service->getPublicUrl($sermon);

        $this->assertEquals('https://cdn.example.com/sermons/cdn-test.mp3', $url);
    }

    #[Test]
    public function it_checks_file_existence_correctly(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/exists.mp3',
        ]);

        // File doesn't exist yet
        $this->assertFalse($this->service->fileExists($sermon));

        // Create file
        Storage::disk('public')->put('sermons/exists.mp3', 'content');

        $this->assertTrue($this->service->fileExists($sermon));
    }

    #[Test]
    public function it_gets_file_size_correctly(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/size.mp3',
        ]);

        Storage::disk('public')->put('sermons/size.mp3', '12345');

        $this->assertEquals(5, $this->service->getFileSize($sermon));
    }

    #[Test]
    public function it_returns_null_size_for_missing_file(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing.mp3',
        ]);

        $this->assertNull($this->service->getFileSize($sermon));
    }

    #[Test]
    public function it_moves_file_between_disks(): void
    {
        // Setup disks
        Storage::fake('source_disk');
        Storage::fake('target_disk');

        // Configure sermon to use source disk
        Config::set('media-processing.storage.sermon_disk', 'source_disk');

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/move-me.mp3',
        ]);

        // Create file in source
        Storage::disk('source_disk')->put('sermons/move-me.mp3', 'test content');

        // Move to target
        $result = $this->service->moveFile($sermon, 'target_disk');

        $this->assertTrue($result);
        
        // Check target
        Storage::disk('target_disk')->assertExists('sermons/move-me.mp3');
        $this->assertEquals('test content', Storage::disk('target_disk')->get('sermons/move-me.mp3'));
        
        // Service doesn't delete source by default (commented out in code), verify this assumption
        Storage::disk('source_disk')->assertExists('sermons/move-me.mp3');
    }

    #[Test]
    public function it_fails_to_move_nonexistent_file(): void
    {
        Storage::fake('source_disk');
        Storage::fake('target_disk');
        Config::set('media-processing.storage.sermon_disk', 'source_disk');

        $sermon = Sermon::factory()->create(['audio_file_path' => 'sermons/gone.mp3']);

        $result = $this->service->moveFile($sermon, 'target_disk');

        $this->assertFalse($result);
    }
}
