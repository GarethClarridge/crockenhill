<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\AudioDropoutInsideTalk;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\RmsAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The source itself carried no audio for a stretch of a talk (run 1089 §1790).
 *
 * The §4.1a residue census (`residue-20260913-dropouts.py`) found these at RMS ≤ −80 dB for
 * 15 s or more. Nothing downstream can put the words back, so the flag is the outcome: the
 * talk goes to review for an operator to accept or exclude.
 */
class AudioDropoutInsideTalkTest extends TestCase
{
    private AudioDropoutInsideTalk $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AudioDropoutInsideTalk(new RmsAnalysisService);
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
        $song = $this->sectionAfter('song', dropout: [1400, 1440], level: null);

        $this->assertSame([], $song->reviewFlags);
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
        $lines = [];

        for ($tenth = 0; $tenth < $end * 10; $tenth++) {
            $time = $tenth / 10;
            $inDropout = $time >= $dropout[0] && $time < $dropout[1];

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = match (true) {
                $inDropout && $level === null => 'lavfi.astats.Overall.RMS_level=-inf',
                $inDropout => sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level),
                default => 'lavfi.astats.Overall.RMS_level=-25.0',
            };
        }

        return implode("\n", $lines)."\n";
    }
}
