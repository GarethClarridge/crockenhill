<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RedetectHistoricServiceStructure;
use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;

/**
 * Operator instrument for re-deriving a pre-D1 historic structure under the
 * sermon-absence schema (see RedetectHistoricServiceStructure). Dry-run by
 * default, and never selects runs on its own — each run is named explicitly,
 * because re-detection costs a provider call and replaces projected sections.
 *
 * Delete once every structure projected before the sermon-absence schema (D1,
 * 2026-09-03) has either been re-derived or retired. The set is closed and
 * shrinking: runs projected after D1 receive the assertion at detection time and
 * can never need this. Nothing outside the historic import ever will.
 */
class RedetectHistoricServiceStructureCommand extends Command
{
    protected $signature = 'historic-import:redetect-structure
        {run* : Media processing log IDs to re-derive}
        {--execute : Dispatch the re-detection; without this option the command is a dry run}';

    protected $description = 'Re-run structure detection for a historic run whose stored structure predates the sermon-absence schema';

    public function handle(RedetectHistoricServiceStructure $redetector): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. Nothing will be dispatched; pass --execute to re-detect.');
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
                    '  run #%d (service %s): %s — %s',
                    $log->id,
                    $log->church_service_id ?? '—',
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
            [
                [$execute ? 'Dispatched' : 'Would dispatch', $dispatched],
                ['Skipped', $skipped],
            ],
        );

        return self::SUCCESS;
    }
}
