<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecomposeForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Recompose the banked draws of a corpus re-run batch's members (plan §4.0) without a provider
 * call: after the operator answers a round's questions (same commit), or after a rule is adopted
 * (new commit) for runs not yet cut. See {@see RecomposeForCorpusRerun}.
 *
 * Dry-run by default, at most `--max` runs an invocation. Like a detection round it cuts no
 * media: `historic-import:rerun-extract` takes a recomposed round as it takes a detected one.
 * A run whose draws no longer fit its input is refused and re-detected instead.
 *
 * Complete the plan's dispatch preflight (queues, worker code, mounts, disk) first; this
 * command checks each run, not the workers that will process it.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RecomposeForCorpusRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-recompose
        {snapshot : The batch snapshot written by historic-import:rerun-snapshot}
        {runs?* : A subset of the snapshot\'s runs; all of them when omitted}
        {--max=10 : The most runs one invocation will dispatch}
        {--execute : Dispatch; without this option the command is a dry run}';

    protected $description = 'Recompose a corpus re-run batch\'s banked draws under current rules and answers, deferring media';

    public function handle(RecomposeForCorpusRerun $recomposer): int
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
            $this->warn('This re-opens runs: each leaves `completed` while its banked draws are recomposed. No draw is made and no media is cut.');
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
                ? $recomposer->execute($run, $snapshot, $execute)
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
