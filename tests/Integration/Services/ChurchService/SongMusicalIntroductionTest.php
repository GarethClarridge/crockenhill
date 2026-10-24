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
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $spans  [from, to, music, speech]
     */
    private function timeline(array $spans): void
    {
        $path = 'service-transcripts/test-'.$this->run->processing_id.'.classes.json';
        Storage::disk('local')->put($path, AudioTimelineFixture::json($spans, 5000.0));
        $this->run->forceFill(['audio_timeline_path' => $path])->save();
    }
}
