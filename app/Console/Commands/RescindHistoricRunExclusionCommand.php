<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricRunExclusion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Take back an operator's exclusion of a run, so it can be processed again.
 *
 * An exclusion is terminal by design; this is the operator changing their mind on new
 * evidence (894, 2026-10-15: a fuller recording could keep the service and adopt the
 * better recording's sermon). The exclusion stays on record beside its rescission.
 *
 * Deletion trigger: Delete alongside {@see ExcludeHistoricRunCommand}.
 */
class RescindHistoricRunExclusionCommand extends Command
{
    protected $signature = 'historic-import:rescind-exclusion
                            {--processing-id= : Exact processing ID of the excluded run}
                            {--note= : The operator note that justifies taking the exclusion back}
                            {--apply : Rescind the exclusion (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Take back an operator decision that excluded a run';

    public function handle(HistoricRunExclusion $exclusion): int
    {
        try {
            $processingId = trim((string) ($this->option('processing-id') ?? ''));
            $note = trim((string) ($this->option('note') ?? ''));
            $apply = (bool) $this->option('apply');

            if ($processingId === '') {
                throw new RuntimeException('--processing-id is required.');
            }

            if ($note === '') {
                throw new RuntimeException('Rescinding an exclusion must carry the operator note that justifies it.');
            }

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $run = $exclusion->rescindable($processingId);

            $this->line("Run:    {$run->id} ({$run->processing_id}), {$run->status->value} at {$run->current_step}");
            $this->line("Reason: {$run->exclusionReason()}");
            $this->line('Note:   '.$note);

            if (! $apply) {
                $this->warn('DRY RUN: the exclusion stands. Re-run with --apply --yes to rescind it.');

                return self::SUCCESS;
            }

            $run->rescindExclusion($note);
            $this->info('Exclusion rescinded; the run keeps its own status and can be retried.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
