<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricRunExclusion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Record an operator's decision that a historic run is excluded, so the pass
 * report shows why the item was set aside rather than leaving it in a review
 * hold nobody is coming back to.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further exclusion decisions can be recorded against it.
 */
class ExcludeHistoricRunCommand extends Command
{
    protected $signature = 'historic-import:exclude-run
                            {--operation= : Exact immutable historic operation that owns the runs}
                            {--processing-id=* : Exact processing ID to exclude; repeat for every run}
                            {--reason=no_sermon_in_source : Recorded exclusion reason}
                            {--note= : The operator note that justifies the exclusion}
                            {--apply : Write the exclusion (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Record an operator decision that a historic run is excluded and cannot be processed';

    public function handle(HistoricRunExclusion $exclusion): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $reason = (string) $this->option('reason');
            $note = (string) ($this->option('note') ?? '');
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $exclusion->inspect($operation, $processingIds, $reason);

            $this->table(
                ['Processing ID', 'Manifest item', 'Disposition now', 'Becomes'],
                array_map(static fn (array $entry): array => [
                    $entry['run']->processing_id,
                    $entry['item_key'],
                    $entry['disposition_now'],
                    $entry['already_excluded'] ? 'excluded (unchanged)' : 'excluded',
                ], $entries),
            );

            $this->line("Reason: {$reason}");
            $this->line('Note:   '.($note === '' ? '(none given)' : $note));
            $this->line('An exclusion is terminal and is not revisited. The run keeps its own status; only the disposition changes.');

            if (! $apply) {
                $this->warn('DRY RUN: no exclusion was written. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $result = $exclusion->apply($operation, $entries, $reason, $note);

            $this->info("Excluded {$result['excluded']} run(s); already excluded: {$result['already_excluded']}.");

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
            throw new RuntimeException('--operation is required and must name the exact immutable operation.');
        }

        $operation = HistoricImportOperation::query()
            ->where('operation_id', trim($operationId))
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("No historic import operation exists for [{$operationId}].");
        }

        return $operation;
    }

    /** @return list<string> */
    private function processingIds(): array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('processing-id');

        $processingIds = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $raw,
        ), static fn (string $value): bool => $value !== ''));

        if ($processingIds === []) {
            throw new RuntimeException('At least one --processing-id is required.');
        }

        if (count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('The --processing-id selection repeats a run; name each exactly once.');
        }

        return $processingIds;
    }
}
