<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ProcessingRunSupersessionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Reconciles services that already carry duplicate/overlapping sections from
 * more than one processing run of the same recording, keeping the best run and
 * marking the rest superseded (see ProcessingRunSupersessionService).
 *
 * Idempotent and dry-run by default; the projection path now supersedes new
 * duplicates automatically, so this is the one-shot backfill for existing ones.
 */
class SupersedeDuplicateRunsCommand extends Command
{
    protected $signature = 'services:supersede-duplicate-runs
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--service=* : Restrict to these church service IDs}';

    protected $description = 'Keep the best processing run per service and supersede duplicate/overlapping runs';

    public function handle(ProcessingRunSupersessionService $supersessionService): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No runs will be changed; pass --execute to persist.');
        }

        $servicesReconciled = 0;
        $runsSuperseded = 0;

        foreach ($this->candidateServices() as $service) {
            $result = $supersessionService->reconcile($service, execute: $execute);

            if ($result['superseded'] === []) {
                continue;
            }

            $servicesReconciled++;
            $runsSuperseded += count($result['superseded']);

            // A non-empty superseded list guarantees a winning run was chosen.
            $winner = $result['winner'];
            $this->line(sprintf(
                '  service %d: kept run #%s, superseded run(s) %s',
                $service->id,
                $winner instanceof MediaProcessingLog ? (string) $winner->id : '—',
                implode(', ', $result['superseded']),
            ));
        }

        $this->table(
            ['Metric', $execute ? 'Applied' : 'Would apply'],
            [
                ['Services reconciled', $servicesReconciled],
                ['Runs superseded', $runsSuperseded],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Services with more than one non-superseded segmentation run — the only
     * ones that can carry overlap.
     *
     * @return EloquentCollection<int, ChurchService>
     */
    private function candidateServices(): EloquentCollection
    {
        /** @var list<int> $serviceIds */
        $serviceIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('service'),
        )));

        $duplicateServiceIds = MediaProcessingLog::query()
            ->segmentationPipeline()
            ->notSuperseded()
            ->whereNotNull('church_service_id')
            ->when($serviceIds !== [], fn ($query) => $query->whereIn('church_service_id', $serviceIds))
            ->selectRaw('church_service_id, COUNT(*) as run_count')
            ->groupBy('church_service_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('church_service_id');

        return ChurchService::query()
            ->whereIn('id', $duplicateServiceIds)
            ->orderBy('id')
            ->get();
    }
}
