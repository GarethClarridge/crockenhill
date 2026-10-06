<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Sermon\SermonExtractionPlanResolver;
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

        $this->service = new SongSpeechEdges(new RmsAnalysisService, app(ScriptureReferenceResolver::class));
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
     * Canary 11 listening (operator, 2026-10-06): speech a trim leaves between the sermon and the
     * song after it held the end of the closing prayer or its "Amen" in 4 of 6 services, and the
     * rest was the hymn announcement, which may go either way. Leaving it unowned silently drops
     * the sermon's last words, so the sermon takes it, up to where the singing starts, unasked.
     */
    #[Test]
    public function speech_trimmed_off_a_song_after_the_sermon_joins_the_sermon(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        [$sermon, $song] = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertGreaterThan(300.0, $song->startTime);
        $this->assertSame([0.0, $song->startTime], [$sermon->startTime, $sermon->endTime]);
        $this->assertSame([], $sermon->reviewFlags);
        $this->assertStringContainsString(sprintf('End extended to %.1fs', $song->startTime), implode(' ', $sermon->notes));
        $this->assertSame([], array_filter($sermon->notes, SongSpeechEdges::isExposedSpeechNote(...)));
    }

    #[Test]
    public function speech_trimmed_off_a_song_before_the_sermon_raises_an_ownership_question_on_the_sermon(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 560, 'sung'], [560, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('song', 300.0, 600.0),
            $this->section('sermon', 600.0, 700.0),
            $this->section('song', 700.0, 900.0),
        ]);

        [$song, $sermon] = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertLessThan(600.0, $song->endTime);
        $this->assertSame([ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED], $sermon->reviewFlags);
        $this->assertStringContainsString(sprintf('%.1f–600.0s', $song->endTime), implode(' ', $sermon->notes));
    }

    /**
     * I2 (1219's shape): the sermon output also holds its reading, so speech a trim leaves beside
     * that reading is the reading's own words or an excluded announcement, asked like the sermon's.
     */
    #[Test]
    public function speech_trimmed_off_a_song_after_the_sermon_reading_raises_an_ownership_question_on_the_sermon(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 0.0, 300.0, readingReference: 'John 3:1-21'),
            $this->section('song', 300.0, 600.0),
            $this->section('sermon', 600.0, 700.0, sermonReference: 'John 3:16'),
            $this->section('song', 700.0, 900.0),
        ]);

        [$reading, $song, $sermon] = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertGreaterThan(300.0, $song->startTime);
        $this->assertSame([0.0, 300.0], [$reading->startTime, $reading->endTime]);
        $this->assertSame([], $reading->reviewFlags);
        $this->assertSame([ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED], $sermon->reviewFlags);
        $this->assertContains(SongSpeechEdges::exposedSpeechNote(300.0, $song->startTime, ServiceSectionType::BibleReading), $sermon->notes);
    }

    /**
     * The concluding prayer is cut with the sermon too ({@see SermonExtractionPlanResolver::compose()}),
     * and its "Amen" is what the canary 11 services lost.
     */
    #[Test]
    public function speech_trimmed_off_a_song_after_the_concluding_prayer_joins_the_prayer(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 250.0),
            $this->section('prayer', 250.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        [$sermon, $prayer, $song] = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertGreaterThan(300.0, $song->startTime);
        $this->assertSame([250.0, $song->startTime], [$prayer->startTime, $prayer->endTime]);
        $this->assertSame([], $prayer->reviewFlags);
        $this->assertSame([], $sermon->reviewFlags);
    }

    /**
     * Beside a reading the same stretch was the announcement every time (6 of 6 in canary 11),
     * and a song after the sermon whose own start is the sermon's opening was never ruled on:
     * both still ask.
     */
    #[Test]
    public function speech_trimmed_off_a_song_after_a_section_outside_the_sermon_itself_still_asks(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 0.0, 300.0, readingReference: 'John 3:1-21'),
            $this->section('song', 300.0, 600.0),
            $this->section('sermon', 600.0, 700.0, sermonReference: 'John 3:16'),
            $this->section('song', 700.0, 900.0),
        ]);

        [$reading, $song] = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertSame([0.0, 300.0], [$reading->startTime, $reading->endTime]);
        $this->assertGreaterThan(300.0, $song->startTime);
    }

    /** A reading the sermon output cannot take — another reading holds its passage — is not its business. */
    #[Test]
    public function speech_trimmed_beside_a_reading_outside_the_sermon_output_asks_no_ownership_question(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 0.0, 300.0, readingReference: 'Psalm 23'),
            $this->section('song', 300.0, 600.0),
            $this->section('bible_reading', 600.0, 650.0, readingReference: 'John 3:1-21'),
            $this->section('sermon', 650.0, 700.0, sermonReference: 'John 3:16'),
            $this->section('song', 700.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertGreaterThan(300.0, $sections[1]->startTime);
        $this->assertSame([], $sections[3]->reviewFlags);
    }

    #[Test]
    public function speech_trimmed_next_to_another_item_asks_no_ownership_question(): void
    {
        $rmsLog = $this->rmsLog([[0, 340, 'speech'], [340, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 250.0),
            $this->section('notices', 250.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $sections = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections;

        $this->assertGreaterThan(300.0, $sections[2]->startTime);
        $this->assertSame([], $sections[0]->reviewFlags);
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

    /**
     * 974 §988's shape: the song section swallowed a prayer, 93 s of speech before the singing,
     * and is under half sustained. Trimming one end would guess where a separate item starts, so
     * it is held for review with the span left as detected.
     */
    #[Test]
    public function it_holds_a_mostly_spoken_song_with_a_long_spoken_lead_in_instead_of_trimming_it(): void
    {
        $rmsLog = $this->rmsLog([[0, 480, 'speech'], [480, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame(300.0, $song->startTime);
        $this->assertSame(600.0, $song->endTime);
        $this->assertSame([ServiceStructureValidator::FLAG_SONG_SWALLOWS_SPEECH], $song->reviewFlags);
        $this->assertStringContainsString('spoken', implode(' ', $song->notes));
    }

    /** 1475's other half: a 41 s spoken tail on a mostly spoken song. */
    #[Test]
    public function it_holds_a_mostly_spoken_song_with_a_long_spoken_tail(): void
    {
        $rmsLog = $this->rmsLog([[0, 300, 'speech'], [300, 420, 'sung'], [420, 700, 'speech'], [700, 900, 'sung']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $song = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false)->sections[1];

        $this->assertSame([ServiceStructureValidator::FLAG_SONG_SWALLOWS_SPEECH], $song->reviewFlags);
    }

    /** The run's measure must work: where no song reads as sung, a spoken-sounding song is not evidence. */
    #[Test]
    public function it_does_not_hold_a_mostly_spoken_song_on_a_run_whose_songs_do_not_read_as_sung(): void
    {
        $rmsLog = $this->rmsLog([[0, 480, 'speech'], [480, 600, 'sung'], [600, 900, 'speech']]);
        $structure = ServiceStructure::fromSections([
            $this->section('prayer', 0.0, 300.0),
            $this->section('song', 300.0, 600.0),
            $this->section('song', 700.0, 900.0),
        ]);

        $applied = $this->service->apply($structure, $rmsLog, recordingOmitsSongs: false);

        $this->assertSame($structure->toArray(), $applied->toArray());
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

    private function section(string $type, float $start, float $end, ?string $readingReference = null, ?string $sermonReference = null): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.9,
            'reading_reference' => $readingReference,
            'sermon_reference' => $sermonReference,
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
