<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\SectionFlagChange;
use App\Services\ChurchService\SectionStructureFlagRederiver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Re-derives structure review flags for completed runs from the structure they banked,
 * so a change to the validator's rules reaches the backlog and not only future runs.
 *
 * This is the layer `services:recompute-section-review-flags` cannot reach. That command
 * re-weighs the flags a section already carries; this one re-asks whether the section
 * should carry them at all, by re-running the validator's annotation over the stored
 * structure. Neither calls a provider, and the two compose: re-derive first, then recompute
 * so any song match the withdrawn flags were holding back can settle.
 */
class RederiveStructureReviewFlagsCommand extends Command
{
    protected $signature = 'services:rederive-structure-review-flags
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--chunk=50 : Number of processing runs to inspect per chunk}
        {--service=* : Restrict to these church service IDs}
        {--log=* : Restrict to these media processing log IDs}';

    protected $description = 'Re-derive banked structure review flags against the current validator rules';

    public function handle(
        SectionStructureFlagRederiver $rederiver,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ): int {
        $execute = (bool) $this->option('execute');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No rows will be updated; pass --execute to persist these changes.');
        }

        $runs = 0;
        $changedSections = 0;

        /** @var array<string, int> $added */
        $added = [];
        /** @var array<string, int> $removed */
        $removed = [];
        /** @var array<int, string> $notes */
        $notes = [];
        /** @var array<int, true> $touchedServiceIds */
        $touchedServiceIds = [];

        $this->runsQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $logs) use (
                &$runs,
                &$changedSections,
                &$added,
                &$removed,
                &$notes,
                &$touchedServiceIds,
                $rederiver,
                $execute,
            ): void {
                foreach ($logs as $processingLog) {
                    $runs++;

                    $rederivation = $rederiver->rederive($processingLog);

                    if ($rederivation->note !== null) {
                        $notes[] = sprintf('run %d: %s', $processingLog->id, $rederivation->note);
                    }

                    foreach ($rederivation->changes as $change) {
                        $changedSections++;

                        foreach ($change->addedFlags as $flag) {
                            $added[$flag] = ($added[$flag] ?? 0) + 1;
                        }

                        foreach ($change->removedFlags as $flag) {
                            $removed[$flag] = ($removed[$flag] ?? 0) + 1;
                        }

                        foreach ($this->serviceIdsFor($change) as $serviceId) {
                            $touchedServiceIds[$serviceId] = true;
                        }

                        if ($execute) {
                            $change->section->forceFill($change->updates)->saveQuietly();
                        }
                    }
                }
            });

        $affectedServiceIds = array_keys($touchedServiceIds);
        $syncedServices = 0;

        if ($execute && $affectedServiceIds !== []) {
            ChurchService::query()
                ->whereKey($affectedServiceIds)
                ->orderBy('id')
                ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewSynchronizer, &$syncedServices): void {
                    foreach ($services as $service) {
                        $reviewSynchronizer->reconcileServiceReview($service);
                        $syncedServices++;
                    }
                });
        }

        $this->reportFlagMovement('Flags withdrawn', $removed);
        $this->reportFlagMovement('Flags raised', $added);

        if ($notes !== []) {
            $this->newLine();
            $this->warn(sprintf('%d run(s) not fully re-derived:', count($notes)));

            foreach ($notes as $note) {
                $this->line('  '.$note);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', $execute ? 'Applied' : 'Would apply'],
            [
                ['Runs inspected', $runs],
                ['Sections re-derived', $changedSections],
                ['Services re-synced', $execute ? $syncedServices : count($affectedServiceIds)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Every live run that persisted sections — not only those carrying a banked structure,
     * because a heuristic-era run banked none and is exactly where the retired flags sit.
     *
     * Superseded runs are excluded: the dashboard already hides their sections, so
     * re-deriving them would report work the operator can never see. An unfiltered count
     * of `needs_manual_review` overstates the live queue by about 30% for the same reason.
     *
     * @return Builder<MediaProcessingLog>
     */
    private function runsQuery(): Builder
    {
        /** @var list<int> $serviceIds */
        $serviceIds = $this->intOption('service');
        /** @var list<int> $logIds */
        $logIds = $this->intOption('log');

        return MediaProcessingLog::query()
            ->whereNull('superseded_at')
            ->whereHas('serviceSections')
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds))
            ->when($logIds !== [], fn (Builder $query): Builder => $query->whereKey($logIds));
    }

    /**
     * @return list<int>
     */
    private function intOption(string $name): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            (array) $this->option($name),
        )));
    }

    /**
     * A section reaches its service through its processing log or its projected item;
     * either path has to be re-synced, because the service's own review state is derived
     * from whichever sections it can see.
     *
     * @return list<int>
     */
    private function serviceIdsFor(SectionFlagChange $change): array
    {
        $ids = [];

        $logServiceId = $change->section->processingLog->church_service_id;

        if (is_int($logServiceId)) {
            $ids[] = $logServiceId;
        }

        $itemServiceId = $change->section->churchServiceItem?->church_service_id;

        if (is_int($itemServiceId)) {
            $ids[] = $itemServiceId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, int>  $movement
     */
    private function reportFlagMovement(string $heading, array $movement): void
    {
        if ($movement === []) {
            return;
        }

        arsort($movement);

        $this->newLine();
        $this->line($heading.':');

        foreach ($movement as $flag => $count) {
            $this->line(sprintf('  %-44s %d', $flag, $count));
        }
    }
}
