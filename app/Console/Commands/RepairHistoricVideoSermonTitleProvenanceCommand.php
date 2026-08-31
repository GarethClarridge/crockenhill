<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoSermonTitleProvenanceRepair;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Record proven `Generated` title provenance on historic sermons created before
 * the column existed, so banked analysis can replace a filename title the legacy
 * placeholder recogniser cannot safely match.
 *
 * Deletion trigger: Delete after IC8 closeout once the Phase 7 title repair and
 * its retained evidence are complete.
 */
class RepairHistoricVideoSermonTitleProvenanceCommand extends Command
{
    protected $signature = 'historic-import:repair-video-sermon-title-provenance
                            {--operation= : Exact immutable historic operation}
                            {--processing-id=* : Exact completed processing ID; repeat for every run}
                            {--apply : Apply the provenance-only repair (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Record proven generated title provenance on historic sermons';

    public function handle(HistoricVideoSermonTitleProvenanceRepair $repair): int
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
                ['Processing ID', 'Sermon', 'Current title', 'Regenerated title', 'Provenance', 'Disposition'],
                array_map(static fn (array $entry): array => [
                    $entry['processing_id'],
                    (string) $entry['sermon']->id,
                    $entry['current_title'],
                    $entry['generated_title'],
                    $entry['current_provenance'],
                    $entry['disposition'],
                ], $entries),
            );

            $this->line('Only a row whose stored title exactly reproduces its regenerated filename title is recorded as generated; everything else is refused and left null.');

            if (! $apply) {
                $this->warn('DRY RUN: no sermon metadata was changed. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($operation, $entries);
            $this->info(
                "Recorded {$totals['recorded']} generated title provenance value(s); "
                ."already recorded: {$totals['already_recorded']}; refused: {$totals['refused']}."
            );

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
