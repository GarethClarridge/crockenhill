<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExtractForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Cut the media a corpus re-run's detection rounds deferred, on the frozen commit (plan §4.0,
 * Tier C).
 *
 * Takes the same snapshot as the round it follows: only runs whose latest round ran on this
 * commit and deferred its media can be dispatched. Dry-run by default, at most `--max` runs an
 * invocation; runs already extracted are refused, so the command is re-run to continue.
 * Afterwards, `historic-import:rerun-diff` on the same snapshot applies the full custody
 * checks, which a detection round leaves pending.
 *
 * Complete the plan's dispatch preflight (queues, worker code, mounts, disk) first; this
 * command checks each run, not the workers that will process it.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class ExtractForCorpusRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-extract
        {snapshot : The batch snapshot the detection round was dispatched from}
        {runs?* : A subset of the snapshot\'s runs; all of them when omitted}
        {--max=10 : The most runs one invocation will dispatch}
        {--execute : Dispatch; without this option the command is a dry run}';

    protected $description = 'Cut the media a corpus re-run detection round deferred';

    public function handle(ExtractForCorpusRerun $extractor): int
    {
        $execute = (bool) $this->option('execute');
        $max = (int) $this->option('max');

        try {
            $snapshot = HistoricRerunSnapshot::fromFile(PrivateEvidenceFile::resolve($this->argument('snapshot'), 'The re-run snapshot'));
            $runIds = $snapshot->select((array) $this->argument('runs'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($max < 1) {
            $this->components->error('--max must be at least 1.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written or dispatched.');
        } else {
            $this->warn('This re-opens completed runs: each leaves `completed` while its media is cut.');
        }

        $rows = [];
        $counts = ['ready' => 0, 'dispatched' => 0, 'refused' => 0];

        foreach ($runIds as $runId) {
            if ($max <= $counts['dispatched'] + ($execute ? 0 : $counts['ready'])) {
                $rows[] = [$runId, 'not reached', sprintf('--max=%d reached', $max)];

                continue;
            }

            $run = MediaProcessingLog::find($runId);
            $result = $run instanceof MediaProcessingLog
                ? $extractor->execute($run, $snapshot, $execute)
                : ['outcome' => 'refused', 'reason' => 'run not found'];

            $counts[$result['outcome']]++;
            $rows[] = [$runId, $result['outcome'], $result['reason']];
        }

        $this->table(['Run', 'Outcome', 'Reason'], $rows);
        $this->line(sprintf(
            '%d %s, %d refused, %d not reached. Snapshot membership %s at %s.',
            $execute ? $counts['dispatched'] : $counts['ready'],
            $execute ? 'dispatched' : 'ready',
            $counts['refused'],
            count($runIds) - array_sum($counts),
            $snapshot->membershipSha256,
            $snapshot->gitCommit ?? 'an unknown commit',
        ));

        return self::SUCCESS;
    }
}
