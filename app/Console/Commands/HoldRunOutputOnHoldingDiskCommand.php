<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HoldingDiskCustodyRepair;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Hold a weekly run's published output when it was written to a holding disk.
 *
 * Deletion trigger: Delete alongside {@see HoldingDiskCustodyRepair}.
 */
class HoldRunOutputOnHoldingDiskCommand extends Command
{
    protected $signature = 'historic-import:hold-run-output
                            {--processing-id= : Exact processing ID of the run whose output to hold}
                            {--apply : Hold the output (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Quarantine a run\'s sermon and song clips that were published onto a holding disk';

    public function handle(HoldingDiskCustodyRepair $repair): int
    {
        try {
            $processingId = trim((string) ($this->option('processing-id') ?? ''));
            $apply = (bool) $this->option('apply');

            if ($processingId === '') {
                throw new RuntimeException('--processing-id is required.');
            }

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $inspection = $repair->inspect($processingId);

            $this->line("Disk:        {$inspection['disk']}");
            $this->line('Sermons:     '.$this->ids($inspection['sermons']));
            $this->line('Song videos: '.$this->ids($inspection['song_videos']));

            if ($inspection['sermons'] === [] && $inspection['song_videos'] === []) {
                $this->info('Nothing this run published is waiting to be held.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->warn('DRY RUN: nothing was held. Re-run with --apply --yes to quarantine these.');

                return self::SUCCESS;
            }

            $repair->apply($inspection);
            $this->info('Held: quarantined on '.$inspection['disk'].'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<Model>  $records
     */
    private function ids(array $records): string
    {
        return $records === [] ? 'none' : implode(', ', array_map(static fn ($record): string => (string) $record->getKey(), $records));
    }
}
