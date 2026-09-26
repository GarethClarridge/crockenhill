<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SoundStage;
use App\Services\Media\Audio\AudioTimeline;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * The sound-stage rules composed in their one order: the dead-feed rule runs first, and its
 * dropouts are barriers no later widening or proposal crosses (music and silence plan §6.5).
 */
class SoundStageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);
    }

    /**
     * 1346 §4377: the song's end is cut back out of digital zero, and nothing later carries it
     * back in, though the timeline has no speech there to stop a widening.
     */
    #[Test]
    public function a_song_end_cut_back_from_digital_zero_stays_cut_back(): void
    {
        $sections = $this->apply(
            [$this->section('sermon', 2000.0, 3600.0), $this->section('prayer', 3600.0, 3663.8), $this->section('song', 3663.8, 3980.0)],
            [[0, 3663, 'speech'], [3663, 3901.6, 'sung'], [3901.6, 3980, 'dead']],
            [[0, 3663, 0.05, 0.9], [3663, 3900, 0.9, 0.1]],
            3980.0,
        );

        $song = $this->songAt($sections, 3663.8);
        $this->assertEqualsWithDelta(3901.6, $song->endTime, 0.05);
        $this->assertSame([], $song->reviewFlags);
    }

    /**
     * The classifier hears music on across a stretch the feed went faint over; the widening
     * into the following `other` stops at the dropout the dead-feed rule found.
     */
    #[Test]
    public function a_music_widening_stops_at_a_dropout_the_dead_feed_rule_found(): void
    {
        $sections = $this->apply(
            [$this->section('prayer', 800.0, 975.0), $this->section('song', 977.9, 1089.0), $this->section('other', 1089.0, 1181.0), $this->section('sermon', 1181.0, 2000.0)],
            [[0, 977, 'speech'], [977, 1112, 'sung'], [1112, 1140, 'faint'], [1140, 2000, 'speech']],
            [[975, 1180, 0.9, 0.3]],
            2000.0,
        );

        $song = $this->songAt($sections, 977.9);
        $this->assertSame(1110.0, $song->endTime);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_INTO_MUSIC, $song->reviewFlags);
    }

    #[Test]
    public function a_talk_dropout_is_still_flagged_and_the_talk_left_whole(): void
    {
        $sections = $this->apply(
            [$this->section('song', 900.0, 990.0), $this->section('sermon', 1000.0, 1800.0)],
            [[0, 900, 'speech'], [900, 990, 'sung'], [990, 1400, 'speech'], [1400, 1420, 'dead'], [1420, 1900, 'speech']],
            [[900, 990, 0.9, 0.1], [990, 1900, 0.05, 0.9]],
            1900.0,
        );

        $sermon = $sections[1];
        $this->assertSame(ServiceSectionType::Sermon, $sermon->type);
        $this->assertSame([1000.0, 1800.0], [$sermon->startTime, $sermon->endTime]);
        $this->assertContains(ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT, $sermon->reviewFlags);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     */
    private function songAt(array $sections, float $start): ServiceStructureSection
    {
        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Song && abs($section->startTime - $start) < 0.01) {
                return $section;
            }
        }

        $this->fail("No song starts at {$start}s.");
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: 'speech'|'sung'|'faint'|'dead'}>  $sound
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     * @return list<ServiceStructureSection>
     */
    private function apply(array $sections, array $sound, array $timeline, float $audioSeconds): array
    {
        return app(SoundStage::class)->apply(
            ServiceStructure::fromSections($sections),
            $this->rmsLog($sound, $audioSeconds),
            ChurchServiceTranscript::fromCues([['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning.']], $audioSeconds, ChurchServiceTranscript::SOURCE_MOCK),
            false,
            AudioTimeline::fromJson(AudioTimelineFixture::json($timeline, $audioSeconds)),
        )->sections;
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray(['type' => $type, 'start_time' => $start, 'end_time' => $end, 'confidence' => 0.9]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * Sampled every 0.1 s: singing holds −18 dB; speech runs 2.5 s at −25 dB then pauses 0.5 s at
     * −60 dB; a faint feed sits at −85 dB; a dead one is digital zero.
     *
     * @param  list<array{0: float|int, 1: float|int, 2: 'speech'|'sung'|'faint'|'dead'}>  $sound
     */
    private function rmsLog(array $sound, float $audioSeconds): string
    {
        $lines = [];

        for ($tenth = 0; $tenth < $audioSeconds * 10; $tenth++) {
            $time = $tenth / 10;
            $level = '-60.0';

            foreach ($sound as [$from, $to, $kind]) {
                if ($time >= $from - 1e-6 && $time < $to - 1e-6) {
                    $level = match ($kind) {
                        'sung' => '-18.0',
                        'speech' => fmod($time, 3.0) < 2.5 ? '-25.0' : '-60.0',
                        'faint' => '-85.0',
                        'dead' => '-inf',
                    };
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
        }

        return implode("\n", $lines)."\n";
    }
}
