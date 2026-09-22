<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SustainedSoundSongSections;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\ServiceSectionConfidence;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shapes taken from the §4.1b section coverage census (2026-09-14) and the 09-15 fresh-audio
 * adjudication: singing the transcript cannot see leaves a song section cut to its transcribed
 * lines (965 §889, 1241 §3003), or no section at all (963, 1311's closing song).
 */
class SustainedSoundSongSectionsTest extends TestCase
{
    private SustainedSoundSongSections $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);

        $this->service = new SustainedSoundSongSections(new RmsAnalysisService);
    }

    #[Test]
    public function it_widens_a_song_end_across_singing_the_detector_left_unsectioned(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 440, 'sung'], [440, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section('song', 300.0, 375.0),
            $this->section('bible_reading', 450.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(300.0, $song->startTime);
        $this->assertEqualsWithDelta(440.0, $song->endTime, 10.0);
        $this->assertLessThanOrEqual(450.0, $song->endTime);
        $this->assertStringContainsString('End widened', implode(' ', $song->notes));
        $this->assertNotContains(ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND, $song->reviewFlags);
    }

    #[Test]
    public function it_widens_a_song_start_back_across_singing_the_detector_left_unsectioned(): void
    {
        $rmsLog = $this->rmsLog([[0, 400, 'speech'], [400, 600, 'sung'], [600, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 10.0, 390.0),
            $this->section('song', 520.0, 600.0),
            $this->section('other', 605.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertEqualsWithDelta(400.0, $song->startTime, 10.0);
        $this->assertGreaterThanOrEqual(390.0, $song->startTime);
        $this->assertSame(600.0, $song->endTime);
    }

    /**
     * Every widening under 30 s that the fresh audio heard as speech was 25 s or less
     * (1239, 1273, 1310, 1329): an announcement or a spoken close over a quiet accompaniment.
     */
    #[Test]
    public function it_leaves_a_song_alone_when_the_sound_beside_it_is_shorter_than_the_widening_floor(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 395, 'sung'], [395, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section('song', 300.0, 375.0),
            $this->section('bible_reading', 400.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(375.0, $song->endTime);
    }

    /**
     * 1244 §3037 would widen 265 s and 985 §1121 160 s, and in both the absorbed sound is the
     * next song: a short silence between two songs does not survive the smoothing window.
     */
    #[Test]
    public function it_flags_a_widening_long_enough_to_hold_a_second_song(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 700, 'sung'], [700, 1000, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section('song', 300.0, 400.0),
            $this->section('prayer', 710.0, 1000.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertEqualsWithDelta(700.0, $song->endTime, 10.0);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND, $song->reviewFlags);
    }

    /**
     * 1341 §4310 and 1231 §2851 hold only the spoken announcement ("Let's sing … Amazing
     * Grace"); sustained sound starts two bins (10 s) past the section, after an introduction
     * that is neither speech nor song. The corpus census found these two and one more at 20 s
     * (1287, a displaced song identity, not an introduction).
     */
    #[Test]
    public function it_widens_an_announced_song_across_a_short_introduction_and_holds_it_for_review(): void
    {
        $rmsLog = $this->rmsLog([[0, 316, 'speech'], [324, 600, 'sung'], [610, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 298.0),
            $this->section('song', 300.0, 326.0),
            $this->section('prayer', 610.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(300.0, $song->startTime);
        $this->assertEqualsWithDelta(600.0, $song->endTime, 10.0);
        $this->assertStringContainsString('introduction', implode(' ', $song->notes));
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND, $song->reviewFlags);
    }

    #[Test]
    public function it_holds_even_a_short_widening_that_crossed_an_introduction(): void
    {
        $rmsLog = $this->rmsLog([[0, 316, 'speech'], [324, 380, 'sung'], [390, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 298.0),
            $this->section('song', 300.0, 326.0),
            $this->section('prayer', 390.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertGreaterThan(326.0, $song->endTime);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND, $song->reviewFlags);
    }

    #[Test]
    public function it_widens_a_song_start_back_across_an_introduction(): void
    {
        $rmsLog = $this->rmsLog([[0, 290, 'speech'], [300, 576, 'sung'], [584, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 290.0),
            $this->section('song', 574.0, 600.0),
            $this->section('sermon', 602.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertEqualsWithDelta(300.0, $song->startTime, 10.0);
        $this->assertGreaterThanOrEqual(290.0, $song->startTime);
        $this->assertSame(600.0, $song->endTime);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND, $song->reviewFlags);
    }

    #[Test]
    public function it_does_not_bridge_a_gap_longer_than_an_introduction(): void
    {
        $rmsLog = $this->rmsLog([[0, 316, 'speech'], [345, 600, 'sung'], [610, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 298.0),
            $this->section('song', 300.0, 326.0),
            $this->section('prayer', 610.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertSame(326.0, $sections[1]->endTime);
    }

    #[Test]
    public function it_does_not_bridge_an_introduction_that_another_section_holds(): void
    {
        $rmsLog = $this->rmsLog([[0, 316, 'speech'], [324, 600, 'sung'], [610, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 298.0),
            $this->section('song', 300.0, 326.0),
            $this->section('notices', 326.0, 332.0),
            $this->section('prayer', 610.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertSame(326.0, $sections[1]->endTime);
    }

    /**
     * 1215 §2638/§2639 and 1337 §4274/§4275: the sound between two songs belongs to one of
     * them, and the level cannot say which.
     */
    #[Test]
    public function it_widens_neither_song_when_the_sound_runs_from_one_song_into_the_next(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 700, 'sung'], [700, 1000, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section('song', 300.0, 360.0),
            $this->section('song', 640.0, 700.0),
            $this->section('prayer', 710.0, 1000.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(4, $sections);
        $this->assertSame(360.0, $sections[1]->endTime);
        $this->assertSame(640.0, $sections[2]->startTime);
    }

    /**
     * Sound running on into a prayer or reading was heard as speech in 21 of 38 cases: a
     * leader at a microphone is as loud and unbroken as singing. That edge is not moved.
     */
    #[Test]
    public function it_does_not_widen_a_song_across_a_neighbouring_section(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 500, 'sung'], [500, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section('song', 300.0, 360.0),
            $this->section('prayer', 365.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(3, $sections);
        $this->assertSame(360.0, $sections[1]->endTime);
    }

    #[Test]
    public function it_proposes_a_held_song_section_for_singing_with_no_section(): void
    {
        $rmsLog = $this->rmsLog([[0, 200, 'speech'], [200, 400, 'sung'], [400, 700, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 100.0, 190.0),
            $this->section('bible_reading', 405.0, 700.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(3, $sections);
        $proposed = $sections[1];
        $this->assertSame(ServiceSectionType::Song, $proposed->type);
        $this->assertSame('Unidentified singing', $proposed->title);
        $this->assertEqualsWithDelta(200.0, $proposed->startTime, 10.0);
        $this->assertEqualsWithDelta(400.0, $proposed->endTime, 10.0);
        $this->assertGreaterThanOrEqual(190.0, $proposed->startTime);
        $this->assertLessThanOrEqual(405.0, $proposed->endTime);
        $this->assertNull($proposed->oosItemId);
        $this->assertNull($proposed->songTitle);
        $this->assertLessThan(ServiceSectionConfidence::HIGH_THRESHOLD, $proposed->confidence);
        $this->assertContains(ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING, $proposed->reviewFlags);
    }

    /**
     * 1001, 1135, 1231 and 1311 each close on a song the detector never sectioned.
     */
    #[Test]
    public function it_proposes_a_closing_song_after_the_last_section(): void
    {
        $rmsLog = $this->rmsLog([[0, 900, 'speech'], [905, 1200, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 100.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(2, $sections);
        $this->assertSame('Unidentified singing', $sections[1]->title);
        $this->assertEqualsWithDelta(905.0, $sections[1]->startTime, 10.0);
        $this->assertEqualsWithDelta(1200.0, $sections[1]->endTime, 10.0);
    }

    /**
     * Music before the service is as loud as an opening song (1253 "*music*", 1036, 1109), and
     * nothing before the first section can tell them apart.
     */
    #[Test]
    public function it_does_not_propose_a_song_before_the_first_section(): void
    {
        $rmsLog = $this->rmsLog([[0, 200, 'sung'], [200, 600, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 205.0, 600.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(1, $sections);
    }

    #[Test]
    public function it_does_not_propose_a_song_for_singing_shorter_than_the_proposal_floor(): void
    {
        $rmsLog = $this->rmsLog([[0, 195, 'speech'], [195, 230, 'sung'], [230, 600, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 100.0, 190.0),
            $this->section('bible_reading', 235.0, 600.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(2, $sections);
    }

    /**
     * Measured 2026-09-15: sermons, prayers, readings and notices break below the threshold at
     * least 8.3 times a minute at their 10th percentile; songs at most 6.8 at their 90th.
     */
    #[Test]
    public function speech_with_pauses_is_never_treated_as_singing(): void
    {
        $rmsLog = $this->rmsLog([[0, 1000, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 100.0, 190.0),
            $this->section('bible_reading', 500.0, 700.0),
            $this->section('song', 700.0, 760.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertCount(3, $sections);
        $this->assertSame(760.0, $sections[2]->endTime);
    }

    #[Test]
    public function a_recording_without_songs_is_left_untouched(): void
    {
        $rmsLog = $this->rmsLog([[0, 200, 'speech'], [200, 400, 'sung'], [400, 700, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 100.0, 190.0),
            $this->section('bible_reading', 405.0, 700.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: true);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    #[Test]
    public function an_empty_rms_log_leaves_the_structure_untouched(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 100.0, 190.0),
            $this->section('song', 300.0, 375.0),
        ]);

        $applied = $this->service->apply($structure, '', recordingOmitsSongs: false);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.9,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * An ffmpeg-astats-style RMS log sampled every 0.1 s. Singing holds -18 dB without a break;
     * speech runs 2.5 s at -25 dB then pauses 0.5 s at -60 dB (20 pauses a minute); anything
     * not listed is silence.
     *
     * @param  list<array{0: int, 1: int, 2: 'sung'|'speech'}>  $spans
     */
    private function rmsLog(array $spans): string
    {
        $end = max(array_map(static fn (array $span): int => $span[1], $spans));
        $lines = [];

        for ($tenth = 0; $tenth < $end * 10; $tenth++) {
            $time = $tenth / 10;
            $level = -60.0;

            foreach ($spans as [$from, $to, $kind]) {
                if ($time >= $from && $time < $to) {
                    $level = $kind === 'sung' ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        return implode("\n", $lines)."\n";
    }
}
