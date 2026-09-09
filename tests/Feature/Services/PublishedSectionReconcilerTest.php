<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\ChurchService\SectionPublication\PublishedSectionReconciler;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishedSectionReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private PublishedSectionReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = app(PublishedSectionReconciler::class);
    }

    #[Test]
    public function it_names_a_published_section_that_is_held_for_review(): void
    {
        $section = $this->publishedSong(reviewFlags: [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);

        $assessment = $this->reconciler->assess($section);

        $this->assertNotNull($assessment);
        $this->assertSame('needs_manual_review', $assessment['reason']);
        $this->assertStringContainsString(ServiceStructureValidator::FLAG_MACRO_SECTION, $assessment['detail']);
    }

    #[Test]
    public function it_leaves_a_published_section_whose_flag_the_policy_already_ruled_benign(): void
    {
        // 14 of the 29 published sections carrying a flag carry only this one, and
        // SectionReviewFlagPolicy always demotes it: it questions which OoS item the
        // section aligns to, never the section's own quality. Reading the raw flag
        // array rather than the verdict would pull all 14 off the site.
        $section = $this->publishedSong(
            reviewFlags: [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION],
            needsReview: false,
        );

        $this->assertNull($this->reconciler->assess($section));
    }

    #[Test]
    public function it_names_a_published_section_the_handler_no_longer_accepts(): void
    {
        // §444's shape: the section lost the service item that identifies its song,
        // so nothing can say what was published, but the video stayed public.
        $section = $this->publishedSong();
        $section->forceFill(['church_service_item_id' => null])->save();

        $assessment = $this->reconciler->assess($section->fresh());

        $this->assertNotNull($assessment);
        $this->assertSame('handler_ineligible', $assessment['reason']);
    }

    #[Test]
    public function it_leaves_a_healthy_published_section_alone(): void
    {
        $this->assertNull($this->reconciler->assess($this->publishedSong()));
    }

    #[Test]
    public function demoting_takes_the_video_out_of_view_without_deleting_it(): void
    {
        // onSectionRemoved() deletes the file and the row, which is right for a
        // superseded section and wrong for one merely waiting on a person.
        $section = $this->publishedSong(reviewFlags: [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);
        $video = $this->songVideoFor($section, SermonPublicationState::Published);

        $outcome = $this->reconciler->demote($section, (array) $this->reconciler->assess($section));

        $this->assertTrue($outcome['section']);
        $this->assertTrue($outcome['video']);

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull($section->published_at);

        $video->refresh();
        $this->assertSame(SermonPublicationState::Quarantined, $video->publication_state);
        $this->assertDatabaseHas('song_videos', ['id' => $video->id]);
        $this->assertSame(0, SongVideo::query()->publiclyReleased()->count());
    }

    #[Test]
    public function demoting_records_why_and_when_it_was_published(): void
    {
        $section = $this->publishedSong(reviewFlags: [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);
        $this->songVideoFor($section, SermonPublicationState::Published);
        $publishedAt = $section->published_at?->toISOString();

        $this->reconciler->demote($section, (array) $this->reconciler->assess($section));

        $demoted = $section->fresh()->metadata->raw['publication']['demoted'] ?? null;

        $this->assertIsArray($demoted);
        $this->assertSame('needs_manual_review', $demoted['reason']);
        $this->assertSame($publishedAt, $demoted['previously_published_at']);
        $this->assertTrue($demoted['song_video_quarantined']);
    }

    #[Test]
    public function it_refuses_a_song_video_a_historic_release_moved(): void
    {
        // Releasing moved the asset to the delivery disk. Setting the state back
        // without reversing the move leaves publication_state and asset_disk
        // describing different decisions — P8-Q3's shape, a row saying one thing
        // and the bytes another.
        $section = $this->publishedSong(reviewFlags: [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);
        $video = $this->songVideoFor($section, SermonPublicationState::Published);
        $video->forceFill(['historic_import_operation_id' => $this->operationId()])->save();

        $refusal = $this->reconciler->refusal($section);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('release path', $refusal);
    }

    #[Test]
    public function it_allows_a_quarantined_historic_song_video_because_nothing_moved(): void
    {
        // A quarantined historic video was never public, so demoting its section
        // corrects the row without touching an asset the release path owns.
        $section = $this->publishedSong(reviewFlags: [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);
        $video = $this->songVideoFor($section, SermonPublicationState::Quarantined);
        $video->forceFill(['historic_import_operation_id' => $this->operationId()])->save();

        $this->assertNull($this->reconciler->refusal($section));
        $this->assertFalse($this->reconciler->isPubliclyVisible($section));
    }

    #[Test]
    public function it_refuses_a_published_sermon_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'needs_manual_review' => true,
            'metadata' => ['confidence_level' => 'high', 'review_flags' => [ServiceStructureValidator::FLAG_MACRO_SECTION]],
        ]);

        $this->assertNotNull($this->reconciler->refusal($section));
    }

    /**
     * @param  list<string>  $reviewFlags
     */
    private function publishedSong(array $reviewFlags = [], bool $needsReview = false): ServiceSection
    {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);

        // Built unpublished then forced, so the factory does not attach a
        // `published_sermon_id`: all 501 published sections in the corpus are songs
        // and every one has that column null — it is written at sermon publication.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream(),
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => 'confirmed',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'needs_manual_review' => $needsReview,
            'metadata' => ['confidence_level' => 'high', 'review_flags' => $reviewFlags],
        ]);

        $section->forceFill([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_at' => now(),
            'extracted_video_path' => 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4',
            'extracted_at' => now(),
        ])->save();

        return $section->fresh();
    }

    private function operationId(): int
    {
        return (int) HistoricImportOperation::query()->create([
            'operation_id' => 'op-'.uniqid(),
            'binding_hash' => str_repeat('a', 64),
            'batch_key' => 'reconciler-test',
            'manifest_hashes' => [],
            'plan_hash' => str_repeat('b', 64),
            'target_fingerprint' => str_repeat('c', 64),
        ])->id;
    }

    private function songVideoFor(ServiceSection $section, SermonPublicationState $state): SongVideo
    {
        return SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'song_id' => $section->churchServiceItem?->song_id,
            'publication_state' => $state,
        ]);
    }
}
