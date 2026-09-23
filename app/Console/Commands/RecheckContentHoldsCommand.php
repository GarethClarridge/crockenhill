<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\ContentHoldRechecker;
use Illuminate\Console\Command;

/**
 * Re-run the checks that found the content holds on named runs, or on every run
 * with a hold, and clear those the repaired transcript no longer supports.
 *
 * Structure detection does this itself after every sync; this reaches runs
 * repaired before it did, without paying for a detection.
 */
class RecheckContentHoldsCommand extends Command
{
    protected $signature = 'service:recheck-content-holds
        {--run=* : Processing log IDs; every run with a held section when omitted}
        {--execute : Clear the holds that pass; without this option the command is a dry run}';

    protected $description = 'Re-run the check that found each content hold, where a repair has rewritten the transcript since';

    public function handle(ContentHoldRechecker $rechecker, ChurchServiceReviewSynchronizer $reviewSynchronizer): int
    {
        $execute = (bool) $this->option('execute');
        $runIds = array_values(array_filter(array_map('intval', (array) $this->option('run'))));

        $runs = MediaProcessingLog::query()
            ->when(
                $runIds !== [],
                fn ($query) => $query->whereKey($runIds),
                fn ($query) => $query->whereHas('serviceSections', fn ($sections) => $sections->whereJsonContains('metadata->review_flags', HoldSectionForContentReview::FLAG)),
            )
            ->orderBy('id')
            ->get();

        $rows = [];
        $serviceIds = [];

        foreach ($runs as $run) {
            $outcome = $rechecker->recheck($run, dryRun: ! $execute);

            if ($outcome['cleared'] + $outcome['kept'] === 0) {
                continue;
            }

            $rows[] = [$run->id, $outcome['cleared'], $outcome['kept']];

            if ($outcome['cleared'] > 0 && is_int($run->church_service_id)) {
                $serviceIds[] = $run->church_service_id;
            }
        }

        $this->table(['Run', 'Would clear / cleared', 'Still found'], $rows);

        if (! $execute) {
            $this->warn('DRY RUN: nothing was written. Re-run with --execute to clear the holds that pass.');

            return self::SUCCESS;
        }

        ChurchService::query()
            ->whereKey(array_values(array_unique($serviceIds)))
            ->each(fn (ChurchService $service) => $reviewSynchronizer->reconcileServiceReview($service));

        $this->info(sprintf('Cleared %d hold record(s); %d still found by their check.', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2))));

        return self::SUCCESS;
    }
}
