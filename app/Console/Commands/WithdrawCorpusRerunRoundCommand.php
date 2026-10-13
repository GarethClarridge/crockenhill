<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\WithdrawCorpusRerunRound;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Withdraw the unfinished or failed corpus re-run rounds of named runs, returning each to the
 * completed state its snapshot recorded, so a new round can take it up. See
 * {@see WithdrawCorpusRerunRound} for what is withdrawn and what is refused.
 *
 * Dry-run by default. Runs are always named: there is no "every member" here.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class WithdrawCorpusRerunRoundCommand extends Command
{
    protected $signature = 'historic-import:rerun-withdraw
        {snapshot : The snapshot the unfinished rounds were dispatched against}
        {runs* : The runs whose rounds to withdraw}
        {--reason= : Why, recorded on each withdrawal}
        {--execute : Withdraw; without this option the command is a dry run}';

    protected $description = 'Withdraw corpus re-run rounds that will never finish, returning the runs to completed';

    public function handle(WithdrawCorpusRerunRound $withdrawer): int
    {
        $execute = (bool) $this->option('execute');
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->components->error('--reason is required: it is recorded on each withdrawal.');

            return self::FAILURE;
        }

        try {
            $snapshot = HistoricRerunSnapshot::fromFile(PrivateEvidenceFile::resolve($this->argument('snapshot'), 'The re-run snapshot'));
            $runIds = $snapshot->select((array) $this->argument('runs'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written.');
        }

        $rows = [];
        $counts = ['ready' => 0, 'withdrawn' => 0, 'refused' => 0];

        foreach ($runIds as $runId) {
            $run = MediaProcessingLog::find($runId);
            $result = $run instanceof MediaProcessingLog
                ? $withdrawer->execute($run, $snapshot, $reason, $execute)
                : ['outcome' => 'refused', 'reason' => 'run not found'];

            $counts[$result['outcome']]++;
            $rows[] = [$runId, $result['outcome'], $result['reason']];
        }

        $this->table(['Run', 'Outcome', 'Reason'], $rows);
        $this->line(sprintf(
            '%d %s, %d refused. Snapshot membership %s.',
            $execute ? $counts['withdrawn'] : $counts['ready'],
            $execute ? 'withdrawn' : 'ready',
            $counts['refused'],
            $snapshot->membershipSha256,
        ));

        return self::SUCCESS;
    }
}
