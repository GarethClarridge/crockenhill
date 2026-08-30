<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use Illuminate\Console\Command;

/**
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video dispatcher and after the final operation report is retained.
 */
class HistoricVideoPassStatusCommand extends Command
{
    protected $signature = 'historic-import:video-pass-status
                            {--operation= : Immutable historic import operation id}
                            {--only= : Comma-separated manifest item keys in this pass}';

    protected $description = 'Report database-owned status for one historic-video pass without reading workers or storage';

    public function handle(HistoricVideoPassStatus $status): int
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

        return self::SUCCESS;
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
