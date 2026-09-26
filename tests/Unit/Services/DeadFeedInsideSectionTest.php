<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\RmsAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The source itself carried no audio for a stretch of a talk (run 1089 §1790) or a song (music
 * and silence plan §2: 1050 §1584, 1346 §4377, 1117 §1956).
 *
 * The §4.1a residue census (`residue-20260913-dropouts.py`) found these at RMS ≤ −80 dB for
 * 15 s or more. Nothing downstream can put the words back, so the flag is the outcome: the
 * talk goes to review for an operator to accept or exclude.
 */
class DeadFeedInsideSectionTest extends TestCase
{
    private DeadFeedInsideSection $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DeadFeedInsideSection(new RmsAnalysisService);
    }

    #[Test]
    public function it_flags_a_sermon_whose_source_goes_digitally_silent(): void
    {
        $sermon = $this->sectionAfter('sermon', dropout: [1400, 1420], level: null);

        $this->assertContains(ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT, $sermon->reviewFlags);
        $this->assertContains('Source audio drops out 1400–1420 s inside the talk (20 s at or below -80 dB).', $sermon->notes);
    }

    #[Test]
    public function it_flags_a_near_silent_floor_as_well_as_digital_silence(): void
    {
        $sermon = $this->sectionAfter('sermon', dropout: [1400, 1420], level: -85.0);

        $this->assertContains(ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT, $sermon->reviewFlags);
    }

    #[Test]
    public function it_flags_a_short_talk(): void
    {
        $talk = $this->sectionAfter('short_talk', dropout: [1400, 1420], level: null);

        $this->assertContains(ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT, $talk->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_dropout_under_fifteen_seconds_alone(): void
    {
        $sermon = $this->sectionAfter('sermon', dropout: [1400, 1414], level: null);

        $this->assertSame([], $sermon->reviewFlags);
    }

    /** A preacher's long pause sits far above the floor a dead feed drops to. */
    #[Test]
    public function it_leaves_a_quiet_pause_alone(): void
    {
        $sermon = $this->sectionAfter('sermon', dropout: [1400, 1440], level: -70.0);

        $this->assertSame([], $sermon->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_dropout_that_only_grazes_the_talk_alone(): void
    {
        $sermon = $this->sectionAfter('sermon', dropout: [980, 1010], level: null);

        $this->assertSame([], $sermon->reviewFlags, 'Ten seconds of the silence fall inside the sermon, under the fifteen that count.');
    }

    #[Test]
    public function it_leaves_other_section_types_alone(): void
    {
        $prayer = $this->sectionAfter('prayer', dropout: [1400, 1440], level: null);

        $this->assertSame([], $prayer->reviewFlags);
    }

    /**
     * 1050 §1584: a "song" at the end of a recording whose feed went to digital zero at ~868 s.
     */
    #[Test]
    public function it_holds_a_song_lying_wholly_over_a_dead_feed(): void
    {
        $song = $this->apply([$this->section('prayer', 700.0, 880.0), $this->section('song', 884.0, 916.0)], 920, [[868, 920, null]])[1];

        $this->assertContains(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $song->reviewFlags);
        $this->assertStringContainsString('The whole song lies over a dead feed', implode(' ', $song->notes));
        $this->assertSame([884.0, 916.0], [$song->startTime, $song->endTime]);
    }

    /** Under 15 s of the dropout lies inside the song, but only 6 s of live audio remain. */
    #[Test]
    public function it_holds_a_song_left_with_under_fifteen_seconds_of_live_audio(): void
    {
        $song = $this->apply([$this->section('welcome', 0.0, 490.0), $this->section('song', 500.0, 520.0)], 600, [[506, 540, -85.0]])[1];

        $this->assertContains(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $song->reviewFlags);
        $this->assertStringContainsString('The whole song lies over a dead feed: 14 of its 20 s', implode(' ', $song->notes));
    }

    /**
     * 1346 §4377: sung to ~3895, then a fade at or below −80 dB from 3901.4 s and digital zero
     * from 3901.6 s to the end. The song's end moves to where the zeros begin, with no hold.
     */
    #[Test]
    public function it_moves_a_song_end_back_out_of_digital_zero_without_a_hold(): void
    {
        $song = $this->apply(
            [$this->section('sermon', 2000.0, 3600.0), $this->section('song', 3663.8, 3980.0)],
            3980,
            [[3901.4, 3901.6, -85.0], [3901.6, 3980, null]],
        )[1];

        $this->assertEqualsWithDelta(3901.6, $song->endTime, 0.05);
        $this->assertSame(3663.8, $song->startTime);
        $this->assertSame([], $song->reviewFlags);
        $this->assertStringContainsString('End moved from 3980.0s to 3901.6s', implode(' ', $song->notes));
    }

    #[Test]
    public function it_moves_a_song_start_forward_out_of_digital_zero_without_a_hold(): void
    {
        $song = $this->apply([$this->section('song', 500.0, 700.0), $this->section('prayer', 700.0, 900.0)], 900, [[480, 533.7, null]])[0];

        $this->assertEqualsWithDelta(533.7, $song->startTime, 0.05);
        $this->assertSame(700.0, $song->endTime);
        $this->assertSame([], $song->reviewFlags);
    }

    /** Only digital zero is cut: a floor at or below −80 dB that still carries samples is held. */
    #[Test]
    public function it_holds_a_song_end_overrunning_into_a_dropout_that_is_not_digital_zero(): void
    {
        $song = $this->apply([$this->section('sermon', 2000.0, 3600.0), $this->section('song', 3663.8, 3980.0)], 3980, [[3901.4, 3980, -85.0]])[1];

        $this->assertSame([3663.8, 3980.0], [$song->startTime, $song->endTime]);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $song->reviewFlags);
        $this->assertStringContainsString('held rather than cut', implode(' ', $song->notes));
    }

    /**
     * 1117 §1956 "Glory In The Highest": song 131–343 s, dropout 187.5–210.1 s at or below
     * −80 dB, plenty of live song either side. Never split or trimmed.
     */
    #[Test]
    public function it_holds_a_song_with_an_interior_dropout_without_cutting_it(): void
    {
        $song = $this->apply([$this->section('welcome', 0.0, 130.0), $this->section('song', 131.0, 343.0)], 600, [[187.5, 210.1, -85.0]])[1];

        $this->assertSame([131.0, 343.0], [$song->startTime, $song->endTime]);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $song->reviewFlags);
        $this->assertStringContainsString('Source audio drops out 188–210 s inside the song', implode(' ', $song->notes));
    }

    /** An interior dropout of digital zero is still held, never cut: only edges move. */
    #[Test]
    public function it_holds_rather_than_cuts_an_interior_run_of_digital_zero(): void
    {
        $song = $this->apply([$this->section('welcome', 0.0, 130.0), $this->section('song', 131.0, 343.0)], 600, [[187.5, 210.1, null]])[1];

        $this->assertSame([131.0, 343.0], [$song->startTime, $song->endTime]);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $song->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_song_with_a_dropout_under_fifteen_seconds_alone(): void
    {
        $song = $this->apply([$this->section('welcome', 0.0, 130.0), $this->section('song', 131.0, 343.0)], 600, [[187.5, 200.0, null]])[1];

        $this->assertSame([], $song->reviewFlags);
        $this->assertSame([131.0, 343.0], [$song->startTime, $song->endTime]);
    }

    #[Test]
    public function it_reports_every_dropout_as_a_barrier(): void
    {
        $this->assertSame(
            [[187.5, 210.1], [868.0, 919.9]],
            $this->service->dropouts($this->rmsLogWith(920, [[187.5, 210.1, -85.0], [300.0, 310.0, null], [868, 920, null]])),
        );
    }

    /**
     * A 1000–1800 s section of the given type after a song, over speech at −25 dB with one
     * dropout at the given level (null for `-inf`).
     *
     * @param  array{0: int, 1: int}  $dropout
     */
    private function sectionAfter(string $type, array $dropout, ?float $level): ServiceStructureSection
    {
        $applied = $this->service->apply(
            ServiceStructure::fromSections([$this->section('song', 900.0, 990.0), $this->section($type, 1000.0, 1800.0)]),
            $this->rmsLog(1900, $dropout, $level),
        );

        return $applied->sections[1];
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray(['type' => $type, 'start_time' => $start, 'end_time' => $end, 'confidence' => 0.9]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * @param  array{0: int, 1: int}  $dropout
     */
    private function rmsLog(int $end, array $dropout, ?float $level): string
    {
        return $this->rmsLogWith($end, [[$dropout[0], $dropout[1], $level]]);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float|null}>  $dropouts
     * @return list<ServiceStructureSection>
     */
    private function apply(array $sections, int $end, array $dropouts): array
    {
        return $this->service->apply(ServiceStructure::fromSections($sections), $this->rmsLogWith($end, $dropouts))->sections;
    }

    /**
     * Live audio at −25 dB sampled every 0.1 s, with each dropout at its level (null for `-inf`).
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float|null}>  $dropouts
     */
    private function rmsLogWith(int $end, array $dropouts): string
    {
        $lines = [];

        for ($tenth = 0; $tenth < $end * 10; $tenth++) {
            $time = $tenth / 10;
            $line = 'lavfi.astats.Overall.RMS_level=-25.0';

            foreach ($dropouts as [$from, $to, $level]) {
                if ($time >= $from - 1e-6 && $time < $to - 1e-6) {
                    $line = $level === null ? 'lavfi.astats.Overall.RMS_level=-inf' : sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }
}
