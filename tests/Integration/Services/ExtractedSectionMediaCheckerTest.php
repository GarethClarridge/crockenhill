<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractedSectionMediaCheckerTest extends TestCase
{
    use RefreshDatabase;

    private ExtractedSectionMediaChecker $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExtractedSectionMediaChecker;

        Storage::fake('public');
        Storage::fake('local');
        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function it_returns_false_if_video_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => 'audio.mp3',
        ]);

        $this->assertFalse($this->service->hasExtractedMedia($section));

        $section->update(['extracted_video_path' => '']);
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_false_if_audio_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => null,
        ]);

        $this->assertFalse($this->service->hasExtractedMedia($section));

        $section->update(['extracted_audio_path' => '']);
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_false_if_files_do_not_exist_on_disk(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        // No files created in Storage::fake('public')
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_false_if_only_one_file_exists(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        Storage::disk('public')->put('video.mp4', 'content');

        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_true_if_both_files_exist_on_disk(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        Storage::disk('public')->put('video.mp4', 'video content');
        Storage::disk('public')->put('audio.mp3', 'audio content');

        $this->assertTrue($this->service->hasExtractedMedia($section));
    }

    /**
     * Legacy rows still naming a `private/` path are audited against the sermon
     * disk like everything else. The local private directory is gone, so they
     * correctly present as needing re-extraction rather than being looked up on
     * a disk production no longer writes to.
     */
    #[Test]
    public function it_reports_legacy_private_paths_as_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'private/sections/video.mp4',
            'extracted_audio_path' => 'private/sections/audio.mp3',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('private/sections/video.mp4', 'video content');
        Storage::disk('local')->put('private/sections/audio.mp3', 'audio content');

        $this->assertFalse($this->service->hasExtractedMedia($section));
    }
}
