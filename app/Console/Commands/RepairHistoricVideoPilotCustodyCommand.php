<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoPilotCustodyRepair;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Repair the direct historic-video pilot's published rows whose durable assets
 * were left on working staging.
 *
 * This is deliberately a one-shot, exact-membership command. It has no broad
 * query mode: an operator names the owning operation and every processing ID,
 * reviews the dry-run table, then opts into the private custody transition.
 *
 * Deletion trigger: Delete after the historic-video operation reaches IC8
 * closeout and the pilot custody repair is retained in its closeout evidence.
 */
class RepairHistoricVideoPilotCustodyCommand extends Command
{
    protected $signature = 'historic-import:repair-video-pilot-custody
                            {--operation= : Exact immutable historic operation that owns the pilot runs}
                            {--processing-id=* : Exact completed pilot processing ID; repeat for every run}
                            {--apply : Quarantine and promote the verified assets (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Repair direct historic-video pilot asset custody into private quarantine';

    public function handle(HistoricVideoPilotCustodyRepair $repair): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $repair->inspect($operation, $processingIds);

            $this->table(
                ['Processing ID', 'Sermon', 'Assets', 'Staged bytes', 'Disposition'],
                array_map(
                    static fn (array $entry): array => [
                        $entry['processing_id'],
                        (string) $entry['sermon']->id,
                        (string) $entry['asset_count'],
                        (string) $entry['staged_bytes'],
                        $entry['disposition'],
                    ],
                    $entries,
                ),
            );

            if (! $apply) {
                $this->warn('DRY RUN: no database rows or assets were changed. Re-run with --apply --yes to repair this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($operation, $entries);

            if ($totals['repaired'] === 0) {
                $this->info('All selected pilot sermons are already repaired; no changes were written.');

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Promoted %d pilot run(s), %d asset(s) (%d bytes); reclaimed %d staging byte(s).',
                $totals['repaired'],
                $totals['assets_promoted'],
                $totals['promoted_bytes'],
                $totals['reclaimed_bytes'],
            ));

            if ($totals['already_repaired'] > 0) {
                $this->line("Already repaired: {$totals['already_repaired']}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function operation(): HistoricImportOperation
    {
        $operationId = $this->option('operation');

        if (! is_string($operationId) || trim($operationId) === '') {
            throw new RuntimeException('The owning historic operation is required.');
        }

        $operation = HistoricImportOperation::query()
            ->where('operation_id', trim($operationId))
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('The named historic operation does not exist.');
        }

        return $operation;
    }

    /** @return list<string> */
    private function processingIds(): array
    {
        $processingIds = $this->option('processing-id');

        $processingIds = array_values(array_filter(
            $processingIds,
            static fn (mixed $processingId): bool => is_string($processingId) && trim($processingId) !== '',
        ));

        if ($processingIds === []) {
            throw new RuntimeException('At least one exact completed pilot --processing-id is required.');
        }

        $processingIds = array_map(trim(...), $processingIds);

        if (count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Each pilot --processing-id must be listed exactly once.');
        }

        return $processingIds;
    }
}
