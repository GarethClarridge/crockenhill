<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\HistoricMedia\HistoricVideoPassPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricVideoPassPerformanceTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const GIB = 1024 ** 3;

    #[Test]
    public function it_reports_worker_widths_from_persisted_profiles_not_current_configuration(): void
    {
        $operation = $this->createHistoricImportOperation();

        $this->createRun(
            $operation, 'item-one', 'run-width-two', ProcessingStatus::Completed, 1,
            '2026-08-31 12:00:00.000000', '2026-08-31 12:00:10.000000', '2026-08-31 12:00:20.000000',
            ['historic_import' => ['execution_profile' => [
                'ffmpeg' => ['routing_fingerprint' => str_repeat('a', 64), 'worker_width' => 2],
            ]]],
            self::GIB,
        );

        // The machine has since been reverted to one FFmpeg worker.
        config(['media-processing.historic_import.stages.ffmpeg.workers' => 1]);

        $report = app(HistoricVideoPassPerformance::class)->report($operation, ['item-one']);

        $this->assertSame('uniform', $report['observed_worker_widths']['ffmpeg']['status']);
        $this->assertSame(2, $report['observed_worker_widths']['ffmpeg']['value']);
        $this->assertSame(1, $report['observed_worker_widths']['ffmpeg']['runs_with_profile']);
        $this->assertSame(0, $report['observed_worker_widths']['ffmpeg']['runs_missing_profile']);

        // Current configuration is still reported, but separately.
        $this->assertSame(1, $report['current_configured_worker_widths']['ffmpeg']);
    }

    #[Test]
    public function it_reports_mixed_and_missing_worker_widths_explicitly(): void
    {
        $operation = $this->createHistoricImportOperation();

        $this->createRun(
            $operation, 'item-one', 'run-width-one', ProcessingStatus::Completed, 1,
            '2026-08-31 12:00:00.000000', '2026-08-31 12:00:10.000000', '2026-08-31 12:00:20.000000',
            ['historic_import' => ['execution_profile' => [
                'ffmpeg' => ['routing_fingerprint' => str_repeat('a', 64), 'worker_width' => 1],
            ]]],
            self::GIB,
        );
        $this->createRun(
            $operation, 'item-two', 'run-width-two', ProcessingStatus::Completed, 1,
            '2026-08-31 12:01:00.000000', '2026-08-31 12:01:10.000000', '2026-08-31 12:01:20.000000',
            ['historic_import' => ['execution_profile' => [
                'ffmpeg' => ['routing_fingerprint' => str_repeat('a', 64), 'worker_width' => 2],
            ]]],
            self::GIB,
        );
        $this->createRun(
            $operation, 'item-three', 'run-no-profile', ProcessingStatus::Completed, 1,
            '2026-08-31 12:02:00.000000', '2026-08-31 12:02:10.000000', '2026-08-31 12:02:20.000000',
            [],
            self::GIB,
        );

        $report = app(HistoricVideoPassPerformance::class)
            ->report($operation, ['item-one', 'item-two', 'item-three']);

        // No single number is true of this selection, and it must not pretend otherwise.
        $ffmpeg = $report['observed_worker_widths']['ffmpeg'];
        $this->assertSame('mixed', $ffmpeg['status']);
        $this->assertNull($ffmpeg['value']);
        $this->assertSame([1, 2], $ffmpeg['values']);
        $this->assertSame(2, $ffmpeg['runs_with_profile']);
        $this->assertSame(1, $ffmpeg['runs_missing_profile']);

        // A stage no run recorded is unknown, never silently the configured value.
        $whisper = $report['observed_worker_widths']['whisper'];
        $this->assertSame('missing', $whisper['status']);
        $this->assertNull($whisper['value']);
        $this->assertSame(3, $whisper['runs_missing_profile']);
    }

    #[Test]
    public function it_reports_scoped_run_timings_retries_clean_samples_and_missing_coverage(): void
    {
        $operation = $this->createHistoricImportOperation();
        $this->createRun(
            $operation,
            'item-one',
            'run-one',
            ProcessingStatus::Completed,
            1,
            '2026-08-31 12:00:00.000000',
            '2026-08-31 12:00:10.123456',
            '2026-08-31 12:00:20.123456',
            [
                'trim' => ['observed_duration' => 1800.0],
                'api_response_times_ms' => [100.0, 200.0],
                'historic_import' => [
                    'execution_profile' => [
                        'ffmpeg' => ['routing_fingerprint' => str_repeat('a', 64), 'worker_width' => 1],
                    ],
                ],
            ],
            4 * self::GIB,
        );
        $this->createStep('run-one', 'rms_generation', ProcessingStatus::Completed, '2026-08-31 12:00:10.123456', '2026-08-31 12:00:12.123456');
        $this->createStep('run-one', 'analyzing_segments', ProcessingStatus::Completed, '2026-08-31 12:00:15.123456', '2026-08-31 12:00:20.123456');
        $this->createStep('run-one', 'generating_thumbnail', ProcessingStatus::Skipped, '2026-08-31 12:00:20.123456', '2026-08-31 12:00:20.123456');
        $this->createStep('run-one', 'future_step', ProcessingStatus::Completed, '2026-08-31 12:00:21.123456', '2026-08-31 12:00:22.123456');

        $this->createRun(
            $operation,
            'item-two',
            'run-retried',
            ProcessingStatus::Completed,
            2,
            '2026-08-31 12:00:30.000000',
            '2026-08-31 12:00:31.000000',
            '2026-08-31 12:00:40.000000',
            [],
            2 * self::GIB,
        );
        $this->createRun(
            $operation,
            'item-two',
            'run-in-progress',
            ProcessingStatus::Processing,
            1,
            '2026-08-31 12:00:50.000000',
            null,
            null,
            [],
            1 * self::GIB,
        );
        $this->createRun(
            $this->createHistoricImportOperation(),
            'item-one',
            'run-other-operation',
            ProcessingStatus::Completed,
            1,
            '2026-08-31 11:00:00.000000',
            '2026-08-31 11:00:01.000000',
            '2026-08-31 11:00:02.000000',
            [],
            99 * self::GIB,
        );

        $report = app(HistoricVideoPassPerformance::class)->report(
            $operation,
            ['item-one', 'item-two', 'not-dispatched'],
        );

        $this->assertSame(['item-one', 'item-two', 'not-dispatched'], $report['item_keys']);
        $this->assertCount(3, $report['runs']);
        $this->assertSame('completed', $report['items'][0]['disposition']);
        $this->assertSame('in_progress', $report['items'][1]['disposition']);
        $this->assertSame('not_dispatched', $report['items'][2]['disposition']);

        $firstRun = $report['runs'][0];
        $this->assertSame(10.0, $firstRun['queue_delay_seconds']);
        $this->assertSame(10.0, $firstRun['elapsed_seconds']);
        $this->assertSame(1800.0, $firstRun['content_seconds']);
        $this->assertSame(4 * self::GIB, $firstRun['source_bytes']);
        $this->assertSame('complete', $firstRun['timing_state']);
        $this->assertSame(1, $firstRun['execution_profile']['ffmpeg']['worker_width']);
        $this->assertSame([100.0, 200.0], $firstRun['api_response_times_ms']);

        $inProgressRun = $report['runs'][2];
        $this->assertNull($inProgressRun['completed_at']);
        $this->assertNull($inProgressRun['elapsed_seconds']);
        $this->assertSame('incomplete', $inProgressRun['timing_state']);

        $this->assertSame(3, $report['all_runs']['run_count']);
        $this->assertSame(2, $report['all_runs']['timed_run_count']);
        $this->assertSame(1, $report['all_runs']['runs_missing_timing_count']);
        $this->assertSame(1, $report['all_runs']['retried_run_count']);
        $this->assertSame(1, $report['clean_first_attempt']['run_count']);
        $this->assertSame('run-retried', $report['all_runs']['runs_with_retries'][0]);

        $this->assertSame('unknown', $report['runs'][0]['steps'][3]['stage']);
        $this->assertSame(3.0, $report['step_summary']['analyzing_segments']['p50_queue_wait_seconds']);
        $this->assertSame(5.0, $report['step_summary']['analyzing_segments']['p95_active_duration_seconds']);
        $this->assertSame(1, $report['step_summary']['generating_thumbnail']['skipped_count']);
        $this->assertSame(3, $report['step_summary']['extracting_audio']['missing_coverage_count']);
        $this->assertSame(1, $report['stages']['max_overlapping_step_intervals']['unknown']);
        $this->assertSame(
            ['ffmpeg', 'whisper', 'llm', 'orchestration'],
            array_keys($report['current_configured_worker_widths']),
        );
        $this->assertSame(
            ['ffmpeg', 'whisper', 'llm', 'orchestration'],
            array_keys($report['observed_worker_widths']),
        );
    }

    #[Test]
    public function it_uses_nearest_rank_percentiles_and_null_for_no_samples(): void
    {
        $performance = app(HistoricVideoPassPerformance::class);

        $this->assertSame(
            ['p50' => 2.0, 'p95' => 4.0, 'max' => 4.0, 'count' => 4],
            $performance->percentiles([1.0, 2.0, 3.0, 4.0]),
        );
        $this->assertSame(
            ['p50' => null, 'p95' => null, 'max' => null, 'count' => 0],
            $performance->percentiles([]),
        );
    }

    /**
     * `clean_first_attempt` is the number a pass retrospective quotes as its throughput, and it is
     * the one a degraded run silently inflates: the run reached `completed` by substituting empty
     * analysis for a failed provider call, so counting it makes a pass that analysed nothing look
     * like the fastest one measured. The 2026-09-02 pass had six of these and no report said so.
     */
    #[Test]
    public function it_names_degraded_completions_and_keeps_them_out_of_clean_throughput(): void
    {
        $operation = $this->createHistoricImportOperation();

        $clean = $this->createRun(
            $operation, 'clean-item', 'run-clean', ProcessingStatus::Completed, 1,
            '2026-09-02 16:00:00.000000', '2026-09-02 16:00:10.000000', '2026-09-02 16:20:10.000000',
            [], 4 * self::GIB,
        );

        $degraded = $this->createRun(
            $operation, 'degraded-item', 'run-degraded', ProcessingStatus::Completed, 1,
            '2026-09-02 16:00:00.000000', '2026-09-02 16:00:10.000000', '2026-09-02 16:05:10.000000',
            [], 4 * self::GIB,
        );
        $degraded->update(['is_degraded_completion' => true, 'current_step' => 'ai_analysis_fallback']);

        $report = app(HistoricVideoPassPerformance::class)->report($operation, ['clean-item', 'degraded-item']);

        $this->assertSame('completed', $report['items'][0]['disposition']);
        $this->assertSame('degraded', $report['items'][1]['disposition']);

        $this->assertFalse($report['runs'][0]['is_degraded_completion']);
        $this->assertTrue($report['runs'][1]['is_degraded_completion']);
        $this->assertNotContains('degraded_completion', $report['runs'][0]['classification']);
        $this->assertContains('degraded_completion', $report['runs'][1]['classification']);

        // Both runs are timed and terminal, so only the degraded exclusion can separate the counts.
        $this->assertSame(2, $report['all_runs']['run_count']);
        $this->assertSame(1, $report['clean_first_attempt']['run_count']);
        $this->assertSame(3, $report['version']);

        $this->assertSame('run-clean', $clean->processing_id);
    }

    private function createRun(
        HistoricImportOperation $operation,
        string $itemKey,
        string $processingId,
        ProcessingStatus $status,
        int $attemptCount,
        string $createdAt,
        ?string $startedAt,
        ?string $completedAt,
        array $extraMetadata,
        int $sourceBytes,
    ): MediaProcessingLog {
        $historicImport = [
            'manifest_item_key' => $itemKey,
            'sources' => [['path' => $itemKey.'.mkv', 'size' => $sourceBytes]],
            ...(is_array($extraMetadata['historic_import'] ?? null) ? $extraMetadata['historic_import'] : []),
        ];
        unset($extraMetadata['historic_import']);

        return MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'historic_import_operation_id' => $operation->id,
            'status' => $status,
            'current_step' => $status === ProcessingStatus::Processing ? 'analyzing_segments' : 'completed',
            'attempt_count' => $attemptCount,
            'file_size' => $sourceBytes,
            'duration' => 3600.0,
            'created_at' => $createdAt,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'processing_metadata' => [
                'historic_import' => $historicImport,
                ...$extraMetadata,
            ],
        ]);
    }

    private function createStep(
        string $processingId,
        string $step,
        ProcessingStatus $status,
        string $startedAt,
        string $completedAt,
    ): SermonProcessingStep {
        return SermonProcessingStep::query()->create([
            'processing_id' => $processingId,
            'step' => $step,
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
    }
}
