<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shapes taken from the 2026-09-16 blind source comparison and the 09-17 corpus sample: a song
 * section that opens on the leader's announcement ("Let's stand and sing…", 20 of 20 sampled
 * lead-ins of 25 s or more) or runs on into the prayer after it (1221 §2719, 1336 §4264).
 */
class SongSpeechEdgesTest extends TestCase
{
    private SongSpeechEdges $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);

        $this->service = new SongSpeechEdges(new RmsAnalysisService);
    }

    #[Test]
    public function it_trims_a_spoken_announcement_off_the_start_of_a_song(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertEqualsWithDelta(330.0, $song->startTime, 5.0);
        $this->assertLessThanOrEqual(340.0, $song->startTime);
        $this->assertSame(600.0, $song->endTime);
        $this->assertStringContainsString('Start trimmed', implode(' ', $song->notes));
        $this->assertSame([], $song->reviewFlags);
    }

    #[Test]
    public function it_trims_speech_off_the_end_of_a_song(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 560, 'sung'], [560, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(300.0, $song->startTime);
        $this->assertEqualsWithDelta(565.0, $song->endTime, 5.0);
        $this->assertGreaterThanOrEqual(560.0, $song->endTime);
        $this->assertStringContainsString('End trimmed', implode(' ', $song->notes));
    }

    /**
     * Under 25 s the onset error (1 to 9 s late on the blind set) is too close to the lead-in
     * itself, and the corpus sample put a clean song (1314 §3988) at exactly 25 s.
     */
    #[Test]
    public function it_leaves_a_short_lead_in_alone(): void
    {
        $rmsLog = $this->rmsLog([[0, 315, 'speech'], [315, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    /**
     * 1267, 1235 and 1309 carry published songs whose singing reads as speech against the run's
     * own threshold; where the other songs do not read as sung, the measure cannot place an edge.
     */
    #[Test]
    public function it_leaves_a_run_alone_when_its_other_songs_do_not_read_as_sung(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    #[Test]
    public function it_leaves_a_song_alone_when_it_is_mostly_speech(): void
    {
        $rmsLog = $this->rmsLog([[0, 480, 'speech'], [480, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(300.0, $song->startTime);
    }

    #[Test]
    public function it_leaves_a_run_with_a_single_song_alone(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('sermon', 600.0, 900.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    #[Test]
    public function it_only_trims_song_sections(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('other', 300.0, 600.0),
            $this->section('song', 610.0, 650.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $other = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[0];

        $this->assertSame(300.0, $other->startTime);
    }

    #[Test]
    public function it_does_nothing_on_a_recording_whose_songs_were_cut_out(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: true);

        $this->assertSame($structure->toArray(), $applied->toArray());
    }

    #[Test]
    public function it_does_nothing_without_an_rms_log(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
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
     * An ffmpeg-astats-style RMS log sampled every 0.1 s, shaped as in
     * {@see SustainedSoundSongSectionsTest}: singing holds -18 dB without a break; speech runs
     * 2.5 s at -25 dB then pauses 0.5 s at -60 dB. A leader at the microphone is loud, so the
     * level alone cannot separate the two; the pauses do.
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
