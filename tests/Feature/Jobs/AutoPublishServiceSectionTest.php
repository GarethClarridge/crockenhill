<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\AutoPublishServiceSection;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoPublishServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_auto_publishes_a_song_section(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $churchService = ChurchService::factory()->create(['date' => '2026-03-20']);
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $videoPath = 'section-publications/1/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => null,
            'extracted_at' => now(),
            'duration' => 200.0,
        ]);

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull($section->published_at);

        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->first();
        $this->assertNotNull($songVideo);
        $this->assertEquals($song->id, $songVideo->song_id);
    }

    #[Test]
    public function it_skips_already_published_sections(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $initialCount = SongVideo::count();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $this->assertEquals($initialCount, SongVideo::count());
    }

    #[Test]
    public function it_skips_when_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
    }

    #[Test]
    public function it_throws_when_no_handler_registered(): void
    {
        config(['media-processing.section_publishing.handlers' => []]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No publication handler registered');

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );
    }

    #[Test]
    public function it_handles_missing_section_gracefully(): void
    {
        // A missing section id is a no-op rather than an error.
        $this->expectNotToPerformAssertions();

        (new AutoPublishServiceSection(99999))->handle(
            app(SectionPublicationHandlerFactory::class)
        );
    }
}
