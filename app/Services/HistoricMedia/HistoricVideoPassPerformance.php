<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Builds the database-owned performance evidence for one historic-video pass.
 *
 * Run and step timestamps are deliberately read from their durable models. A
 * queue dashboard or a structured log can enrich an old retrospective, but it
 * cannot be the only evidence used to admit the next pass.
 */
final class HistoricVideoPassPerformance
{
    private const FORMAT = 'crockenhill.historic-video-pass-performance';

    /**
     * Version 2 adds `is_degraded_completion` per run, the `degraded_completion` classification and
     * the `degraded` terminal disposition, and removes degraded runs from the clean-first-attempt
     * sample. A version 1 report cannot be compared like-for-like with a version 2 one: its
     * `clean_first_attempt` aggregate counted runs that banked empty analysis, which is how the
     * 2026-09-02 pass reported six hollow sermons inside its clean throughput.
     *
     * Version 3 removes the `usage` key entirely (P1-4, 2026-09-02): the internal cost ledger it
     * read from is deleted, and its `api_response_time_summary_ms` sub-key was always empty because
     * nothing ever wrote `api_response_times_ms` to run metadata. Ordinary provider usage telemetry
     * survives in the application log via `OpenAiUsageLogger`; only this inert reporting surface goes.
     */
    private const VERSION = 3;

    /**
     * These are the high-cost steps whose absence is itself useful evidence.
     *
     * @var list<string>
     */
    private const HIGH_COST_STEPS = [
        'rms_generation',
        'analyzing_segments',
        'extracting_audio',
        'generating_thumbnail',
    ];

    public function __construct(
        private readonly HistoricProcessingThroughput $throughput,
    ) {}

    /**
     * @param  list<string>  $itemKeys
     * @return array<string, mixed>
     */
    public function report(HistoricImportOperation $operation, array $itemKeys): array
    {
        $itemKeys = $this->normaliseItemKeys($itemKeys);
        $runs = MediaProcessingLog::query()
            ->where('historic_import_operation_id', $operation->id)
            ->with('processingSteps')
            ->orderBy('id')
            ->get()
            ->filter(fn (MediaProcessingLog $run): bool => in_array($this->itemKey($run), $itemKeys, true))
            ->values();

        $runReports = [];

        foreach ($runs as $run) {
            $runReports[] = $this->runReport($run);
        }

        $stepSummaries = $this->stepSummaries($runReports);
        $configuredWorkerWidths = $this->throughput->configuredWidths();
        $observedWorkerWidths = $this->observedWorkerWidths($runReports, array_keys($configuredWorkerWidths));
        $allRuns = $this->aggregate($runReports, 'all_runs');
        $cleanRuns = array_values(array_filter(
            $runReports,
            fn (array $run): bool => $this->isCleanFirstAttempt($run),
        ));
        $cleanFirstAttempt = $this->aggregate($cleanRuns, 'clean_first_attempt');

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'operation_id' => $operation->operation_id,
            'item_keys' => $itemKeys,
            'generated_at' => now()->toISOString(),
            'items' => $this->itemSummaries($itemKeys, $runReports),
            'runs' => $runReports,
            'run_timings' => $runReports,
            'steps' => $stepSummaries,
            'step_summary' => $stepSummaries,
            'stages' => [
                /**
                 * What the selected runs actually ran with, from the execution
                 * profile persisted with each one. This is the number a
                 * retrospective must quote: current configuration describes the
                 * machine now, not the pass being reported, and widths get
                 * changed and reverted between passes.
                 */
                'observed_worker_widths' => $observedWorkerWidths,
                'current_configured_worker_widths' => $configuredWorkerWidths,
                'max_overlapping_step_intervals' => $this->stageOverlap($runReports),
            ],
            'observed_worker_widths' => $observedWorkerWidths,
            'current_configured_worker_widths' => $configuredWorkerWidths,
            'all_runs' => $allRuns,
            'clean_first_attempt' => $cleanFirstAttempt,
            'measurement' => [
                'run_source' => 'media_processing_logs',
                'step_source' => 'sermon_processing_steps',
                'attempt_history' => false,
                'retry_semantics' => 'A retry replaces canonical step timestamps; this is not an attempt-history ledger.',
                'missing_step_coverage' => $this->missingStepCoverage($stepSummaries),
            ],
        ];
    }

    /**
     * Nearest-rank percentiles keep a measured sample intact. In particular,
     * with four samples p95 is the maximum, not an invented interpolation.
     *
     * @param  list<float>  $samples
     * @return array{p50: float|null, p95: float|null, max: float|null, count: int}
     */
    public function percentiles(array $samples): array
    {
        if ($samples === []) {
            return ['p50' => null, 'p95' => null, 'max' => null, 'count' => 0];
        }

        sort($samples);
        $count = count($samples);

        return [
            'p50' => $this->nearestRank($samples, 0.50),
            'p95' => $this->nearestRank($samples, 0.95),
            'max' => $samples[$count - 1],
            'count' => $count,
        ];
    }

    /**
     * @param  list<string>  $itemKeys
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function itemSummaries(array $itemKeys, array $runs): array
    {
        $summaries = [];

        foreach ($itemKeys as $itemKey) {
            $itemRuns = array_values(array_filter(
                $runs,
                static fn (array $run): bool => $run['item_key'] === $itemKey,
            ));

            $summaries[] = [
                'item_key' => $itemKey,
                'disposition' => $this->disposition($itemRuns),
                'processing_ids' => array_map(
                    static fn (array $run): string => (string) $run['processing_id'],
                    $itemRuns,
                ),
                'run_count' => count($itemRuns),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function runReport(MediaProcessingLog $run): array
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $terminal = $this->isTerminal($run->status);
        $completedAt = $terminal ? $run->completed_at : null;
        $queueDelay = $this->secondsBetween($run->created_at, $run->started_at);
        $elapsed = $terminal
            ? $this->secondsBetween($run->started_at, $completedAt)
            : null;
        $attemptCount = $run->attempt_count;
        $manualReview = $run->status === ProcessingStatus::Failed
            && $run->current_step === 'manual_review_required';
        $mountFailed = $this->isMountFailure($run, $metadata);
        $reExtraction = $run->isReExtraction();
        $degraded = $run->status === ProcessingStatus::Completed && $run->isDegradedCompletion();
        $executionProfile = data_get($metadata, 'historic_import.execution_profile');

        return [
            'item_key' => $this->itemKey($run),
            'processing_id' => $run->processing_id,
            'terminal_disposition' => $this->terminalDisposition($run),
            'status' => $run->status->value,
            'attempt_count' => $attemptCount,
            'source_bytes' => $this->sourceBytes($run, $metadata),
            'media_seconds' => $this->positiveFloat($run->duration),
            'content_seconds' => $this->positiveFloat(
                $run->observedSermonMediaDuration()
                    ?? $run->extractedSermonMediaDuration(),
            ),
            'created_at' => $run->created_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'completed_at' => $completedAt?->toISOString(),
            'queue_delay_seconds' => $queueDelay,
            'elapsed_seconds' => $elapsed,
            'timing_state' => $queueDelay !== null && $elapsed !== null ? 'complete' : 'incomplete',
            'terminal' => $terminal,
            'retry' => $attemptCount !== null && $attemptCount > 1,
            'is_re_extraction' => $reExtraction,
            'is_manual_review' => $manualReview,
            'is_mount_failed' => $mountFailed,
            'is_degraded_completion' => $degraded,
            'execution_profile' => is_array($executionProfile) ? $executionProfile : null,
            'api_response_times_ms' => $this->responseTimeSamples($metadata),
            'classification' => $this->classifications(
                $attemptCount,
                $reExtraction,
                $manualReview,
                $mountFailed,
                $degraded,
            ),
            'steps' => $this->stepTimings($run->processingSteps),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function aggregate(array $runs, string $name): array
    {
        $timedRuns = array_values(array_filter(
            $runs,
            static fn (array $run): bool => is_float($run['elapsed_seconds'])
                || is_int($run['elapsed_seconds']),
        ));
        $terminalRuns = array_values(array_filter(
            $runs,
            static fn (array $run): bool => $run['terminal'] === true,
        ));
        $started = array_values(array_filter(
            array_map(
                fn (array $run): ?float => $this->timestamp($run['started_at'] ?? null),
                $runs,
            ),
            static fn (?float $timestamp): bool => $timestamp !== null,
        ));
        $completed = array_values(array_filter(
            array_map(
                fn (array $run): ?float => $this->timestamp($run['completed_at'] ?? null),
                $terminalRuns,
            ),
            static fn (?float $timestamp): bool => $timestamp !== null,
        ));
        $earliestStart = $started === [] ? null : min($started);
        $latestCompletion = $completed === [] ? null : max($completed);
        $wallTime = $earliestStart !== null
            && $latestCompletion !== null
            && $latestCompletion >= $earliestStart
            ? round($latestCompletion - $earliestStart, 6)
            : null;
        $wallHours = $wallTime !== null && $wallTime > 0.0 ? $wallTime / 3600 : null;
        $sampleItemKeys = array_values(array_unique(array_map(
            static fn (array $run): string => (string) $run['item_key'],
            $timedRuns,
        )));
        $sourceBytes = array_values(array_filter(
            array_map(
                static fn (array $run): ?int => is_int($run['source_bytes']) ? $run['source_bytes'] : null,
                $timedRuns,
            ),
            static fn (?int $bytes): bool => $bytes !== null,
        ));
        $contentSeconds = array_values(array_filter(
            array_map(
                static fn (array $run): ?float => is_numeric($run['content_seconds'])
                    ? (float) $run['content_seconds']
                    : null,
                $timedRuns,
            ),
            static fn (?float $seconds): bool => $seconds !== null && $seconds > 0.0,
        ));
        $itemsPerHour = $wallHours !== null && $wallHours > 0.0
            ? count($sampleItemKeys) / $wallHours
            : null;
        $sourceGiBPerHour = $wallHours !== null && $wallHours > 0.0 && $sourceBytes !== []
            ? array_sum($sourceBytes) / (1024 ** 3) / $wallHours
            : null;
        $contentHoursPerWallHour = $wallTime !== null && $wallTime > 0.0 && $contentSeconds !== []
            ? array_sum($contentSeconds) / $wallTime
            : null;
        $missingTimingIds = array_values(array_map(
            static fn (array $run): string => (string) $run['processing_id'],
            array_filter(
                $runs,
                static fn (array $run): bool => $run['timing_state'] !== 'complete',
            ),
        ));
        $retriedRunIds = array_values(array_map(
            static fn (array $run): string => (string) $run['processing_id'],
            array_filter(
                $runs,
                static fn (array $run): bool => $run['retry'] === true,
            ),
        ));

        $aggregate = [
            'name' => $name,
            'run_count' => count($runs),
            'terminal_run_count' => count($terminalRuns),
            'timed_run_count' => count($timedRuns),
            'sample_item_count' => count($sampleItemKeys),
            'earliest_start' => $earliestStart === null ? null : Carbon::createFromTimestampUTC($earliestStart)->toISOString(),
            'latest_terminal_completion' => $latestCompletion === null
                ? null
                : Carbon::createFromTimestampUTC($latestCompletion)->toISOString(),
            'wall_time_seconds' => $wallTime,
            'items_per_hour' => $itemsPerHour,
            'source_gib_per_hour' => $sourceGiBPerHour,
            'content_hours_per_wall_hour' => $contentHoursPerWallHour,
            'max_overlapping_runs' => $this->runOverlap($runs),
            'runs_missing_timings' => $missingTimingIds,
            'runs_missing_timing_count' => count($missingTimingIds),
            'runs_with_retries' => $retriedRunIds,
            'retried_run_count' => count($retriedRunIds),
            'source_bytes' => $sourceBytes === [] ? null : array_sum($sourceBytes),
            'content_seconds' => $contentSeconds === [] ? null : array_sum($contentSeconds),
        ];

        $aggregate['wall_time'] = $aggregate['wall_time_seconds'];
        $aggregate['source_gib_per_wall_hour'] = $aggregate['source_gib_per_hour'];

        return $aggregate;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, array<string, mixed>>
     */
    private function stepSummaries(array $runs): array
    {
        $grouped = [];

        foreach ($runs as $run) {
            foreach ($run['steps'] as $step) {
                $canonicalStep = (string) $step['canonical_step'];
                $grouped[$canonicalStep] ??= [];
                $grouped[$canonicalStep][] = $step;
            }
        }

        foreach (self::HIGH_COST_STEPS as $step) {
            $grouped[$step] ??= [];
        }

        ksort($grouped);
        $summaries = [];

        foreach ($grouped as $stepName => $stepRows) {
            $activeDurations = array_values(array_filter(
                array_map(
                    static fn (array $step): ?float => is_numeric($step['active_duration_seconds'])
                        ? (float) $step['active_duration_seconds']
                        : null,
                    $stepRows,
                ),
                static fn (?float $duration): bool => $duration !== null && $duration >= 0.0,
            ));
            $queueWaits = array_values(array_filter(
                array_map(
                    static fn (array $step): ?float => is_numeric($step['queue_wait_seconds'])
                        ? (float) $step['queue_wait_seconds']
                        : null,
                    $stepRows,
                ),
                static fn (?float $wait): bool => $wait !== null && $wait >= 0.0,
            ));
            $completedCount = $this->stepStatusCount($stepRows, ProcessingStatus::Completed);
            $failedCount = $this->stepStatusCount($stepRows, ProcessingStatus::Failed);
            $skippedCount = $this->stepStatusCount($stepRows, ProcessingStatus::Skipped);
            $cancelledCount = $this->stepStatusCount($stepRows, ProcessingStatus::Cancelled);
            $coverageCount = count(array_unique(array_map(
                static fn (array $step): string => (string) $step['processing_id'],
                $stepRows,
            )));
            $percentiles = $this->percentiles($activeDurations);
            $waitPercentiles = $this->percentiles($queueWaits);
            $stages = array_values(array_unique(array_map(
                static fn (array $step): string => (string) $step['stage'],
                $stepRows,
            )));

            $summaries[$stepName] = [
                'step' => $stepName,
                'stage' => count($stages) === 1 ? $stages[0] : ($stages === [] ? 'unknown' : 'mixed'),
                'observed_count' => count($stepRows),
                'coverage_count' => $coverageCount,
                'missing_coverage_count' => max(0, count($runs) - $coverageCount),
                'sample_count' => $percentiles['count'],
                'completed_count' => $completedCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'cancelled_count' => $cancelledCount,
                'p50_active_duration_seconds' => $percentiles['p50'],
                'p95_active_duration_seconds' => $percentiles['p95'],
                'max_active_duration_seconds' => $percentiles['max'],
                'active_duration_seconds' => $percentiles,
                'queue_wait_sample_count' => $waitPercentiles['count'],
                'p50_queue_wait_seconds' => $waitPercentiles['p50'],
                'p95_queue_wait_seconds' => $waitPercentiles['p95'],
                'max_queue_wait_seconds' => $waitPercentiles['max'],
                'queue_wait_seconds' => $waitPercentiles,
                'unknown_stage_count' => count(array_filter(
                    $stepRows,
                    static fn (array $step): bool => $step['stage'] === 'unknown',
                )),
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $stepRows
     */
    private function stepStatusCount(array $stepRows, ProcessingStatus $status): int
    {
        return count(array_filter(
            $stepRows,
            static fn (array $step): bool => $step['status'] === $status->value,
        ));
    }

    /**
     * @param  EloquentCollection<int, SermonProcessingStep>  $steps
     * @return list<array<string, mixed>>
     */
    private function stepTimings(EloquentCollection $steps): array
    {
        $rows = [];

        foreach ($steps as $step) {
            $rawStep = $step->step;
            $canonicalStep = ProcessingStep::canonicalize($rawStep) ?? $rawStep;
            $startedAt = $step->started_at;
            $completedAt = $this->isTerminal($step->status) ? $step->completed_at : null;

            $rows[] = [
                'processing_id' => $step->processing_id,
                'step' => $rawStep,
                'canonical_step' => $canonicalStep,
                'stage' => $this->throughput->stageForStep($canonicalStep),
                'status' => $step->status->value,
                'started_at' => $startedAt?->toISOString(),
                'completed_at' => $completedAt?->toISOString(),
                'active_duration_seconds' => $this->secondsBetween($startedAt, $completedAt),
                'queue_wait_seconds' => null,
                'timing_state' => $this->secondsBetween($startedAt, $completedAt) === null
                    ? 'incomplete'
                    : 'complete',
            ];
        }

        usort($rows, function (array $left, array $right): int {
            $leftStart = $this->timestamp($left['started_at'] ?? null);
            $rightStart = $this->timestamp($right['started_at'] ?? null);

            if ($leftStart === null && $rightStart === null) {
                return 0;
            }

            if ($leftStart === null) {
                return 1;
            }

            if ($rightStart === null) {
                return -1;
            }

            return $leftStart <=> $rightStart;
        });

        $precedingCompletedAt = null;

        foreach ($rows as $index => $row) {
            $startedAt = $this->timestamp($row['started_at'] ?? null);

            if ($startedAt !== null && $precedingCompletedAt !== null && $startedAt >= $precedingCompletedAt) {
                $rows[$index]['queue_wait_seconds'] = round($startedAt - $precedingCompletedAt, 6);
            }

            if ($row['status'] === ProcessingStatus::Completed->value && is_string($row['completed_at'])) {
                $completedAt = $this->timestamp($row['completed_at']);

                if ($completedAt !== null) {
                    $precedingCompletedAt = max($precedingCompletedAt ?? $completedAt, $completedAt);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, int>
     */
    private function stageOverlap(array $runs): array
    {
        $intervals = [];

        foreach ($runs as $run) {
            foreach ($run['steps'] as $step) {
                $startedAt = $this->timestamp($step['started_at'] ?? null);
                $completedAt = $this->timestamp($step['completed_at'] ?? null);

                if ($startedAt === null || $completedAt === null || $completedAt <= $startedAt) {
                    continue;
                }

                $stage = (string) $step['stage'];
                $intervals[$stage][] = [(float) $startedAt, (float) $completedAt];
            }
        }

        $overlap = [];

        foreach ($this->throughputStages($intervals) as $stage) {
            $overlap[$stage] = $this->maximumOverlap($intervals[$stage] ?? []);
        }

        return $overlap;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function runOverlap(array $runs): int
    {
        $intervals = [];

        foreach ($runs as $run) {
            $startedAt = $this->timestamp($run['started_at'] ?? null);
            $completedAt = $this->timestamp($run['completed_at'] ?? null);

            if ($startedAt === null || $completedAt === null || $completedAt <= $startedAt) {
                continue;
            }

            $intervals[] = [(float) $startedAt, (float) $completedAt];
        }

        return $this->maximumOverlap($intervals);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $intervals
     */
    private function maximumOverlap(array $intervals): int
    {
        $events = [];

        foreach ($intervals as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }

            $events[] = [$start, 1];
            $events[] = [$end, -1];
        }

        usort($events, static fn (array $left, array $right): int => $left[0] <=> $right[0]
            ?: $left[1] <=> $right[1]);

        $active = 0;
        $maximum = 0;

        foreach ($events as [, $change]) {
            $active += $change;
            $maximum = max($maximum, $active);
        }

        return $maximum;
    }

    /**
     * Per-stage worker width as recorded on the selected runs.
     *
     * Reported from persisted execution profiles rather than current
     * configuration, because a report that reads configuration attributes
     * today's widths to work that ran under yesterday's -- and the calibration
     * this report exists to support is precisely a width change that gets kept
     * or reverted afterwards.
     *
     * `status` is explicit rather than collapsed to a number: `uniform` when
     * every profile agrees, `mixed` when the selection spans more than one width
     * (so no single figure is true of it), and `missing` when no selected run
     * carried a profile at all. `runs_missing_profile` stays visible even when
     * the profiles that do exist agree, so a partly-instrumented selection can
     * never read as fully known.
     *
     * @param  list<array<string, mixed>>  $runReports
     * @param  list<string>  $stages
     * @return array<string, array{status: 'uniform'|'mixed'|'missing', value: int|null, values: list<int>, runs_with_profile: int, runs_missing_profile: int}>
     */
    private function observedWorkerWidths(array $runReports, array $stages): array
    {
        $observed = [];

        foreach ($stages as $stage) {
            $values = [];
            $withProfile = 0;
            $missingProfile = 0;

            foreach ($runReports as $run) {
                $width = data_get($run, "execution_profile.{$stage}.worker_width");

                if (! is_int($width)) {
                    $missingProfile++;

                    continue;
                }

                $withProfile++;
                $values[$width] = $width;
            }

            $values = array_values($values);
            sort($values);

            $observed[$stage] = [
                'status' => match (true) {
                    $values === [] => 'missing',
                    count($values) > 1 => 'mixed',
                    default => 'uniform',
                },
                'value' => count($values) === 1 ? $values[0] : null,
                'values' => $values,
                'runs_with_profile' => $withProfile,
                'runs_missing_profile' => $missingProfile,
            ];
        }

        return $observed;
    }

    /**
     * @param  array<string, list<array{0: float, 1: float}>>  $intervals
     * @return list<string>
     */
    private function throughputStages(array $intervals): array
    {
        $stages = array_keys($this->throughput->configuredWidths());
        $unknown = array_key_exists('unknown', $intervals);

        if ($unknown) {
            $stages[] = 'unknown';
        }

        return $stages;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function sourceBytes(MediaProcessingLog $run, array $metadata): ?int
    {
        $sources = data_get($metadata, 'historic_import.sources');

        if (is_array($sources) && $sources !== []) {
            $total = 0;

            foreach ($sources as $source) {
                if (! is_array($source) || ! is_numeric($source['size'] ?? null)) {
                    return null;
                }

                $size = (float) $source['size'];

                if (! is_finite($size) || $size < 0.0 || $size > PHP_INT_MAX) {
                    return null;
                }

                $total += (int) $size;
            }

            return $total;
        }

        return is_int($run->file_size) && $run->file_size >= 0 ? $run->file_size : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function isMountFailure(MediaProcessingLog $run, array $metadata): bool
    {
        $explicit = data_get($metadata, 'historic_import.mount_failed');

        if (is_bool($explicit)) {
            return $explicit;
        }

        $text = strtolower(implode(' ', array_filter([
            $run->current_step,
            $run->error_message,
            data_get($metadata, 'historic_import.failure_classification'),
        ], is_string(...))));

        return str_contains($text, 'mount')
            || str_contains($text, 'stale drive')
            || str_contains($text, 'source drive');
    }

    /**
     * @return list<string>
     */
    private function classifications(
        ?int $attemptCount,
        bool $reExtraction,
        bool $manualReview,
        bool $mountFailed,
        bool $degraded,
    ): array {
        $classifications = [];

        if ($mountFailed) {
            $classifications[] = 'mount_failed';
        }

        if ($degraded) {
            $classifications[] = 'degraded_completion';
        }

        if ($attemptCount !== null && $attemptCount > 1) {
            $classifications[] = 'retried';
        }

        if ($reExtraction) {
            $classifications[] = 'manual_re_extraction';
        }

        if ($manualReview) {
            $classifications[] = 'manual_review';
        }

        return $classifications;
    }

    /** @param array<string, mixed> $run */
    private function isCleanFirstAttempt(array $run): bool
    {
        return $run['attempt_count'] === 1
            && $run['terminal_disposition'] === 'completed'
            && $run['is_re_extraction'] === false
            && $run['is_manual_review'] === false
            && $run['is_mount_failed'] === false
            /*
             * A degraded completion is not clean work at any speed. It reached 'completed' by
             * substituting empty analysis for a failed provider call, so counting it in the clean
             * throughput sample makes a pass that analysed nothing look like the fastest one yet —
             * exactly the inversion the 2026-09-02 pass produced.
             */
            && $run['is_degraded_completion'] === false;
    }

    private function terminalDisposition(MediaProcessingLog $run): string
    {
        if (! $this->isTerminal($run->status)) {
            return 'in_progress';
        }

        if ($run->status === ProcessingStatus::Failed && $run->current_step === 'manual_review_required') {
            return 'manual_review';
        }

        if ($run->status === ProcessingStatus::Completed && $run->isDegradedCompletion()) {
            return 'degraded';
        }

        return $run->status->value;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function disposition(array $runs): string
    {
        if ($runs === []) {
            return 'not_dispatched';
        }

        if (in_array('in_progress', array_column($runs, 'terminal_disposition'), true)) {
            return 'in_progress';
        }

        $dispositions = array_values(array_unique(array_column($runs, 'terminal_disposition')));

        if (in_array('manual_review', $dispositions, true) && count($dispositions) > 1) {
            return 'mixed_terminal';
        }

        if (count($dispositions) > 1) {
            return 'mixed_terminal';
        }

        return $dispositions[0] ?? 'in_progress';
    }

    /**
     * API timings are included only when a producer has durably recorded them
     * with the run. End-to-end or step durations are never used as a proxy.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<float>
     */
    private function responseTimeSamples(array $metadata): array
    {
        $times = data_get($metadata, 'historic_import.api_response_times_ms')
            ?? data_get($metadata, 'api_response_times_ms');

        if (is_numeric($times)) {
            $time = (float) $times;

            return is_finite($time) && $time >= 0.0 ? [$time] : [];
        }

        if (! is_array($times)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $time): ?float => is_numeric($time) && (float) $time >= 0.0
                    ? (float) $time
                    : null,
                $times,
            ),
            static fn (?float $time): bool => $time !== null && is_finite($time),
        ));
    }

    private function timestamp(mixed $value): ?float
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $timestamp = (float) Carbon::parse($value)->format('U.u');
        } catch (\Throwable) {
            return null;
        }

        return is_finite($timestamp) ? $timestamp : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $summaries
     * @return list<string>
     */
    private function missingStepCoverage(array $summaries): array
    {
        return array_values(array_map(
            static fn (array $summary): string => (string) $summary['step'],
            array_filter(
                $summaries,
                static fn (array $summary): bool => $summary['missing_coverage_count'] > 0,
            ),
        ));
    }

    private function itemKey(MediaProcessingLog $run): string
    {
        $itemKey = data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key');

        return is_string($itemKey) ? $itemKey : '';
    }

    private function isTerminal(ProcessingStatus $status): bool
    {
        return in_array($status, [
            ProcessingStatus::Completed,
            ProcessingStatus::Skipped,
            ProcessingStatus::Failed,
            ProcessingStatus::Cancelled,
        ], true);
    }

    private function positiveFloat(?float $value): ?float
    {
        return $value !== null && is_finite($value) && $value > 0.0 ? $value : null;
    }

    private function secondsBetween(
        ?Carbon $start,
        ?Carbon $end,
    ): ?float {
        if (! $start instanceof Carbon || ! $end instanceof Carbon) {
            return null;
        }

        $seconds = (float) $end->format('U.u') - (float) $start->format('U.u');

        return is_finite($seconds) && $seconds >= 0.0 ? round($seconds, 6) : null;
    }

    /** @param list<float> $sorted */
    private function nearestRank(array $sorted, float $quantile): float
    {
        $rank = (int) ceil($quantile * count($sorted));

        return $sorted[max(1, $rank) - 1];
    }

    /**
     * @param  list<string>  $itemKeys
     * @return list<string>
     */
    private function normaliseItemKeys(array $itemKeys): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $itemKey): string => trim($itemKey),
                $itemKeys,
            ),
            static fn (string $itemKey): bool => $itemKey !== '',
        )));
    }
}
