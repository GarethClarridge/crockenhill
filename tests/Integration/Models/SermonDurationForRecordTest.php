<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Data\SermonCreationOptions;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P8-Q13b: the duration banked on a Sermon must describe the emitted media,
 * never the outer source window a concat plan spans.
 */
class SermonDurationForRecordTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prefers_the_duration_observed_in_the_emitted_media(): void
    {
        $log = $this->concatRun(observedDuration: 2411.82);

        $this->assertSame(2411.82, $log->sermonDurationForRecord());
    }

    #[Test]
    public function it_falls_back_to_the_summed_spans_rather_than_the_outer_window(): void
    {
        $log = $this->concatRun(observedDuration: null);

        // Spans sum to 127.06 + 2284.71; the outer window is 3298.70, and the
        // 886.92 s between the spans was never extracted.
        $this->assertEqualsWithDelta(2411.77, $log->sermonDurationForRecord(), 0.01);
    }

    #[Test]
    public function it_reports_nothing_when_the_run_recorded_no_measurement(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 50.0034,
            'sermon_end_time' => 3348.7,
            'processing_metadata' => [],
        ]);

        $this->assertNull($log->sermonDurationForRecord());
    }

    #[Test]
    public function livestream_options_carry_the_extracted_duration_not_the_source_window(): void
    {
        $log = $this->concatRun(observedDuration: null);

        $options = SermonCreationOptions::fromLivestream($log, [
            'segment_start_time' => $log->sermon_start_time,
            'segment_end_time' => $log->sermon_end_time,
        ]);

        $this->assertEqualsWithDelta(2411.77, $options->resolvedDuration(), 0.01);
        $this->assertNotEqualsWithDelta(3298.70, $options->resolvedDuration(), 1.0);
    }

    private function concatRun(?float $observedDuration): MediaProcessingLog
    {
        $metadata = [
            'sermon_extraction_plan' => [
                'mode' => 'concat_spans',
                'segments' => [
                    ['start_time' => 50.0034, 'end_time' => 177.065],
                    ['start_time' => 1063.99, 'end_time' => 3348.7],
                ],
            ],
        ];

        if ($observedDuration !== null) {
            $metadata['trim'] = ['observed_duration' => $observedDuration];
        }

        return MediaProcessingLog::factory()->livestream()->create([
            'audio_file_path' => 'sermons/audio/concat-run.mp3',
            'sermon_start_time' => 50.0034,
            'sermon_end_time' => 3348.7,
            'processing_metadata' => $metadata,
        ]);
    }
}
