<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\ExtendSongsOverOwnLyrics;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SongMusicalIntroduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Register D6 as amended 10-24: a song's musical introduction is always in the song when it is
 * detected; a spoken one is optional. Heard over 36 clips that opened on their words.
 */
class SongMusicalIntroductionTest extends TestCase
{
    use RefreshDatabase;

    private MediaProcessingLog $run;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $this->run = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
    }

    /**
     * 954 §796: the leader stops at 4:15 and the band plays in; the clip began at 4:33 on the first
     * words. Heard 10-24: "Should be about 4:18".
     */
    #[Test]
    public function it_starts_a_song_at_the_music_that_introduces_it(): void
    {
        $section = $this->song(273.0, 480.0);
        $this->timeline([[0, 255, 0.02, 0.85], [255, 480, 0.7, 0.03]]);

        $this->assertSame(1, $this->introduction()->extend($this->run));

        $this->assertEqualsWithDelta(255.0, (float) $section->fresh()->start_time, 0.01);
        $this->assertEqualsWithDelta(480.0 - 255.0, (float) $section->fresh()->duration, 0.01);
        $this->assertEquals([['from' => 273.0, 'to' => 255.0, 'windows' => 4]], array_map(
            static fn (array $record): array => array_diff_key($record, ['extended_at' => true]),
            $section->fresh()->metadata->raw[SongMusicalIntroduction::METADATA_KEY],
        ));
    }

    /**
     * One window of music before the start is the song's own opening overlapping the edge: six of
     * the eight heard with one window had nothing worth keeping.
     */
    #[Test]
    public function it_leaves_a_song_with_a_single_window_of_music_before_it(): void
    {
        $section = $this->song(1022.0, 1200.0);
        $this->timeline([[0, 1020, 0.02, 0.85], [1020, 1200, 0.7, 0.03]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(1022.0, (float) $section->fresh()->start_time, 0.01);
    }

    /** Music under the leader's voice is not an introduction: the walk stops at speech. */
    #[Test]
    public function it_stops_at_speech_over_music(): void
    {
        $section = $this->song(273.0, 480.0);
        $this->timeline([[0, 260, 0.6, 0.8], [260, 480, 0.7, 0.03]]);

        $this->introduction()->extend($this->run);

        $this->assertEqualsWithDelta(260.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1267: three hymns back to back. Music before a song inside the song before it is that song,
     * so the walk stops at its end.
     */
    #[Test]
    public function it_stops_at_the_end_of_the_song_before(): void
    {
        $previous = $this->song(958.0, 1150.0);
        $section = $this->song(1162.0, 1350.0);
        $this->timeline([[0, 958, 0.02, 0.85], [958, 1350, 0.7, 0.03]]);

        $this->introduction()->extend($this->run);

        $this->assertEqualsWithDelta(1150.0, (float) $section->fresh()->start_time, 0.01);
        $this->assertEqualsWithDelta(1150.0, (float) $previous->fresh()->end_time, 0.01, 'The song before keeps its span.');
    }

    /**
     * 1233 §2867: music before the service is wholly instrumental and not the song's (D6), so the
     * walk stops at a section of its own.
     */
    #[Test]
    public function it_stops_at_pre_service_music(): void
    {
        $this->section(ServiceSectionType::Other, 24.7, 91.0);
        $section = $this->song(91.0, 139.0);
        $this->timeline([[0, 22, 0.02, 0.85], [22, 139, 0.7, 0.03]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(91.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1154 §247599: the sermon ran on to the song, and the band played in under its last
     * seconds. Heard 10-24: "Should be about 62:31". The talk's musical tail is the introduction.
     */
    #[Test]
    public function it_starts_a_song_inside_the_musical_tail_of_the_sermon_before_it(): void
    {
        $this->section(ServiceSectionType::Sermon, 1888.0, 3760.0);
        $section = $this->song(3760.0, 3990.0);
        $this->timeline([[0, 3750, 0.02, 0.85], [3750, 3990, 0.7, 0.03]]);

        $this->introduction()->extend($this->run);

        $this->assertEqualsWithDelta(3750.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1011 §1285 (E25, 11-01): after "Let's stand and sing" the feed drops out (−95 dB) until a solo
     * voice comes in at 458.81, far below the band (−55 to −70 dB against −24 dB) and reading music
     * 0.10–0.33, but singing 0.049–0.070 with no speech. The congregation and band join at 520. A
     * structure `other` section, "Hymn introduction", holds the solo's first 18 s. Heard: the clip
     * at 519.73 starts partway through the song; it starts at the solo.
     */
    #[Test]
    public function it_starts_a_song_at_the_quiet_solo_that_opens_it(): void
    {
        $this->section(ServiceSectionType::BibleReading, 279.0, 419.0);
        $this->section(ServiceSectionType::Other, 419.0, 476.99);
        $section = $this->song(513.85, 637.42);
        $this->quietSoloTimeline();
        $this->levels([[0.0, 438.0, -40.0], [438.0, 458.81, -95.0], [458.81, 520.0, -62.0], [520.0, 640.0, -24.0]]);

        $this->assertSame(1, $this->introduction()->extend($this->run));

        $this->assertEqualsWithDelta(458.51, (float) $section->fresh()->start_time, 0.01);
        $this->assertEquals([['from' => 513.85, 'to' => 458.51, 'windows' => 12, 'quiet_singing_windows' => 12, 'quieter_by_db' => 38.0]], array_map(
            static fn (array $record): array => array_diff_key($record, ['extended_at' => true]),
            $section->fresh()->metadata->raw[SongMusicalIntroduction::METADATA_KEY],
        ));
        $this->assertTrue(SongMusicalIntroduction::opensOnQuietRecording($section->fresh()), 'E25: the clip is flagged.');
    }

    /** A quiet opening a little below the song is moved but not flagged: the flag is for one far quieter. */
    #[Test]
    public function it_does_not_flag_an_opening_only_a_little_quieter_than_the_song(): void
    {
        $section = $this->song(513.85, 637.42);
        $this->quietSoloTimeline();
        $this->levels([[0.0, 438.0, -40.0], [438.0, 458.81, -95.0], [458.81, 520.0, -34.0], [520.0, 640.0, -24.0]]);

        $this->introduction()->extend($this->run);

        $this->assertEqualsWithDelta(458.51, (float) $section->fresh()->start_time, 0.01);
        $this->assertEqualsWithDelta(10.0, $section->fresh()->metadata->raw[SongMusicalIntroduction::METADATA_KEY][0]['quieter_by_db'], 0.01);
        $this->assertFalse(SongMusicalIntroduction::opensOnQuietRecording($section->fresh()));
    }

    /** A start the music moved is no quiet recording. */
    #[Test]
    public function it_does_not_flag_a_musical_introduction(): void
    {
        $section = $this->song(273.0, 480.0);
        $this->timeline([[0, 255, 0.02, 0.85], [255, 480, 0.7, 0.03]]);

        $this->introduction()->extend($this->run);

        $this->assertFalse(SongMusicalIntroduction::opensOnQuietRecording($section->fresh()));
    }

    /** Without a level log the start is the first sung window's: nothing heard is left out. */
    #[Test]
    public function it_starts_a_quiet_solo_at_its_window_without_a_level_log(): void
    {
        $this->section(ServiceSectionType::Other, 419.0, 476.99);
        $section = $this->song(513.85, 637.42);
        $this->quietSoloTimeline();

        $this->introduction()->extend($this->run);

        $this->assertEqualsWithDelta(455.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * A voice over the quiet singing is a leader speaking, not the song (the readings heard 11-01
     * read speech 0.72–0.84).
     */
    #[Test]
    public function it_does_not_take_quiet_singing_under_a_voice(): void
    {
        $section = $this->song(513.85, 637.42);
        $this->timeline([[0, 455, 0.02, 0.75, 0.001], [455, 520, 0.18, 0.3, 0.05], [520, 640, 0.7, 0.02, 0.2]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(513.85, (float) $section->fresh()->start_time, 0.01);
    }

    /** Below the quietest singing heard (0.037) a window is not taken as sung: 1294's 0.015–0.030 is unheard. */
    #[Test]
    public function it_does_not_take_singing_below_the_quietest_heard(): void
    {
        $section = $this->song(230.0, 330.0);
        $this->timeline([[0, 205, 0.02, 0.75, 0.001], [205, 225, 0.3, 0.06, 0.026], [225, 330, 0.7, 0.02, 0.2]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(230.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * Loud singing inside an `other` section is an item of its own (a choir piece), as music is
     * (1233): only singing too quiet to read as music crosses into it.
     */
    #[Test]
    public function it_stops_at_loud_singing_inside_an_other_section(): void
    {
        $this->section(ServiceSectionType::Other, 400.0, 480.0);
        $section = $this->song(480.0, 640.0);
        $this->timeline([[0, 400, 0.02, 0.75, 0.001], [400, 640, 0.7, 0.02, 0.2]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(480.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function it_leaves_a_published_section_alone(): void
    {
        $section = $this->song(273.0, 480.0);
        $section->forceFill([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_at' => now(),
            'extracted_video_path' => 'sections/published.mp4',
            'extracted_audio_path' => 'sections/published.mp3',
            'extracted_at' => now(),
        ])->save();
        $this->timeline([[0, 255, 0.02, 0.85], [255, 480, 0.7, 0.03]]);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(273.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function it_changes_nothing_when_run_twice(): void
    {
        $section = $this->song(273.0, 480.0);
        $this->timeline([[0, 255, 0.02, 0.85], [255, 480, 0.7, 0.03]]);

        $this->introduction()->extend($this->run);

        $this->assertSame(0, $this->introduction()->extend($this->run->fresh()));
        $this->assertEqualsWithDelta(255.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function it_leaves_a_run_without_an_audio_timeline_alone(): void
    {
        $section = $this->song(273.0, 480.0);

        $this->assertSame(0, $this->introduction()->extend($this->run));
        $this->assertEqualsWithDelta(273.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function the_song_edge_job_runs_it_after_the_lyric_edges(): void
    {
        $section = $this->song(273.0, 480.0);
        $this->timeline([[0, 255, 0.02, 0.85], [255, 480, 0.7, 0.03]]);

        app()->call([new ExtendSongsOverOwnLyrics($this->run), 'handle']);

        $this->assertEqualsWithDelta(255.0, (float) $section->fresh()->start_time, 0.01);
    }

    private function introduction(): SongMusicalIntroduction
    {
        return app(SongMusicalIntroduction::class);
    }

    private function song(float $start, float $end): ServiceSection
    {
        return $this->section(ServiceSectionType::Song, $start, $end);
    }

    private function section(ServiceSectionType $type, float $start, float $end): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $this->run->id,
            'section_type' => $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'metadata' => [],
        ]);
    }

    /**
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float, 4?: float}>  $spans  [from, to, music, speech, singing]
     */
    private function timeline(array $spans): void
    {
        $path = 'service-transcripts/test-'.$this->run->processing_id.'.classes.json';
        Storage::disk('local')->put($path, AudioTimelineFixture::json($spans, 5000.0));
        $this->run->forceFill(['audio_timeline_path' => $path])->save();
    }

    /** 1011's windows: the reading, the dropout, the solo, then the congregation and band. */
    private function quietSoloTimeline(): void
    {
        $this->timeline([[0, 440, 0.02, 0.75, 0.001], [440, 455, 0.12, 0.02, 0.001], [455, 520, 0.18, 0.03, 0.05], [520, 640, 0.7, 0.02, 0.2]]);
    }

    /**
     * A level log sampled every 0.1 s.
     *
     * @param  list<array{0: float, 1: float, 2: float}>  $spans  [from, to, dB]
     */
    private function levels(array $spans): void
    {
        $lines = [];
        $frame = 0;

        foreach ($spans as [$from, $to, $level]) {
            for ($time = $from; $time < $to - 0.0001; $time = round($time + 0.1, 2)) {
                $lines[] = sprintf('frame:%d pts:0 pts_time:%.2f', $frame++, $time);
                $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
            }
        }

        $path = 'service-transcripts/test-'.$this->run->processing_id.'.rms.log';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");
        $this->run->forceFill(['rms_log_path' => $path])->save();
    }
}
