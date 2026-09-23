<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongSpeechUnderLoop;
use App\Services\Media\Audio\SustainedSound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A transcript loop that manufactures sung text over speech (1268 §4731, 967 §1082, 1348 §4390).
 *
 * Measured 2026-09-23 (`speechloop-20260923-measure.json`) over 513 loop blocks inside song
 * sections: under a fifth sustained, 30 s or more, a repeated-phrase loop, on a run whose other
 * songs read as sung, flags 12 sections — 1082, 4390, 4449 and 4731 among them, all 12 held —
 * and the count does not move between 0.1 and 0.2.
 */
class SongSpeechUnderLoopTest extends TestCase
{
    use RefreshDatabase;

    /** §4731: "we honour and adore you" ×63 over the prayer §4732 then continues. */
    #[Test]
    public function it_flags_a_loop_whose_audio_is_speech(): void
    {
        $observation = $this->observe(loopSung: false);

        $this->assertNotNull($observation);
        $this->assertTrue($observation['risk']);
        $this->assertSame(SongSpeechUnderLoop::RISK_KIND, 'song_speech_under_loop');
    }

    /** The ordinary song loop: a chorus the decoder repeated, over singing. */
    #[Test]
    public function it_leaves_a_loop_over_singing_alone(): void
    {
        $this->assertFalse($this->observe(loopSung: true)['risk'] ?? false);
    }

    /** 1267, 1235, 1309: where the run's songs do not read as sung, speech-shaped sound says nothing. */
    #[Test]
    public function it_says_nothing_on_a_run_whose_other_songs_do_not_read_as_sung(): void
    {
        $this->assertNull($this->observe(loopSung: false, otherSongsSung: false));
    }

    #[Test]
    public function it_leaves_a_sparse_cadence_block_alone(): void
    {
        $this->assertFalse($this->observe(loopSung: false, reason: SuspectTranscriptBlock::REASON_SPARSE_CADENCE)['risk'] ?? false);
    }

    #[Test]
    public function it_leaves_a_loop_shorter_than_thirty_seconds_alone(): void
    {
        $this->assertFalse($this->observe(loopSung: false, loopSeconds: 20.0)['risk'] ?? false);
    }

    /**
     * A song section 300–600 s whose loop sits at 400 s, and another song at 700–900 s.
     *
     * @return array<string, mixed>|null
     */
    private function observe(bool $loopSung, bool $otherSongsSung = true, string $reason = SuspectTranscriptBlock::REASON_REPEATED_PHRASE, float $loopSeconds = 90.0): ?array
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song->value, 'start_time' => 300.0, 'end_time' => 600.0]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song->value, 'start_time' => 700.0, 'end_time' => 900.0]);

        $block = new SuspectTranscriptBlock(start: 400.0, end: 400.0 + $loopSeconds, reason: $reason, words: 200, wordsPerMinute: 120.0, phrase: 'we honour and adore you', repeats: 63);

        // Sampled every 0.1 s: singing holds -18 dB; speech runs 2.5 s at -25 dB and pauses 0.5 s.
        $samples = [];
        for ($tenth = 0; $tenth < 10000; $tenth++) {
            $time = $tenth / 10;
            $inLoop = $time >= $block->start && $time < $block->end;
            $sung = ($inLoop && $loopSung)
                || ($time >= 700.0 && $time < 900.0 && $otherSongsSung)
                || (! $inLoop && $time >= 300.0 && $time < 600.0);
            $samples[] = ['time' => $time, 'rms' => $sung ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0)];
        }

        return (new SongSpeechUnderLoop)->observe($section, [$block], SustainedSound::fromSamples($samples, -45.0));
    }
}
