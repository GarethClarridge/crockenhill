<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoPassMeasures;
use App\Services\HistoricMedia\HistoricVideoPassPerformance;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video dispatcher and after the final operation report is retained.
 */
class HistoricVideoPassStatusCommand extends Command
{
    protected $signature = 'historic-import:video-pass-status
                            {--operation= : Immutable historic import operation id}
                            {--only= : Comma-separated manifest item keys in this pass}
                            {--measures : Also report the operation\'s custody byte measures}
                            {--performance : Also report durable run, step, stage and usage timings}
                            {--performance-report= : Create a new JSON performance report at an absolute path}';

    protected $description = 'Report database-owned status for one historic-video pass without reading workers or storage';

    public function handle(
        HistoricVideoPassStatus $status,
        HistoricVideoPassMeasures $measures,
        HistoricVideoPassPerformance $performance,
    ): int {
        $operationId = $this->stringOption('operation');
        $itemKeys = $this->itemKeys($this->option('only'));
        $performancePath = $this->stringOption('performance-report');

        if ($performancePath !== null && ! $this->option('performance')) {
            $this->error('--performance-report requires --performance.');

            return self::FAILURE;
        }

        if ($performancePath !== null && ! str_starts_with($performancePath, '/')) {
            $this->error('--performance-report must be an absolute path.');

            return self::FAILURE;
        }

        if ($operationId === null || $itemKeys === []) {
            $this->error('Both --operation and a non-empty --only manifest-key list are required.');

            return self::FAILURE;
        }

        $operation = HistoricImportOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            $this->error("Historic import operation {$operationId} does not exist.");

            return self::FAILURE;
        }

        $report = $status->report($operation, $itemKeys);

        $this->table(
            ['Manifest item', 'Disposition', 'Processing IDs', 'Current stage(s)'],
            array_map(static fn (array $item): array => [
                $item['item_key'],
                $item['disposition'],
                implode(', ', $item['processing_ids']) ?: '—',
                implode(', ', $item['stages']) ?: '—',
            ], $report),
        );

        $dispositions = collect($report)
            ->countBy('disposition')
            ->sortKeys()
            ->map(static fn (int $count, string $disposition): string => "{$disposition}: {$count}")
            ->implode(', ');

        $this->line("Database-owned pass status — {$dispositions}.");

        $this->reportDegraded($report);

        $this->reportAlerts($status->alerts($operation, $itemKeys));

        if ($this->option('measures')) {
            $this->reportMeasures($measures->report($operation, $itemKeys));
        }

        if ($this->option('performance')) {
            $performanceReport = $performance->report($operation, $itemKeys);
            $this->reportPerformance($performanceReport);

            if ($performancePath !== null && ! $this->writePerformanceReport($performanceReport, $performancePath)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Names the identities whose runs completed with substituted analysis.
     *
     * The disposition table already shows `degraded`, but a pass is read by scanning the summary
     * line, and a degraded run is the one outcome that looks like success while containing none of
     * the work. Phase 8's exit gate treats it as unresolved, so it needs saying in words: the
     * 2026-09-02 pass banked six of these and no report mentioned them at all.
     *
     * @param  list<array{item_key:string, disposition:string, processing_ids:list<string>, stages:list<string>}>  $report
     */
    private function reportDegraded(array $report): void
    {
        $degraded = array_values(array_filter(
            $report,
            static fn (array $item): bool => $item['disposition'] === 'degraded',
        ));

        if ($degraded === []) {
            return;
        }

        $this->warn(sprintf(
            '%d run(s) completed with substituted analysis and do NOT count as completed for the pass gate:',
            count($degraded),
        ));

        foreach ($degraded as $item) {
            $this->line(sprintf(
                '  %s (%s) — re-analyse from the surviving transcript before release',
                $item['item_key'],
                implode(', ', $item['processing_ids']) ?: 'no processing id',
            ));
        }
    }

    /**
     * Surfaces every historic alert already on disk for this pass's runs — the
     * only durable record of why a run stopped, since historic notification_mode
     * is external_disabled by construction. Nothing read these before this
     * command did: a 64-alert backlog from the canary was invisible until now.
     *
     * @param  array{
     *     by_kind: list<array{kind:string, severity:string, count:int}>,
     *     items: list<array{item_key:string, kind:string, severity:string, reason:string, recorded_at:string}>
     * }  $alerts
     */
    private function reportAlerts(array $alerts): void
    {
        if ($alerts['items'] === []) {
            return;
        }

        $this->newLine();
        $this->line('Historic import alerts (no email is sent for this lane — this command is the only reader):');
        $this->table(
            ['Kind', 'Severity', 'Count'],
            array_map(
                static fn (array $row): array => [$row['kind'], $row['severity'], (string) $row['count']],
                $alerts['by_kind'],
            ),
        );
        $this->table(
            ['Manifest item', 'Kind', 'Severity', 'Reason'],
            array_map(
                static fn (array $item): array => [$item['item_key'], $item['kind'], $item['severity'], $item['reason']],
                $alerts['items'],
            ),
        );
    }

    /** @param array<string, mixed> $report */
    private function reportPerformance(array $report): void
    {
        /** @var array<string, mixed> $allRuns */
        $allRuns = $report['all_runs'];
        /** @var array<string, mixed> $cleanFirstAttempt */
        $cleanFirstAttempt = $report['clean_first_attempt'];
        /** @var array<string, int> $configuredWidths */
        $configuredWidths = $report['current_configured_worker_widths'];
        /** @var array<string, array<string, mixed>> $observedWidths */
        $observedWidths = $report['observed_worker_widths'];

        $this->newLine();
        $this->line('Database-owned historic-video performance — canonical run and step timestamps.');
        $this->table(
            ['Aggregate', 'Runs', 'Wall time', 'Items/hour', 'Source GiB/hour', 'Content hours/wall hour', 'Max overlap'],
            [
                $this->performanceAggregateRow('all_runs', $allRuns),
                $this->performanceAggregateRow('clean_first_attempt', $cleanFirstAttempt),
            ],
        );
        $this->table(
            ['Stage', 'Observed width', 'Runs with profile', 'Runs missing profile', 'Currently configured'],
            array_map(
                static fn (string $stage): array => [
                    $stage,
                    match ($observedWidths[$stage]['status']) {
                        'uniform' => (string) $observedWidths[$stage]['value'],
                        'mixed' => 'mixed ('.implode(', ', $observedWidths[$stage]['values']).')',
                        default => 'unknown',
                    },
                    (string) $observedWidths[$stage]['runs_with_profile'],
                    (string) $observedWidths[$stage]['runs_missing_profile'],
                    (string) ($configuredWidths[$stage] ?? '?'),
                ],
                array_keys($observedWidths),
            ),
        );
        $this->line('Observed widths come from the execution profile persisted with each selected run; the configured column is this machine now and does not describe completed work.');
        $this->line(sprintf(
            'Run evidence: %d missing timing run(s); %d run(s) with retries. The clean aggregate excludes retries, re-extractions, manual review and mount-failed runs.',
            $allRuns['runs_missing_timing_count'],
            $allRuns['retried_run_count'],
        ));

        /** @var array<string, array<string, mixed>> $steps */
        $steps = $report['step_summary'];
        $stepRows = [];

        foreach ($steps as $step) {
            $stepRows[] = [
                $step['step'],
                $step['stage'],
                $step['sample_count'],
                $step['completed_count'].'/'.$step['failed_count'].'/'.$step['skipped_count'],
                $this->seconds($step['p50_active_duration_seconds']),
                $this->seconds($step['p95_active_duration_seconds']),
                $this->seconds($step['max_active_duration_seconds']),
                $this->seconds($step['p95_queue_wait_seconds']),
            ];
        }

        if ($stepRows !== []) {
            $this->newLine();
            $this->table(
                ['Step', 'Stage', 'Samples', 'Completed/failed/skipped', 'p50 active', 'p95 active', 'Max active', 'p95 queue/wait'],
                $stepRows,
            );
        }

        /** @var array<string, mixed> $usage */
        $usage = $report['usage'];
        $this->line(sprintf(
            'Usage evidence: %d request(s), %d call(s), %d input token(s), %d output token(s); API response-time samples: %d (%s).',
            $usage['request_count'],
            $usage['call_count'],
            $usage['input_tokens'],
            $usage['output_tokens'],
            $usage['api_response_time_summary_ms']['count'],
            $usage['api_response_time_source'],
        ));
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array{string, string, string, string, string, string, string}
     */
    private function performanceAggregateRow(string $name, array $aggregate): array
    {
        return [
            $name,
            (string) $aggregate['run_count'],
            $this->seconds($aggregate['wall_time_seconds']),
            $this->rate($aggregate['items_per_hour']),
            $this->rate($aggregate['source_gib_per_hour']),
            $this->rate($aggregate['content_hours_per_wall_hour']),
            (string) $aggregate['max_overlapping_runs'],
        ];
    }

    private function seconds(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 3).' s' : '—';
    }

    private function rate(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 3) : '—';
    }

    /**
     * Create a report only at a new absolute path. A performance artifact is
     * evidence, so an existing path is never replaced.
     *
     * @param  array<string, mixed>  $report
     */
    private function writePerformanceReport(array $report, string $path): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error("Performance report directory is not writable: {$directory}");

            return false;
        }

        if (file_exists($path) || is_link($path)) {
            $this->error("Refusing to overwrite existing performance report: {$path}");

            return false;
        }

        try {
            $contents = CanonicalJson::encode($report).PHP_EOL;
        } catch (Throwable $exception) {
            $this->error('Unable to encode performance report: '.$exception->getMessage());

            return false;
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            $this->error("Unable to create performance report: {$path}");

            return false;
        }

        $complete = false;

        try {
            $offset = 0;
            $length = strlen($contents);

            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));

                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Unable to write performance report: {$path}");
                }

                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new \RuntimeException("Unable to flush performance report: {$path}");
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new \RuntimeException("Unable to flush performance report: {$path}");
            }

            $complete = true;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return false;
        } finally {
            fclose($handle);

            if (! $complete) {
                @unlink($path);
            }
        }

        @chmod($path, 0600);
        $this->info("Performance report: {$path}");

        return true;
    }

    /**
     * Print the Phase 5 custody measures, plus the figures that make residue
     * and review-source retention readable.
     *
     * @param  array{
     *     runs: int,
     *     runs_reporting_promotion: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     peak_working_bytes: int,
     *     staging_retained_bytes: int,
     *     review_source_retained_bytes: int,
     *     staging_accounted_bytes: int,
     *     unexplained_residue_bytes: int,
     *     quarantine_bytes: int
     * }  $measures
     */
    private function reportMeasures(array $measures): void
    {
        $this->newLine();
        $this->table(
            ['Measure', 'Bytes', 'GiB'],
            [
                ['Peak working (sampled at promotion)', $measures['peak_working_bytes'], $this->gib($measures['peak_working_bytes'])],
                ['Promoted to private quarantine', $measures['promoted_bytes'], $this->gib($measures['promoted_bytes'])],
                ['Retained on staging now', $measures['staging_retained_bytes'], $this->gib($measures['staging_retained_bytes'])],
                ['Retained for unresolved review', $measures['review_source_retained_bytes'], $this->gib($measures['review_source_retained_bytes'])],
                ['Unexplained residue', $measures['unexplained_residue_bytes'], $this->gib($measures['unexplained_residue_bytes'])],
                ['— of which accounted for by runs', $measures['staging_accounted_bytes'], $this->gib($measures['staging_accounted_bytes'])],
                ['Reclaimed after promotion', $measures['reclaimed_bytes'], $this->gib($measures['reclaimed_bytes'])],
                ['Held in quarantine now', $measures['quarantine_bytes'], $this->gib($measures['quarantine_bytes'])],
            ],
        );

        $this->line(sprintf(
            '%d of %d operation run(s) reported a promotion. Peak working bytes is the maximum of those samples, not a continuous gauge.',
            $measures['runs_reporting_promotion'],
            $measures['runs'],
        ));
    }

    private function gib(int $bytes): string
    {
        return number_format($bytes / (1024 ** 3), 2);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function itemKeys(mixed $option): array
    {
        if (! is_string($option)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $option)),
            static fn (string $key): bool => $key !== '',
        )));
    }
}
