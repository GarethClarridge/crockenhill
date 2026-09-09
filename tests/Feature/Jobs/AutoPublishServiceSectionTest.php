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
    public function it_withholds_a_section_held_for_review_after_the_job_was_queued(): void
    {
        // The window this closes: preparation judged the section eligible and
        // queued the job, then the 2026-09-09 repetition screen held it. The
        // transition table does not help — `not_applicable` to `published` is
        // allowed, and 157 of the 194 held sections sit in exactly that state.
        [$section] = $this->publishableSongSection();
        $section->forceFill(['needs_manual_review' => true])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_withholds_a_section_whose_run_was_retired_after_the_job_was_queued(): void
    {
        // A retired run's result is withdrawn, so publishing from it would
        // republish a conclusion the import has already taken back.
        [$section, $processingLog] = $this->publishableSongSection();
        $processingLog->forceFill(['superseded_at' => now()])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_withholds_a_section_that_has_lost_its_handler_eligibility(): void
    {
        // A song whose match was withdrawn to unmatched is no longer eligible —
        // inferred still is — and the queued job has no way to know unless it
        // asks again.
        [$section] = $this->publishableSongSection();
        $section->forceFill(['song_match_type' => ServiceSectionSongMatchType::Unmatched->value])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_still_publishes_a_section_that_is_genuinely_unchanged(): void
    {
        // The guard must not become a refusal to publish anything: these are the
        // same questions preparation asked, and a section that still answers
        // them the same way publishes exactly as before.
        [$section] = $this->publishableSongSection();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    /** @return array{0: ServiceSection, 1: MediaProcessingLog} */
    private function publishableSongSection(): array
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

        $videoPath = 'section-publications/guard/video.mp4';
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
            'needs_manual_review' => false,
        ]);

        return [$section, $processingLog];
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
