<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RedetectForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-detect the members of a snapshotted corpus re-run batch (plan §4.0, Tier B).
 *
 * The snapshot written by `historic-import:rerun-snapshot` is the batch: only its runs can be
 * dispatched, and only on the commit it was taken on. Dry-run by default, and each invocation
 * dispatches at most `--max` runs; runs already re-run on this commit are refused, so the
 * command is re-run to continue a batch. Afterwards, `historic-import:rerun-diff` on the same
 * snapshot reports what changed.
 *
 * Complete the plan's dispatch preflight (queues, worker code, mounts, disk) first; this
 * command checks each run, not the workers that will process it.
 *
 * Delete with the corpus re-run's other instruments once its batches are accepted.
 */
class RedetectForCorpusRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-redetect
        {snapshot : The batch snapshot written by historic-import:rerun-snapshot}
        {runs?* : A subset of the snapshot\'s runs; all of them when omitted}
        {--max=10 : The most runs one invocation will dispatch}
        {--execute : Dispatch; without this option the command is a dry run}';

    protected $description = 'Re-detect the members of a corpus re-run batch from structure detection';

    public function handle(RedetectForCorpusRerun $redetector): int
    {
        $execute = (bool) $this->option('execute');
        $max = (int) $this->option('max');

        try {
            $snapshot = HistoricRerunSnapshot::fromFile(PrivateEvidenceFile::resolve($this->argument('snapshot'), 'The re-run snapshot'));
            $runIds = $this->runIds($snapshot);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($max < 1) {
            $this->components->error('--max must be at least 1.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written or dispatched. Each run\'s source is hashed, which reads the whole recording.');
        } else {
            $this->warn('This re-opens completed runs: each leaves `completed` while its chain runs, and its media is re-cut.');
        }

        $rows = [];
        $counts = ['ready' => 0, 'dispatched' => 0, 'refused' => 0];

        foreach ($runIds as $runId) {
            if ($counts['dispatched'] + ($execute ? 0 : $counts['ready']) >= $max) {
                $rows[] = [$runId, 'not reached', sprintf('--max=%d reached', $max)];

                continue;
            }

            $run = MediaProcessingLog::find($runId);
            $result = $run instanceof MediaProcessingLog
                ? $redetector->execute($run, $snapshot, $execute)
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

    /**
     * @return list<int>
     */
    private function runIds(HistoricRerunSnapshot $snapshot): array
    {
        $requested = HistoricRerunSnapshot::membership((array) $this->argument('runs'));

        if ($requested === []) {
            return $snapshot->membership;
        }

        $outside = array_diff($requested, $snapshot->membership);

        if ($outside !== []) {
            throw new RuntimeException('Not in the snapshot: '.implode(', ', $outside).'. A batch is exactly its snapshot.');
        }

        return $requested;
    }
}
