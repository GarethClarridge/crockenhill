<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RedetectStructureOnRecoveredEvidence;
use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;

/**
 * Operator instrument for re-deriving the structure of a completed run whose
 * transcript the recovery replay corrected.
 *
 * Dry-run by default, and **never selects runs on its own**. Re-detection
 * re-opens a completed run, costs two provider calls, re-cuts the sermon media
 * and replaces the projected sections — and detection is not deterministic, so
 * re-deriving a structure that happens to be right can make it worse. Each run
 * is named by an operator who has looked at it.
 *
 * Delete once every run the recovery replay corrected has either been re-derived
 * or accepted as it stands.
 */
class RedetectRecoveredStructureCommand extends Command
{
    protected $signature = 'historic-import:redetect-recovered-structure
        {run* : Media processing log IDs to re-derive}
        {--execute : Dispatch the re-detection; without this option the command is a dry run}';

    protected $description = 'Re-derive the service structure of a completed run whose transcript recovery corrected';

    public function handle(RedetectStructureOnRecoveredEvidence $redetector): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. Nothing will be dispatched; pass --execute to re-derive.');
        } else {
            $this->warn('This re-opens completed runs: they leave `completed` while the chain runs, their sermon media is re-cut, and their analysis is re-derived.');
        }

        /** @var list<int> $runIds */
        $runIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->argument('run'),
        )));

        $dispatched = 0;
        $skipped = 0;

        foreach ($runIds as $runId) {
            $log = MediaProcessingLog::find($runId);

            if (! $log instanceof MediaProcessingLog) {
                $this->error(sprintf('  run #%d: not found', $runId));
                $skipped++;

                continue;
            }

            $result = $redetector->execute($log, $execute);

            if ($result['outcome'] === 'dispatched') {
                $dispatched++;
                $this->line(sprintf(
                    '  run #%d (sermon %s): %s — %s',
                    $log->id,
                    $log->sermon_id ?? '—',
                    $execute ? 'dispatched' : 'would dispatch',
                    $result['reason'],
                ));

                continue;
            }

            $skipped++;
            $this->line(sprintf('  run #%d: skipped — %s', $log->id, $result['reason']));
        }

        $this->table(
            ['Outcome', 'Runs'],
            [[$execute ? 'Dispatched' : 'Would dispatch', $dispatched], ['Skipped', $skipped]],
        );

        if ($execute && $dispatched > 0) {
            $this->info('The structure each run replaced is recorded on it under '.RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY.'.');
        }

        return self::SUCCESS;
    }
}
