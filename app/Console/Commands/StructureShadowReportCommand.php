<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;

/**
 * Aggregates the service_structure_shadow metadata that shadow-mode runs
 * accumulate on every processed livestream — the zero-effort evidence stream
 * behind the promotion decision.
 */
class StructureShadowReportCommand extends Command
{
    protected $signature = 'structure:shadow-report
                            {--since= : Only include runs created on or after this date (Y-m-d)}
                            {--processing-id=* : Only include these processing ids}
                            {--report= : Write the full JSON report to this path}';

    protected $description = 'Aggregate shadow-mode LLM structure proposals into agreement metrics against the heuristic pipeline';

    public function handle(): int
    {
        $query = MediaProcessingLog::query()
            ->whereNotNull('processing_metadata->service_structure_shadow')
            ->orderBy('created_at');

        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            $query->where('created_at', '>=', $since);
        }

        $processingIds = array_values(array_filter((array) $this->option('processing-id')));

        if ($processingIds !== []) {
            $query->whereIn('processing_id', $processingIds);
        }

        $runs = [];

        foreach ($query->get() as $log) {
            $shadow = $log->processing_metadata?->toArray()['service_structure_shadow'] ?? null;

            if (! is_array($shadow)) {
                continue;
            }

            $runs[] = $this->summariseRun($log, $shadow);
        }

        if ($runs === []) {
            $this->warn('No shadow-mode runs found. Set SERVICE_STRUCTURE_MODE=shadow and process a livestream first.');

            return self::SUCCESS;
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'runs' => $runs,
            'aggregate' => $this->aggregate($runs),
        ];

        $this->renderReport($report);
        $this->writeReport($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $shadow
     * @return array<string, mixed>
     */
    private function summariseRun(MediaProcessingLog $log, array $shadow): array
    {
        if (isset($shadow['error'])) {
            return [
                'processing_id' => $log->processing_id,
                'generated_at' => $shadow['generated_at'] ?? null,
                'error' => (string) $shadow['error'],
            ];
        }

        $diff = is_array($shadow['diff'] ?? null) ? $shadow['diff'] : [];
        $sections = is_array($shadow['sections'] ?? null) ? $shadow['sections'] : [];

        $flaggedSections = count(array_filter(
            $sections,
            static fn (mixed $section): bool => is_array($section) && (bool) ($section['needs_manual_review'] ?? false)
        ));

        return [
            'processing_id' => $log->processing_id,
            'generated_at' => $shadow['generated_at'] ?? null,
            'error' => null,
            'passed_validation' => (bool) ($shadow['passed_validation'] ?? false),
            'hard_failure_codes' => array_values(array_unique(array_column(
                is_array($shadow['hard_failures'] ?? null) ? $shadow['hard_failures'] : [],
                'code'
            ))),
            'type_sequence_match' => $diff['type_sequence_match'] ?? null,
            'sermon' => $diff['sermon'] ?? null,
            'oos_anchoring' => $diff['oos_anchoring'] ?? null,
            'flagged_section_count' => $flaggedSections,
            'would_have_flagged' => $flaggedSections > 0 || ! (bool) ($shadow['passed_validation'] ?? false),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function aggregate(array $runs): array
    {
        $clean = array_values(array_filter($runs, static fn (array $run): bool => ($run['error'] ?? null) === null));

        $rate = static fn (int $count, int $total): ?float => $total === 0 ? null : round($count / $total, 3);
        $mean = static fn (array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 3);

        $sermonDiffs = array_values(array_filter(array_column($clean, 'sermon'), 'is_array'));
        $absStartDeltas = array_map(static fn (array $sermon): float => abs((float) $sermon['start_delta']), $sermonDiffs);
        $absEndDeltas = array_map(static fn (array $sermon): float => abs((float) $sermon['end_delta']), $sermonDiffs);

        $within = static fn (float $threshold): int => count(array_filter(
            $sermonDiffs,
            static fn (array $sermon): bool => abs((float) $sermon['start_delta']) <= $threshold
                && abs((float) $sermon['end_delta']) <= $threshold
        ));

        $failureCodes = [];

        foreach ($clean as $run) {
            foreach ($run['hard_failure_codes'] ?? [] as $code) {
                $failureCodes[$code] = ($failureCodes[$code] ?? 0) + 1;
            }
        }

        $anchoring = array_values(array_filter(array_column($clean, 'oos_anchoring'), 'is_array'));
        $agreements = array_sum(array_column($anchoring, 'agreements'));
        $disagreements = array_sum(array_column($anchoring, 'disagreements'));

        return [
            'run_count' => count($runs),
            'error_count' => count($runs) - count($clean),
            'passed_validation_rate' => $rate(count(array_filter($clean, static fn (array $run): bool => (bool) $run['passed_validation'])), count($clean)),
            'type_sequence_match_rate' => $rate(count(array_filter($clean, static fn (array $run): bool => (bool) ($run['type_sequence_match'] ?? false))), count($clean)),
            'sermon' => [
                'compared' => count($sermonDiffs),
                'within_15s_rate' => $rate($within(15.0), count($sermonDiffs)),
                'within_30s_rate' => $rate($within(30.0), count($sermonDiffs)),
                'mean_abs_start_delta' => $mean($absStartDeltas),
                'mean_abs_end_delta' => $mean($absEndDeltas),
            ],
            'oos_anchoring' => [
                'agreements' => $agreements,
                'disagreements' => $disagreements,
                'agreement_rate' => $rate($agreements, $agreements + $disagreements),
            ],
            'would_have_flagged_count' => count(array_filter($clean, static fn (array $run): bool => (bool) $run['would_have_flagged'])),
            'hard_failure_codes' => $failureCodes,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $rows = [];

        foreach ($report['runs'] as $run) {
            if (($run['error'] ?? null) !== null) {
                $rows[] = [$run['processing_id'], 'ERROR: '.$run['error'], '', '', ''];

                continue;
            }

            $sermon = $run['sermon'];
            $rows[] = [
                $run['processing_id'],
                $run['passed_validation'] ? 'passed' : implode(', ', $run['hard_failure_codes']),
                $run['type_sequence_match'] === null ? '—' : ($run['type_sequence_match'] ? 'yes' : 'no'),
                is_array($sermon)
                    ? sprintf('%+.1fs / %+.1fs', $sermon['start_delta'], $sermon['end_delta'])
                    : '—',
                $run['would_have_flagged'] ? 'yes' : 'no',
            ];
        }

        $this->table(['Run', 'Validation', 'Types agree', 'Sermon Δstart/Δend', 'Would flag'], $rows);

        $aggregate = $report['aggregate'];
        $this->info(sprintf(
            'Aggregate: %d run(s) (%d errored), sermon within 30s: %s, type agreement: %s, would-have-flagged: %d',
            $aggregate['run_count'],
            $aggregate['error_count'],
            $aggregate['sermon']['within_30s_rate'] === null ? 'n/a' : sprintf('%.0f%%', $aggregate['sermon']['within_30s_rate'] * 100),
            $aggregate['type_sequence_match_rate'] === null ? 'n/a' : sprintf('%.0f%%', $aggregate['type_sequence_match_rate'] * 100),
            $aggregate['would_have_flagged_count'],
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): void
    {
        $reportPath = $this->option('report');

        if (! is_string($reportPath) || $reportPath === '') {
            return;
        }

        $directory = dirname($reportPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Report written to {$reportPath}");
    }
}
