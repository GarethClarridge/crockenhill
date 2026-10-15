<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\EnsembleComposition;
use App\Services\ChurchService\Structure\LoopedSegmentChecks;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoopedSegmentChecksTest extends TestCase
{
    private const OPENING = 'The Bible reading is taken from Philippians chapter four, verses ten to twenty.';

    /**
     * 1342 (operator, 2026-10-14): the pre-recorded reading looped, replaying its first ten
     * seconds after it ended, and the song cut it off. The question's one version is the
     * reading without the replay.
     */
    #[Test]
    public function a_loop_at_the_end_of_a_reading_asks_with_the_reading_ending_before_it(): void
    {
        $result = $this->check([
            $this->section(ServiceSectionType::BibleReading, 100, 209),
            $this->section(ServiceSectionType::Song, 209, 400),
        ], [
            [100, 106, self::OPENING],
            [106, 196, 'I rejoiced greatly in the Lord that at last you renewed your concern for me, to our God and Father be glory for ever and ever. Amen.'],
            [198, 208, self::OPENING.' I rejoiced greatly'],
            [215, 240, 'O Lord my rock and my redeemer'],
        ]);

        $this->assertCount(1, $result->disputes);
        $question = $result->disputes[0];
        $this->assertSame(LoopedSegmentChecks::CHECK, $question['check']);
        $this->assertSame(['bible_reading', 100.0, 209.0, true], [$question['type'], $question['start_time'], $question['end_time'], $question['written']]);
        $this->assertSame(198.0, $question['loop']['start_time']);
        $this->assertSame(100.0, $question['loop']['opening_at']);
        $this->assertSame([100.0, 198.0], [$question['alternatives'][0]['section']['start_time'], $question['alternatives'][0]['section']['end_time']]);
        $this->assertSame([0, 1, 2, 3], $question['alternatives'][0]['slots']);
    }

    /** 1363: the replay landed at the start of the talk that followed the reading. */
    #[Test]
    public function a_loop_at_the_start_of_the_next_talk_asks_with_the_talk_starting_after_it(): void
    {
        $result = $this->check([
            $this->section(ServiceSectionType::BibleReading, 63, 199),
            $this->section(ServiceSectionType::ShortTalk, 199, 305),
        ], [
            [63, 70, 'Good morning everyone. Our first reading this morning is taken from 1 Samuel 2.'],
            [70, 196, 'Then Hannah prayed and said my heart rejoices in the Lord. This is the word of the Lord.'],
            [199, 209, 'Good morning everyone. Our first reading this morning is taken from 1 Samuel 2.'],
            [209, 300, 'I don\'t know about you, but there are bits of that reading that sound a bit scary.'],
        ]);

        $this->assertCount(1, $result->disputes);
        $this->assertSame('short_talk', $result->disputes[0]['type']);
        $this->assertSame([209.0, 305.0], [$result->disputes[0]['alternatives'][0]['section']['start_time'], $result->disputes[0]['alternatives'][0]['section']['end_time']]);
    }

    /**
     * Psalms repeat their opening (operator, 2026-10-14), so a repeat the passage carries on
     * well beyond is part of it, not a replay at its edge, and is not asked.
     */
    #[Test]
    public function a_repeat_the_passage_carries_on_beyond_is_not_asked(): void
    {
        $result = $this->check([
            $this->section(ServiceSectionType::BibleReading, 100, 260),
        ], [
            [100, 108, 'O Lord our Lord how majestic is your name in all the earth.'],
            [108, 190, 'You have set your glory in the heavens, when I consider your heavens the work of your fingers.'],
            [200, 208, 'O Lord our Lord how majestic is your name in all the earth.'],
            [210, 258, 'And the reading continues for a long while after the refrain with other words entirely.'],
        ]);

        $this->assertSame([], $result->disputes);
    }

    /** One question per clip: a section the drafts already disagree about is asked by that question. */
    #[Test]
    public function a_section_already_asked_about_is_not_asked_again(): void
    {
        $reading = $this->section(ServiceSectionType::BibleReading, 100, 209);
        $existing = ['question_id' => 'drafts', 'type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 209.0, 'written' => true];

        $result = $this->check([$reading, $this->section(ServiceSectionType::Song, 209, 400)], [
            [100, 106, self::OPENING],
            [106, 196, 'I rejoiced greatly in the Lord that at last you renewed your concern for me.'],
            [198, 208, self::OPENING],
        ], [$existing]);

        $this->assertSame([$existing], $result->disputes);
    }

    /**
     * 1363's greeting replay was answered into a section of its own, ending just before the talk.
     * A replay already outside a section is not asked about it, and never moves its start earlier.
     */
    #[Test]
    public function a_loop_ending_before_the_next_talk_starts_is_not_asked(): void
    {
        $result = $this->check([
            $this->section(ServiceSectionType::BibleReading, 802, 888),
            $this->section(ServiceSectionType::Other, 889, 893),
            $this->section(ServiceSectionType::ShortTalk, 893, 932),
        ], [
            [802, 810, 'Good morning. It is great to be with you even if it is virtually this morning.'],
            [810, 886, 'My soul glorifies the Lord and my spirit rejoices in God my Saviour.'],
            [888, 892, 'Good morning. It is great to be with you even if it is virtually this morning.'],
            [893, 930, 'So God did remember his promise. Jesus is the fulfilment of God\'s promise.'],
        ]);

        $this->assertSame([], $result->disputes);
    }

    /** A replay inside a section no clip publishes on its own (1341) harms nothing and is not asked. */
    #[Test]
    public function a_loop_inside_an_unpublished_section_is_not_asked(): void
    {
        $result = $this->check([
            $this->section(ServiceSectionType::BibleReading, 100, 197),
            $this->section(ServiceSectionType::Other, 197, 240),
        ], [
            [100, 106, self::OPENING],
            [106, 196, 'I rejoiced greatly in the Lord that at last you renewed your concern for me.'],
            [198, 206, self::OPENING],
            [206, 238, 'And we will sing again before we look into the word together.'],
        ]);

        $this->assertSame([], $result->disputes);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: string}>  $cues
     * @param  list<array<string, mixed>>  $disputes
     */
    private function check(array $sections, array $cues, array $disputes = []): EnsembleComposition
    {
        $transcript = new ChurchServiceTranscript(
            array_map(static fn (array $cue): array => ['start' => (float) $cue[0], 'end' => (float) $cue[1], 'text' => $cue[2]], $cues),
            600.0,
            ChurchServiceTranscript::SOURCE_SIDECAR,
        );
        $composition = new EnsembleComposition(ServiceStructure::fromSections($sections), $disputes, [], false, false, 4, false, []);

        return app(LoopedSegmentChecks::class)->apply($composition, $transcript, [0, 1, 2, 3]);
    }

    private function section(ServiceSectionType $type, float $start, float $end): ServiceStructureSection
    {
        return new ServiceStructureSection($type, null, $start, $end, 0.95, null, null, null);
    }
}
