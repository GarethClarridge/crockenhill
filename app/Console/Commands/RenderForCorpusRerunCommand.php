<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RenderForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Render the cuts a corpus re-run's Tier C deferred, between batches (plan §4.0, "cut now,
 * render later").
 *
 * Takes the snapshot Tier C cut from. Run it once a batch's diff is accepted; renders queue on
 * the one ffmpeg worker, so they finish before the next batch's cuts start only if the next
 * batch waits for them. Release refuses a run until its render has landed. Dry-run by default,
 * at most `--max` runs an invocation; rendered runs are refused, so re-run it to continue or to
 * retry a failed render.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RenderForCorpusRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-render
        {snapshot : The batch snapshot Tier C cut from}
        {runs?* : A subset of the snapshot\'s runs; all of them when omitted}
        {--max=50 : The most runs one invocation will dispatch}
        {--execute : Dispatch; without this option the command is a dry run}';

    protected $description = 'Re-encode the cuts a corpus re-run Tier C deferred';

    public function handle(RenderForCorpusRerun $renderer): int
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
                ? $renderer->execute($run, $snapshot, $execute)
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
