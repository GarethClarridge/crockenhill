<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\MergeInterruptedTalk;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeInterruptedTalkTest extends TestCase
{
    use RefreshDatabase;

    private MergeInterruptedTalk $action;

    private User $admin;

    private MediaProcessingLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(MergeInterruptedTalk::class);
        $this->admin = User::factory()->admin()->create();
        $this->log = MediaProcessingLog::factory()->livestream()->create();
    }

    /**
     * Run 1356: the Bach talk reads Psalm 150 and then finishes. One step makes it one talk
     * again, keeping the talk that began, with the reading and the ending inside it.
     */
    #[Test]
    public function it_makes_a_talk_resumed_after_a_reading_one_talk(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 204.0, 449.0, 'Hero of Faith: Johann Sebastian Bach', [ServiceStructureValidator::FLAG_TALK_INTERRUPTED], [1, 2]);
        $reading = $this->section(2, ServiceSectionType::BibleReading, 449.0, 490.0, 'Psalm 150', [], [3]);
        $ending = $this->section(3, ServiceSectionType::ShortTalk, 490.0, 522.0, 'Using God-given gifts', [ServiceStructureValidator::FLAG_TALK_INTERRUPTED], [4]);
        $song = $this->section(4, ServiceSectionType::Song, 522.0, 700.0, 'Praise My Soul');

        $this->assertNull($this->action->execute($talk, $this->admin->id));

        $talk->refresh();
        $this->assertSame(204.0, (float) $talk->start_time);
        $this->assertSame(522.0, (float) $talk->end_time);
        $this->assertSame(318.0, (float) $talk->duration);
        $this->assertSame('Hero of Faith: Johann Sebastian Bach', $talk->title);
        $this->assertSame([1, 2, 3, 4], $talk->source_segment_ids);

        $metadata = $talk->metadata?->toArray() ?? [];
        $this->assertNotContains(ServiceStructureValidator::FLAG_TALK_INTERRUPTED, $metadata['review_flags'] ?? []);
        $this->assertTrue($metadata['manually_merged']);
        $this->assertSame($this->admin->id, $metadata['manually_merged_by_user_id']);
        $this->assertEquals(
            [
                ['section_id' => $reading->id, 'type' => 'bible_reading', 'title' => 'Psalm 150', 'start_time' => 449.0, 'end_time' => 490.0],
                ['section_id' => $ending->id, 'type' => 'short_talk', 'title' => 'Using God-given gifts', 'start_time' => 490.0, 'end_time' => 522.0],
            ],
            $metadata['absorbed_talk_interruption'],
        );

        $this->assertDatabaseMissing('service_sections', ['id' => $reading->id]);
        $this->assertDatabaseMissing('service_sections', ['id' => $ending->id]);
        $this->assertDatabaseHas('service_sections', ['id' => $song->id]);
    }

    #[Test]
    public function it_absorbs_every_reading_and_prayer_between_the_two_talks(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $reading = $this->section(2, ServiceSectionType::BibleReading, 400.0, 480.0);
        $prayer = $this->section(3, ServiceSectionType::Prayer, 480.0, 600.0);
        $ending = $this->section(4, ServiceSectionType::ShortTalk, 600.0, 700.0);

        $this->assertNull($this->action->execute($talk, $this->admin->id));

        $this->assertSame(700.0, (float) $talk->refresh()->end_time);
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $this->log->id)->count());
        $this->assertDatabaseMissing('service_sections', ['id' => $reading->id]);
        $this->assertDatabaseMissing('service_sections', ['id' => $prayer->id]);
        $this->assertDatabaseMissing('service_sections', ['id' => $ending->id]);
    }

    /**
     * The merged talk takes the ending's span, so it takes the ending's hold, as the
     * same-type merge does.
     */
    #[Test]
    public function it_carries_the_endings_content_hold_onto_the_talk(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $this->section(2, ServiceSectionType::BibleReading, 400.0, 480.0);
        $ending = $this->section(3, ServiceSectionType::ShortTalk, 480.0, 540.0);

        app(HoldSectionForContentReview::class)($ending, 'Speech invented over silence', 'plan §4.0', ContentHoldCheck::SourceAudio);

        $this->assertNull($this->action->execute($talk, $this->admin->id));

        $talk->refresh();
        $this->assertTrue(HoldSectionForContentReview::isHeld($talk->metadata?->toArray()['review_flags'] ?? []));
        $this->assertTrue($talk->needs_manual_review);
    }

    #[Test]
    public function it_resets_the_talks_extracted_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('talks/talk.mp4', 'video');
        Storage::disk('public')->put('talks/talk.mp3', 'audio');

        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $talk->update([
            'extracted_video_path' => 'talks/talk.mp4',
            'extracted_audio_path' => 'talks/talk.mp3',
            'extracted_at' => now(),
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);
        $this->section(2, ServiceSectionType::BibleReading, 400.0, 480.0);
        $this->section(3, ServiceSectionType::ShortTalk, 480.0, 540.0);

        $this->assertNull($this->action->execute($talk, $this->admin->id));

        $talk->refresh();
        $this->assertNull($talk->extracted_video_path);
        $this->assertNull($talk->extracted_audio_path);
        $this->assertNull($talk->extracted_at);
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $talk->publication_status);
    }

    /**
     * @return iterable<string, array{list<ServiceSectionType>}>
     */
    public static function shapesThatAreNotAnInterruptedTalk(): iterable
    {
        yield 'a song follows the talk' => [[ServiceSectionType::Song, ServiceSectionType::ShortTalk]];
        yield 'a song follows the reading' => [[ServiceSectionType::BibleReading, ServiceSectionType::Song, ServiceSectionType::ShortTalk]];
        yield 'the reading leads into the sermon' => [[ServiceSectionType::BibleReading, ServiceSectionType::Sermon]];
        yield 'the next talk follows directly' => [[ServiceSectionType::ShortTalk]];
        yield 'nothing follows the reading' => [[ServiceSectionType::BibleReading]];
    }

    /**
     * @param  list<ServiceSectionType>  $following
     */
    #[Test]
    #[DataProvider('shapesThatAreNotAnInterruptedTalk')]
    public function it_refuses_a_talk_that_is_not_followed_by_readings_or_prayers_then_a_talk(array $following): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $cursor = 400.0;

        foreach ($following as $index => $type) {
            $this->section($index + 2, $type, $cursor, $cursor + 100.0);
            $cursor += 100.0;
        }

        $this->assertNotNull($this->action->execute($talk, $this->admin->id));
        $this->assertSame(400.0, (float) $talk->refresh()->end_time);
        $this->assertSame(count($following) + 1, ServiceSection::query()->where('media_processing_log_id', $this->log->id)->count());
    }

    #[Test]
    public function it_refuses_a_section_that_is_not_a_talk(): void
    {
        $reading = $this->section(1, ServiceSectionType::BibleReading, 0.0, 100.0);
        $this->section(2, ServiceSectionType::Prayer, 100.0, 200.0);
        $this->section(3, ServiceSectionType::ShortTalk, 200.0, 300.0);

        $this->assertSame('Only a talk can absorb the readings and prayers that interrupted it.', $this->action->execute($reading, $this->admin->id));
    }

    #[Test]
    public function it_refuses_when_any_section_it_would_absorb_is_published(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $this->section(2, ServiceSectionType::BibleReading, 400.0, 480.0);
        $ending = ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id,
            'section_type' => ServiceSectionType::ShortTalk,
            'section_order' => 3,
            'start_time' => 480.0,
            'end_time' => 540.0,
            'published_at' => now(),
        ]);

        $this->assertSame('Published sections cannot be merged.', $this->action->execute($talk, $this->admin->id));
        $this->assertDatabaseHas('service_sections', ['id' => $ending->id]);
    }

    #[Test]
    public function it_names_the_talk_ending_it_would_absorb_for_each_interrupted_talk(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $this->section(2, ServiceSectionType::BibleReading, 400.0, 480.0);
        $ending = $this->section(3, ServiceSectionType::ShortTalk, 480.0, 540.0);
        $this->section(4, ServiceSectionType::Song, 540.0, 700.0);
        $this->section(5, ServiceSectionType::ShortTalk, 700.0, 900.0);

        $sections = ServiceSection::query()->where('media_processing_log_id', $this->log->id)->orderBy('section_order')->get();

        $this->assertSame([$talk->id => $ending->id], MergeInterruptedTalk::candidates($sections));
    }

    /**
     * @param  list<string>  $reviewFlags
     * @param  list<int>  $sourceSegmentIds
     */
    private function section(
        int $order,
        ServiceSectionType $type,
        float $start,
        float $end,
        ?string $title = null,
        array $reviewFlags = [],
        array $sourceSegmentIds = [],
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id,
            'section_type' => $type,
            'section_order' => $order,
            'title' => $title ?? $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'source_segment_ids' => $sourceSegmentIds,
            'needs_manual_review' => $reviewFlags !== [],
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'metadata' => ['review_flags' => $reviewFlags],
        ]);
    }
}
