<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcileStaleSermonReview;
use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reconciles runs stuck at manual sermon review that a detected sermon section
 * has made redundant (see ReconcileStaleSermonReview). Dry-run by default.
 */
class ReconcileStaleSermonReviewCommand extends Command
{
    protected $signature = 'services:reconcile-stale-sermon-review
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--service=* : Restrict to runs belonging to these church service IDs}';

    protected $description = 'Resume or clear stale manual sermon-review runs whose structure sermon section already exists';

    public function handle(ReconcileStaleSermonReview $reconciler): int
    {
        $execute = (bool) $this->option('execute');

        /** @var list<int> $serviceIds */
        $serviceIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('service'),
        )));

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No runs will be changed; pass --execute to persist.');
        }

        $outcomes = ['resumed' => 0, 'cleared' => 0, 'skipped' => 0];

        MediaProcessingLog::query()
            ->awaitingManualSermonReview()
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds))
            ->orderBy('id')
            ->get()
            ->each(function (MediaProcessingLog $log) use ($reconciler, $execute, &$outcomes): void {
                $outcome = $reconciler->execute($log, $execute);
                $outcomes[$outcome]++;

                if ($outcome !== 'skipped') {
                    $this->line(sprintf(
                        '  run #%d (service %s): %s',
                        $log->id,
                        $log->church_service_id ?? '—',
                        $execute ? $outcome : $outcome.' (dry run)',
                    ));
                }
            });

        $this->table(
            ['Outcome', 'Runs'],
            [
                [$execute ? 'Resumed (auto-extract)' : 'Would resume (auto-extract)', $outcomes['resumed']],
                [$execute ? 'Cleared (source gone)' : 'Would clear (source gone)', $outcomes['cleared']],
                ['Left for manual selection', $outcomes['skipped']],
            ],
        );

        return self::SUCCESS;
    }
}
