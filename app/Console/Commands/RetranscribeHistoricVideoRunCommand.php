<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RetranscribeHistoricVideoRun;
use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-shot operator instrument for the approved context-drift retranscriptions.
 *
 * Delete after every run in RetranscribeHistoricVideoRun::ALLOWED_RUN_IDS has been
 * retranscribed, its results verified, and the historic import evidence is retained.
 */
final class RetranscribeHistoricVideoRunCommand extends Command
{
    protected $signature = 'historic-import:retranscribe-video-run
        {run : Exact approved media processing log ID}
        {--execute : Reopen and dispatch; without this option the command is a dry run}';

    protected $description = 'Retranscribe one approved historic video run from its staged source';

    public function handle(RetranscribeHistoricVideoRun $retranscriber): int
    {
        $runId = (int) $this->argument('run');
        $run = MediaProcessingLog::find($runId);

        if (! $run instanceof MediaProcessingLog) {
            $this->error(sprintf('run #%d: not found', $runId));

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written or dispatched.');
        }

        try {
            $result = $retranscriber->execute($run, $execute);
        } catch (Throwable $exception) {
            $this->error(sprintf('run #%d: failed — %s', $runId, $exception->getMessage()));

            return self::FAILURE;
        }

        $this->line(sprintf('run #%d: %s — %s', $runId, $result['outcome'], $result['reason']));

        return $result['outcome'] === 'refused' ? self::FAILURE : self::SUCCESS;
    }
}
