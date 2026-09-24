<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\ConcatenatedSourceRestage;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Rebuild a concatenated historic run's staged source from its archive parts: the
 * concatenation gate that `historic-import:restage-source`'s byte-identity rule cannot be.
 *
 * Each part is checked against the manifest's size and sha256, the parts are joined with the
 * importer's recipe, and the join must carry the run's recorded duration and codec fingerprint.
 * Verify-only by default; the verification still writes a temporary join, because the
 * timeline can only be read from one, and removes it. One run per invocation, as with the
 * single-part restore.
 *
 * Delete once the historic import's source-retention decision is settled.
 */
class RestageConcatenatedHistoricSourceCommand extends Command
{
    protected $signature = 'historic-import:restage-concatenated-source
        {run : Media processing log ID}
        {--archive-root=/mnt/cbc-services : Root the manifest\'s relative part paths resolve against}
        {--execute : Stage the rebuilt source and stamp it; without this option the command only verifies}';

    protected $description = 'Rebuild a concatenated historic run\'s source from its verified archive parts';

    public function handle(ConcatenatedSourceRestage $restage, HistoricStagingContextRegistry $stagingContexts): int
    {
        try {
            $run = MediaProcessingLog::find((int) $this->argument('run'));

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException('That processing run does not exist.');
            }

            $execute = (bool) $this->option('execute');
            $archiveRoot = (string) $this->option('archive-root');
            $this->line('Verifying each archive part and joining them…');

            $context = $run->historicStagingContext();
            $result = $context === null
                ? $restage->restage($run, $archiveRoot, $execute)
                : $stagingContexts->within($context, static fn (): array => $restage->restage($run, $archiveRoot, $execute));

            $this->table(['', ''], [
                ['parts verified', (string) $result['parts']],
                ['recorded duration', sprintf('%.3f s', $result['recorded_duration'])],
                ['rebuilt duration', sprintf('%.6f s', $result['duration'])],
                ['sha256 to stage and stamp', $result['sha256']],
            ]);

            match ($result['outcome']) {
                'verified' => $this->warn('VERIFY ONLY: the join carries this run\'s timeline; nothing was staged.'),
                'already_restaged' => $this->info('Already restaged through the concatenation gate; nothing to do.'),
                'already_staged' => $this->info($execute
                    ? 'The staged file is exactly this join; stamped.'
                    : 'The staged file is exactly this join. Pass --execute to stamp it.'),
                'restaged' => $this->info('Restaged and stamped.'),
            };

            if ($execute && in_array($result['outcome'], ['restaged', 'already_staged'], true)) {
                Log::info('Restaged a concatenated historic run source from its archive parts', [
                    'processing_id' => $run->processing_id,
                    'sha256' => $result['sha256'],
                    'duration' => $result['duration'],
                ]);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
