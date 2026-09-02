<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricRunRetirement;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Withdraw a historic run whose source has been replaced, so its identity can be
 * imported again from the new source instead of being blocked forever by the
 * result of the old one.
 *
 * Use this and not `historic-import:exclude-run` when the recording changed. An
 * exclusion is terminal — it says no run of this source can succeed — and it
 * deliberately leaves the run's own status alone, so it never unblocks a date.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further source replacements can arrive.
 */
class RetireHistoricRunCommand extends Command
{
    protected $signature = 'historic-import:retire-run
                            {--operation= : Exact immutable historic operation that owns the runs}
                            {--processing-id=* : Exact processing ID to retire; repeat for every run}
                            {--note= : The operator note that justifies the retirement}
                            {--apply : Write the retirement (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Withdraw a historic run whose source was replaced so its identity can be imported again';

    public function handle(HistoricRunRetirement $retirement): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $note = (string) ($this->option('note') ?? '');
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $retirement->inspect($operation, $processingIds);

            $this->table(
                ['Processing ID', 'Manifest item', 'Status now', 'Sermon', 'Assets', 'Sections', 'Becomes'],
                array_map(static function (array $entry): array {
                    $sermon = $entry['sermon'];

                    return [
                        $entry['run']->processing_id,
                        $entry['item_key'],
                        $entry['status_now'],
                        $sermon instanceof Sermon ? "#{$sermon->id} ".$sermon->slug : '(none)',
                        count($entry['assets']).' file(s), '.self::formatBytes(
                            array_sum(array_column($entry['assets'], 'bytes')),
                        ),
                        (string) $entry['sections'],
                        $entry['already_retired'] ? 'retired (unchanged)' : 'retired',
                    ];
                }, $entries),
            );

            foreach ($entries as $entry) {
                foreach ($entry['assets'] as $asset) {
                    $this->line("  {$entry['run']->processing_id}  {$asset['role']}: {$asset['disk']}:{$asset['path']}");
                }
            }

            $this->line('Note: '.($note === '' ? '(none given)' : $note));
            $this->line('Retirement withdraws the run: its sections drop out of every reader, its sermon row is');
            $this->line('deleted and the sermon assets move to a superseded prefix on the same disk. The identity');
            $this->line('becomes dispatchable again. Nothing published can be retired.');

            if (! $apply) {
                $this->warn('DRY RUN: nothing was written and no file was moved. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $result = $retirement->apply($operation, $entries, $note);

            $this->info(sprintf(
                'Retired %d run(s); already retired: %d; sermons withdrawn: %d; assets relocated: %d.',
                $result['retired'],
                $result['already_retired'],
                $result['sermons_deleted'],
                $result['assets_relocated'],
            ));

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

    /**
     * The unit index counts divisions, so `B` has to occupy index zero: an
     * array starting at `KiB` reports a value one unit too large, which on a
     * sermon's assets reads as hundreds of gigabytes instead of megabytes.
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : (string) round($value, 1)).' '.$units[$unit];
    }
}
