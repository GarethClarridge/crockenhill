<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupUnpublishedSectionAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_delete_files_in_dry_run_mode(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'published_sermon_id' => null,
            'extracted_video_path' => 'sermons/sections/70/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-70.mp3',
            'extracted_at' => now()->subHours(72),
            'unpublished_expires_at' => now()->subHour(),
        ]);

        Storage::disk('public')->put('sermons/sections/70/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-70.mp3', 'audio');

        $this->artisan('media:cleanup-unpublished-section-assets', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN enabled')
            ->assertSuccessful();

        $section->refresh();
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNotNull($section->extracted_audio_path);
        Storage::disk('public')->assertExists('sermons/sections/70/video.mp4');
        Storage::disk('public')->assertExists('sermons/audio/section-70.mp3');
    }

    #[Test]
    public function it_deletes_expired_unpublished_assets_and_clears_columns(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'published_sermon_id' => null,
            'extracted_video_path' => 'sermons/sections/71/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-71.mp3',
            'extracted_at' => now()->subHours(72),
            'unpublished_expires_at' => now()->subHour(),
        ]);

        Storage::disk('public')->put('sermons/sections/71/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-71.mp3', 'audio');

        $this->artisan('media:cleanup-unpublished-section-assets')->assertSuccessful();

        $section->refresh();
        $this->assertNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNull($section->extracted_at);
        $this->assertNull($section->unpublished_expires_at);
        Storage::disk('public')->assertMissing('sermons/sections/71/video.mp4');
        Storage::disk('public')->assertMissing('sermons/audio/section-71.mp3');
    }
}
