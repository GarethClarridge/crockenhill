<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\PathologicalWindowSoundSpans;
use App\Services\Media\Audio\ServiceTranscriptCoverageScreen;
use Tests\TestCase;

class ServiceTranscriptCoverageScreenTest extends TestCase
{
    private ServiceTranscriptCoverageScreen $screen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->screen = new ServiceTranscriptCoverageScreen(new PathologicalWindowSoundSpans);
    }

    /**
     * The case the screen exists for: four minutes of speech-level audio with
     * almost nothing transcribed against it. The repetition screen cannot see
     * this — there is no repetition, no impossible density and no 30-second
     * cadence, just missing words.
     */
    public function test_it_finds_sustained_sound_with_no_words_against_it(): void
    {
        $gaps = $this->screen->screen(
            $this->speechThroughout(600.0),
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 240.0),
        );

        $this->assertCount(1, $gaps);
        $this->assertGreaterThanOrEqual(240.0, $gaps[0]->start);
        $this->assertLessThan(10.0, $gaps[0]->wordsPerMinute());
    }

    public function test_a_fully_transcribed_recording_has_no_gaps(): void
    {
        $this->assertSame([], $this->screen->screen(
            $this->speechThroughout(600.0),
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 600.0),
        ));
    }

    /**
     * Silence is not a gap. A recording that simply stops carrying sound has
     * nothing for the transcript to account for.
     */
    public function test_silence_is_not_a_gap(): void
    {
        $rms = [];

        for ($time = 0.0; $time < 600.0; $time += 1.0) {
            // Speech to 240s, then a −60 dB noise floor for the rest.
            $rms[] = ['time' => $time, 'rms' => $time < 240.0 ? -25.0 : -60.0];
        }

        $this->assertSame([], $this->screen->screen(
            $rms,
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 240.0),
        ));
    }

    /**
     * Singing is routinely invisible to the decoder, so song spans would
     * otherwise dominate the output and bury every real gap.
     */
    public function test_it_ignores_excluded_song_spans(): void
    {
        $this->assertSame([], $this->screen->screen(
            $this->speechThroughout(600.0),
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 240.0),
            [['start' => 230.0, 'end' => 600.0]],
        ));
    }

    /**
     * A held pause or a reader finding their place is ordinary. Only stretches
     * past the configured floor are worth a human minute.
     */
    public function test_a_short_quiet_stretch_is_not_reported(): void
    {
        config(['media-processing.service_structure.coverage_screen.min_gap_seconds' => 600.0]);

        $this->assertSame([], $this->screen->screen(
            $this->speechThroughout(600.0),
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 240.0),
        ));
    }

    public function test_it_reports_nothing_without_rms_data(): void
    {
        $this->assertSame([], $this->screen->screen([], $this->transcript(600.0, 0.0, 240.0)));
    }

    /**
     * Rate is words per minute *of sound*, so a stretch that is half silent is
     * not flattered by dividing across the silence.
     */
    public function test_the_rate_is_measured_against_sound_not_elapsed_time(): void
    {
        $gaps = $this->screen->screen(
            $this->speechThroughout(600.0),
            $this->transcript(600.0, spokenFrom: 0.0, spokenTo: 240.0),
        );

        $this->assertCount(1, $gaps);
        $this->assertGreaterThan(0.0, $gaps[0]->soundedSeconds);
        $this->assertLessThanOrEqual($gaps[0]->seconds(), $gaps[0]->soundedSeconds);
    }

    /**
     * @return list<array{time: float, rms: float}>
     */
    private function speechThroughout(float $duration): array
    {
        $rms = [];

        for ($time = 0.0; $time < $duration; $time += 1.0) {
            $rms[] = ['time' => $time, 'rms' => -25.0];
        }

        return $rms;
    }

    private function transcript(float $duration, float $spokenFrom, float $spokenTo): ChurchServiceTranscript
    {
        $cues = [];

        for ($start = $spokenFrom; $start < $spokenTo; $start += 5.0) {
            $cues[] = [
                'start' => $start,
                'end' => min($start + 5.0, $spokenTo),
                // Roughly 144 wpm, comfortably ordinary speech.
                'text' => 'and he said to them that the kingdom of heaven is like a man who',
            ];
        }

        return new ChurchServiceTranscript(
            cues: $cues,
            duration: $duration,
            source: ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );
    }
}
