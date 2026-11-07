<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\PublicationPlanValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * S6: the checks an output's final spans must pass after every adjustment, in one place,
 * before extraction may execute them.
 */
class PublicationPlanValidatorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function final_spans_that_reach_every_selected_section_and_no_held_one_pass(): void
    {
        $log = $this->log();
        $reading = $this->section($log, ServiceSectionType::BibleReading, 100, 200);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$reading, $sermon], [
            ['start_time' => 100.0, 'end_time' => 200.0],
            ['start_time' => 221.5, 'end_time' => 3200.0],
        ]);

        $this->assertSame([], $violations);
    }

    /** A merge or an inward edge may move a section's cut, but never lose the section. */
    #[Test]
    public function a_selected_section_no_final_span_reaches_is_a_violation(): void
    {
        $log = $this->log();
        $reading = $this->section($log, ServiceSectionType::BibleReading, 100, 200);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$reading, $sermon], [
            ['start_time' => 220.0, 'end_time' => 3200.0],
        ]);

        $this->assertSame([['kind' => 'selected_section_not_cut', 'section_ids' => [$reading->id]]], $violations);
    }

    #[Test]
    public function a_final_span_reaching_an_unselected_held_section_is_a_violation(): void
    {
        $log = $this->log();
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);
        $held = $this->section($log, ServiceSectionType::ShortTalk, 3200, 3300, [HoldSectionForContentReview::FLAG]);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 220.0, 'end_time' => 3201.5],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$held->id]]], $violations);
    }

    /**
     * 1337 (11-07): a song section that grew past its music takes in the next item's first words (E24 gives
     * speech after a song to the next item), so a neighbour's cut widened to that first word reached the held
     * song's bounds and was refused. The hold is on the song's content: what its hold was found on and what its
     * own output cuts. Speech outside both carries none of it.
     */
    #[Test]
    public function a_cut_reaching_only_the_speech_a_held_song_gives_to_the_next_item_does_not_cross_it(): void
    {
        $log = $this->log();
        $song = $this->heldSong($log, 1478.49, 1773.94, holdFrom: 1659.0, holdTo: 1767.49);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 1813.51, 3800);
        $this->songCutsTo($song, 1769.42);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 1770.33, 'end_time' => 3800.0],
        ]);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function a_cut_reaching_into_a_held_songs_own_cut_still_crosses_it(): void
    {
        $log = $this->log();
        $song = $this->heldSong($log, 1478.49, 1773.94, holdFrom: 1659.0, holdTo: 1767.49);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 1813.51, 3800);
        $this->songCutsTo($song, 1769.42);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 1768.9, 'end_time' => 3800.0],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$song->id]]], $violations);
    }

    /** A hold's recorded span counts even where the song's own cut has since moved away from it. */
    #[Test]
    public function a_cut_reaching_a_held_songs_recorded_hold_span_still_crosses_it(): void
    {
        $log = $this->log();
        $song = $this->heldSong($log, 1478.49, 1773.94, holdFrom: 1659.0, holdTo: 1772.0);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 1813.51, 3800);
        $this->songCutsTo($song, 1769.42);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 1770.33, 'end_time' => 3800.0],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$song->id]]], $violations);
    }

    /** Without its own cut (no evidence to plan it) a held section's content is its whole span, as before. */
    #[Test]
    public function a_held_song_whose_own_cut_cannot_be_planned_is_judged_by_its_bounds(): void
    {
        $log = $this->log();
        $song = $this->heldSong($log, 1478.49, 1773.94, holdFrom: 1659.0, holdTo: 1767.49);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 1813.51, 3800);
        $this->partialMock(CueSafeExtractionPlan::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forSection')->andThrow(new OutputEdgeTimingsMissing('edge_word_timings_missing: test'));
        });

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 1770.33, 'end_time' => 3800.0],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$song->id]]], $violations);
    }

    /** A held sermon's own cut is not planned here: its bounds stand (1347's song into its held sermon). */
    #[Test]
    public function a_held_sermon_is_judged_by_its_bounds(): void
    {
        $log = $this->log();
        $sermon = $this->section($log, ServiceSectionType::Sermon, 1900.36, 3526.32, [HoldSectionForContentReview::FLAG]);
        $song = $this->section($log, ServiceSectionType::Song, 3521.84, 3713.08);
        $this->partialMock(CueSafeExtractionPlan::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forSection');
        });

        $violations = app(PublicationPlanValidator::class)->validate($log, [$song], [
            ['start_time' => 3521.72, 'end_time' => 3713.38],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$sermon->id]]], $violations);
    }

    /** @param  list<array{0: float, 1: float}>  $spans */
    #[Test]
    #[TestWith([[[300.0, 200.0]]], 'reversed')]
    #[TestWith([[[220.0, 3200.0], [3100.0, 3300.0]]], 'overlapping')]
    #[TestWith([[[1000.0, 3200.0], [220.0, 900.0]]], 'unordered')]
    #[TestWith([[[220.0, 5000.5]]], 'past the source')]
    #[TestWith([[]], 'empty')]
    public function impossible_final_spans_are_refused(array $spans): void
    {
        $log = $this->log();
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $this->expectException(InvalidArgumentException::class);

        app(PublicationPlanValidator::class)->validate($log, [$sermon], array_map(
            static fn (array $span): array => ['start_time' => $span[0], 'end_time' => $span[1]],
            $spans,
        ));
    }

    private function heldSong(MediaProcessingLog $log, float $start, float $end, float $holdFrom, float $holdTo): ServiceSection
    {
        $song = $this->section($log, ServiceSectionType::Song, $start, $end, [HoldSectionForContentReview::FLAG]);
        $song->metadata = ServiceSectionMetadata::fromArray([...$song->metadata->toArray(), HoldSectionForContentReview::METADATA_KEY => [
            ['found_by' => 'source_audio', 'reason' => 'The clip is the wrong song.', 'start_time' => $holdFrom, 'end_time' => $holdTo],
        ]]);
        $song->save();

        return $song;
    }

    /** The held song's own output, as its planner would cut it: ending where E24 hands the speech on. */
    private function songCutsTo(ServiceSection $song, float $end): void
    {
        $this->partialMock(CueSafeExtractionPlan::class, function (MockInterface $mock) use ($song, $end): void {
            $mock->shouldReceive('forSection')
                ->withArgs(static fn (ServiceSection $section): bool => $section->id === $song->id)
                ->andReturn(['segments' => [['start_time' => (float) $song->start_time, 'end_time' => $end]], 'cue_edge_widening' => [], 'audio_fade_out' => null]);
        });
    }

    private function log(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
    }

    /** @param  list<string>  $flags */
    private function section(MediaProcessingLog $log, ServiceSectionType $type, float $start, float $end, array $flags = []): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => $flags !== [],
            'metadata' => ['confidence_level' => 'high', 'review_flags' => $flags],
        ]);
    }
}
