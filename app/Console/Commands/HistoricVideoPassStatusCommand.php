<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoPassMeasures;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use Illuminate\Console\Command;

/**
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video dispatcher and after the final operation report is retained.
 */
class HistoricVideoPassStatusCommand extends Command
{
    protected $signature = 'historic-import:video-pass-status
                            {--operation= : Immutable historic import operation id}
                            {--only= : Comma-separated manifest item keys in this pass}
                            {--measures : Also report the operation\'s four custody byte measures}';

    protected $description = 'Report database-owned status for one historic-video pass without reading workers or storage';

    public function handle(HistoricVideoPassStatus $status, HistoricVideoPassMeasures $measures): int
    {
        $operationId = $this->stringOption('operation');
        $itemKeys = $this->itemKeys($this->option('only'));

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

        if ($this->option('measures')) {
            $this->reportMeasures($measures->report($operation, $itemKeys));
        }

        return self::SUCCESS;
    }

    /**
     * Print the four measures Phase 5's exit gate names, plus the two figures
     * that make the fourth readable.
     *
     * @param  array{
     *     runs: int,
     *     runs_reporting_promotion: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     peak_working_bytes: int,
     *     staging_retained_bytes: int,
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
