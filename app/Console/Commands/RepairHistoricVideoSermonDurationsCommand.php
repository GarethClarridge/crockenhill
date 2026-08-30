<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoSermonDurationRepair;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Repair stale duration metadata from completed historic extraction plans.
 *
 * Deletion trigger: Delete after IC8 closeout once the Phase 7 duration repair
 * and its retained evidence are complete.
 */
class RepairHistoricVideoSermonDurationsCommand extends Command
{
    protected $signature = 'historic-import:repair-video-sermon-durations
                            {--operation= : Exact immutable historic operation}
                            {--processing-id=* : Exact completed processing ID; repeat for every run}
                            {--apply : Apply the duration-only repair (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Repair historic sermon durations from their recorded extraction plans';

    public function handle(HistoricVideoSermonDurationRepair $repair): int
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
                ['Processing ID', 'Sermon', 'Current', 'Repaired', 'Disposition'],
                array_map(static fn (array $entry): array => [
                    $entry['processing_id'],
                    (string) $entry['sermon']->id,
                    $entry['current_duration'] === null ? 'null' : (string) $entry['current_duration'],
                    (string) $entry['repaired_duration'],
                    $entry['disposition'],
                ], $entries),
            );

            if (! $apply) {
                $this->warn('DRY RUN: no sermon metadata was changed. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($operation, $entries);
            $this->info("Repaired {$totals['repaired']} sermon duration(s); already repaired: {$totals['already_repaired']}.");

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

        $operation = HistoricImportOperation::query()->where('operation_id', trim($operationId))->first();

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
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $processingIds),
        ));

        if ($processingIds === [] || count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Name every target processing ID exactly once.');
        }

        return $processingIds;
    }
}
