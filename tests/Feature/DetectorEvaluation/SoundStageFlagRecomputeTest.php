<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Models\MediaProcessingLog;
use App\Services\DetectorEvaluation\SoundStageFlagRecompute;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function recompute(): SoundStageFlagRecompute
    {
        return app(SoundStageFlagRecompute::class);
    }
}
