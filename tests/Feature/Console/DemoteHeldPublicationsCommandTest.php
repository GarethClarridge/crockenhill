<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoteHeldPublicationsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_run_without_an_explicit_selection(): void
    {
        $this->artisan('service:demote-held-publications')
            ->expectsOutputToContain('Name what to reconcile')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_without_writing_anything_by_default(): void
    {
        [$section, $video] = $this->heldPublishedSong();

        $this->artisan('service:demote-held-publications', ['--all' => true])->assertSuccessful();

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->fresh()->publication_status);
        $this->assertSame(SermonPublicationState::Published, $video->fresh()->publication_state);
    }

    #[Test]
    public function it_demotes_a_held_section_and_takes_its_video_out_of_view_when_applied(): void
    {
        [$section, $video] = $this->heldPublishedSong();

        $this->artisan('service:demote-held-publications', ['--all' => true, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->fresh()->publication_status);
        $this->assertSame(SermonPublicationState::Quarantined, $video->fresh()->publication_state);
        // Demotion is not deletion — the artifact survives for whoever adjudicates it.
        $this->assertDatabaseHas('song_videos', ['id' => $video->id]);
    }

    #[Test]
    public function it_leaves_a_healthy_published_section_untouched(): void
    {
        [$section, $video] = $this->heldPublishedSong(needsReview: false);

        $this->artisan('service:demote-held-publications', ['--all' => true, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->fresh()->publication_status);
        $this->assertSame(SermonPublicationState::Published, $video->fresh()->publication_state);
    }

    #[Test]
    public function public_only_skips_a_held_section_nobody_can_see(): void
    {
        // 11 of the 15 held published sections are quarantined historic imports that
        // were never public. --public-only is how an operator sees the real exposure
        // without the rows that only need their status corrected.
        [$section, $video] = $this->heldPublishedSong();
        $video->forceFill(['publication_state' => SermonPublicationState::Quarantined])->save();

        $this->artisan('service:demote-held-publications', ['--all' => true, '--public-only' => true, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->fresh()->publication_status);
    }

    #[Test]
    public function it_errors_rather_than_reporting_an_empty_pass_for_a_section_it_cannot_find(): void
    {
        $this->artisan('service:demote-held-publications', ['--section' => [987654]])
            ->expectsOutputToContain('could not be found')
            ->assertFailed();
    }

    #[Test]
    public function it_is_a_no_op_when_run_a_second_time(): void
    {
        [$section] = $this->heldPublishedSong();

        $this->artisan('service:demote-held-publications', ['--all' => true, '--apply' => true])->assertSuccessful();
        $demotedAt = $section->fresh()->updated_at;

        $this->artisan('service:demote-held-publications', ['--all' => true, '--apply' => true])->assertSuccessful();

        $this->assertEquals($demotedAt, $section->fresh()->updated_at);
    }

    /**
     * @return array{0: ServiceSection, 1: SongVideo}
     */
    private function heldPublishedSong(bool $needsReview = true): array
    {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream(),
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => 'confirmed',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'needs_manual_review' => $needsReview,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => $needsReview ? [ServiceStructureValidator::FLAG_MACRO_SECTION] : [],
            ],
        ]);

        $section->forceFill([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_at' => now(),
            'extracted_video_path' => 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4',
            'extracted_at' => now(),
        ])->save();

        $video = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'song_id' => $song->id,
            'publication_state' => SermonPublicationState::Published,
        ]);

        return [$section->fresh(), $video];
    }
}
