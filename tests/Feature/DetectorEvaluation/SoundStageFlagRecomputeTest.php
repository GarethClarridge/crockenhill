<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\SoundStageFlagRecompute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SoundStageFlagRecomputeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A run with no banked structure cannot be recomputed, and must read as
     * unknown rather than as a run with nothing to find.
     *
     * H10a lost a whole pass to this distinction: reading from the wrong place
     * reports every run unavailable, which is indistinguishable from a clean
     * corpus. Anything that cannot be assessed is counted in its own column.
     */
    public function test_a_run_without_banked_structure_is_unassessable(): void
    {
        $run = MediaProcessingLog::factory()->create(['processing_metadata' => []]);

        $this->assertNull($this->recompute()->forRun($run));
    }

    public function test_a_run_without_an_rms_log_is_unassessable(): void
    {
        $run = MediaProcessingLog::factory()->create([
            'rms_log_path' => null,
            'processing_metadata' => ['service_structure' => ['sections' => []]],
        ]);

        $this->assertNull($this->recompute()->forRun($run));
    }

    /**
     * An unassessable run must never be folded into the assessed count, or the
     * report would describe a smaller corpus than it claims.
     */
    public function test_it_reports_unassessable_runs_separately(): void
    {
        $runs = [
            MediaProcessingLog::factory()->create(['processing_metadata' => []]),
            MediaProcessingLog::factory()->create(['processing_metadata' => []]),
        ];

        $report = $this->recompute()->over($runs);

        $this->assertSame(0, $report['runs_assessed']);
        $this->assertSame(2, $report['runs_unassessable']);
        $this->assertSame(
            array_map(static fn (MediaProcessingLog $run): int => (int) $run->id, $runs),
            $report['unassessable_run_ids'],
        );
        $this->assertSame([], $report['sections_gaining_flag']);
    }

    /**
     * The pipeline runs {@see \App\Services\ChurchService\Structure\SongSpeechEdges} between
     * widening and the mistyped-sung pass, and its held-not-trimmed flag is a sound-stage flag
     * like the rest, so a replay that skipped it would report 974 §988's shape as clean.
     */
    public function test_it_replays_the_speech_edge_stage_and_reports_a_song_that_swallowed_speech(): void
    {
        Storage::fake('local');
        config()->set('media-processing.storage.temp_disk', 'local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        Storage::disk('local')->put('rms/run.log', $this->rmsLog([[0, 480, 'speech'], [480, 600, 'sung'], [600, 700, 'speech'], [700, 900, 'sung']]));
        Storage::disk('local')->put('service-transcripts/run.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 10.0, 'end' => 20.0, 'text' => 'Let us pray.'],
        ], 900.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER)->toArray(), JSON_THROW_ON_ERROR));

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'rms_log_path' => 'rms/run.log',
            'processing_metadata' => [
                'service_transcript_path' => 'service-transcripts/run.json',
                'service_structure' => ['sections' => [
                    ['type' => 'prayer', 'start_time' => 0.0, 'end_time' => 300.0, 'confidence' => 0.9],
                    ['type' => 'song', 'start_time' => 300.0, 'end_time' => 600.0, 'confidence' => 0.9],
                    ['type' => 'song', 'start_time' => 700.0, 'end_time' => 900.0, 'confidence' => 0.9],
                ]],
            ],
        ]);

        $gained = $this->recompute()->forRun($run);

        $this->assertSame(1, $gained[ServiceStructureValidator::FLAG_SONG_SWALLOWS_SPEECH] ?? null);
    }

    /**
     * The RMS log shape of {@see \Tests\Unit\Services\SongSpeechEdgesTest}: singing holds a level,
     * speech pauses every three seconds.
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

    private function recompute(): SoundStageFlagRecompute
    {
        return app(SoundStageFlagRecompute::class);
    }
}
