<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RetranscribeForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-transcribe the members of a snapshotted corpus re-run batch (plan §4.0, Tier A).
 *
 * The same batch discipline as `historic-import:rerun-redetect`: the snapshot is the batch, the
 * commit is pinned, each invocation dispatches at most `--max` runs and a run already re-run on
 * this commit is refused. A member is dispatched only on its grounds, a live transcript-loss
 * hold on the text it holds now or a `new_better` listening route on that text (frozen into the
 * snapshot by `rerun-snapshot --routing`). Each run costs a full Whisper pass and stops before
 * detection: re-detect it with `historic-import:rerun-redetect` on this snapshot or a later one,
 * then cut the media with `historic-import:rerun-extract`, both on the frozen commit.
 *
 * Complete the plan's dispatch preflight (queues, worker code, mounts, disk) first; this
 * command checks each run, not the workers that will process it.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RetranscribeForCorpusRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-retranscribe
        {snapshot : The batch snapshot written by historic-import:rerun-snapshot}
        {runs?* : A subset of the snapshot\'s runs; all of them when omitted}
        {--max=10 : The most runs one invocation will dispatch}
        {--execute : Dispatch; without this option the command is a dry run}';

    protected $description = 'Re-transcribe the transcript-loss and listening-routed members of a corpus re-run batch, leaving detection to Tier B (Tier A)';

    public function handle(RetranscribeForCorpusRerun $retranscriber): int
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
            $this->warn('This re-opens completed runs from full-service transcription: each is transcribed again and re-detected; media is deferred to rerun-extract.');
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
                ? $retranscriber->execute($run, $snapshot, $execute)
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
