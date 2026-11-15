<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\RetiredRunPurge;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete what `historic-import:retire-run` leaves behind for a retired run — the staged source,
 * the service transcript and every derived artifact, the section rows with their extracted media,
 * the superseded sermon files and the run-derived text on the church service — so that no stored
 * copy of the run's content remains. The run row stays as the tombstone, carrying a `purge`
 * record beside its `retirement` record.
 *
 * Dry run by default: the inventory lists every file, row and JSON fragment, exactly, and
 * writes nothing. Retire-run also accepts weekly runs, and so does this.
 *
 * This is a lasting maintenance command, not a one-shot: it is the second half of the retirement
 * route. Delete alongside {@see RetireHistoricRunCommand}, never before it.
 */
class PurgeRetiredHistoricRunCommand extends Command
{
    protected $signature = 'historic-import:purge-retired-run
                            {--processing-id= : Exact processing ID of the retired run to purge}
                            {--apply : Delete what the inventory lists (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Delete the content a retired run still holds, keeping its row as the tombstone';

    public function handle(RetiredRunPurge $purge): int
    {
        try {
            $processingId = $this->processingId();
            $apply = (bool) $this->option('apply');

            $plan = $purge->plan($processingId);

            if ($plan['state'] === 'purged') {
                $this->info("Run [{$processingId}] is already purged; nothing to do.");

                return self::SUCCESS;
            }

            $this->renderInventory($plan);

            if (! $apply) {
                $this->warn('DRY RUN: nothing was written or deleted. Re-run with --apply for this exact run.');

                return self::SUCCESS;
            }

            if (! (bool) $this->option('yes') && ! $this->confirm("Permanently delete the content of retired run [{$processingId}]?", false)) {
                throw new RuntimeException('Not confirmed; no changes were written.');
            }

            $result = $purge->apply($processingId);

            if ($result['failures'] !== []) {
                foreach ($result['failures'] as $failure) {
                    $this->error("Could not delete {$failure}");
                }

                $this->error(sprintf(
                    'Purge incomplete: %d file(s) deleted, %d failed. Database work is committed; re-run with --apply to finish.',
                    $result['files_deleted'],
                    count($result['failures']),
                ));

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Purged run [%s]: %d file(s) deleted (%s), %d database row(s) deleted.',
                $processingId,
                $result['files_deleted'],
                self::formatBytes($result['bytes_deleted']),
                $result['rows_deleted'],
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function processingId(): string
    {
        $processingId = $this->option('processing-id');

        if (! is_string($processingId) || trim($processingId) === '') {
            throw new RuntimeException('--processing-id is required and must name the exact retired run.');
        }

        return trim($processingId);
    }

    /**
     * @param  array{
     *     state: string,
     *     files: list<array{kind: string, disk: string, path: string, bytes: int, exists: bool, sha256: ?string, expected_sha256: ?string}>,
     *     rows: list<array{table: string, id: int, detail: string}>,
     *     fragments: list<array{table: string, id: int, field: string, detail: string}>,
     *     left: list<string>
     * }  $plan
     */
    private function renderInventory(array $plan): void
    {
        $this->line($plan['state'] === 'resume'
            ? 'Resuming an earlier purge: the database work is committed; these files remain.'
            : 'Inventory of what this purge deletes:');

        $present = array_values(array_filter($plan['files'], static fn (array $file): bool => $file['exists']));
        $absent = array_values(array_filter($plan['files'], static fn (array $file): bool => ! $file['exists']));

        $this->line(sprintf('Files: %d present (%s), %d already absent.', count($present), self::formatBytes((int) array_sum(array_column($present, 'bytes'))), count($absent)));

        foreach ($plan['files'] as $file) {
            $this->line(sprintf(
                '  file  %s  %s:%s  %s',
                $file['kind'],
                $file['disk'],
                $file['path'],
                $file['exists'] ? self::formatBytes($file['bytes']) : '(absent)',
            ));
        }

        $this->line(sprintf('Database rows: %d.', count($plan['rows'])));

        foreach ($plan['rows'] as $row) {
            $this->line("  row   {$row['table']} #{$row['id']}  {$row['detail']}");
        }

        $this->line(sprintf('JSON fragments removed from retained rows: %d.', count($plan['fragments'])));

        foreach ($plan['fragments'] as $fragment) {
            $this->line("  json  {$fragment['table']} #{$fragment['id']}  {$fragment['field']}  {$fragment['detail']}");
        }

        foreach ($plan['left'] as $line) {
            $this->line("  kept  {$line}");
        }

        $this->line('Kept in every case: the run row (tombstone), its retirement record, the historic import operation rows and the archive masters.');
    }

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
