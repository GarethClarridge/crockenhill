<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionSongMatchType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Re-derives section review flags from each section's own persisted metadata,
 * bringing services processed before a policy change back in line with the
 * current SectionReviewFlagPolicy — without re-running the LLM pipeline.
 *
 * Unlike the one-shot Workstream B cleanup, this is a general, idempotent
 * reconciliation over every review-candidate section (all classification
 * modes), safe to re-run after any future flag-policy change.
 */
class RecomputeSectionReviewFlagsCommand extends Command
{
    protected $signature = 'services:recompute-section-review-flags
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--chunk=200 : Number of sections or services to inspect per chunk}
        {--service=* : Restrict to these church service IDs}';

    protected $description = 'Re-derive stale section review flags from stored metadata against current policy';

    public function handle(
        SectionReviewFlagRecalculator $recalculator,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ): int {
        $execute = (bool) $this->option('execute');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        /** @var list<int> $serviceIds */
        $serviceIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('service'),
        )));

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No rows will be updated; pass --execute to persist these changes.');
        }

        $eligibleSections = 0;
        $updatedSections = 0;

        /** @var array<int, true> $touchedServiceIds */
        $touchedServiceIds = [];

        $this->candidateSectionsQuery($serviceIds)
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $sections) use (
                &$eligibleSections,
                &$updatedSections,
                &$touchedServiceIds,
                $recalculator,
                $execute,
            ): void {
                foreach ($sections as $section) {
                    $updates = $recalculator->updatesFor($section);
                    if ($updates === []) {
                        continue;
                    }

                    $eligibleSections++;

                    foreach ($this->serviceIdsForSection($section) as $serviceId) {
                        $touchedServiceIds[$serviceId] = true;
                    }

                    if (! $execute) {
                        continue;
                    }

                    $section->forceFill($updates)->saveQuietly();
                    $updatedSections++;
                }
            });

        // Also re-sync services still carrying any review trigger even when no
        // section changed this pass — otherwise a service left flagged with zero
        // actionable items (a phantom) would never clear.
        foreach ($this->servicesWithStaleTriggers($serviceIds) as $serviceId) {
            $touchedServiceIds[$serviceId] = true;
        }

        $affectedServiceIds = array_keys($touchedServiceIds);
        $syncedServices = 0;

        if ($execute && $affectedServiceIds !== []) {
            ChurchService::query()
                ->whereKey($affectedServiceIds)
                ->orderBy('id')
                ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewSynchronizer, &$syncedServices): void {
                    foreach ($services as $service) {
                        // Recompute needs_review and review_triggers from the service's
                        // live (non-superseded) sections. Triggers no longer backed by
                        // an actionable section are dropped — including orphaned legacy
                        // triggers a strip allow-list could never enumerate.
                        $reviewSynchronizer->reconcileServiceReview($service);
                        $syncedServices++;
                    }
                });
        }

        $this->table(
            ['Metric', $execute ? 'Applied' : 'Would apply'],
            [
                ['Sections re-derived', $execute ? $updatedSections : $eligibleSections],
                ['Services re-synced', $execute ? $syncedServices : count($affectedServiceIds)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Only rows that could change downward under current policy: a stored
     * manual-review flag that may clear, or an inferred song match that may
     * confirm.
     *
     * @param  list<int>  $serviceIds
     * @return Builder<ServiceSection>
     */
    private function candidateSectionsQuery(array $serviceIds): Builder
    {
        return ServiceSection::query()
            ->where(function (Builder $query): void {
                $query->where('needs_manual_review', true)
                    ->orWhere('song_match_type', ServiceSectionSongMatchType::Inferred->value);
            })
            ->when($serviceIds !== [], function (Builder $query) use ($serviceIds): void {
                $query->whereIn('id', $this->sectionIdsForServices($serviceIds));
            });
    }

    /**
     * A section can belong to a service through its processing log or its
     * projected item; either path counts for scoping.
     *
     * @param  list<int>  $serviceIds
     * @return Builder<ServiceSection>
     */
    private function sectionIdsForServices(array $serviceIds): Builder
    {
        return ServiceSection::query()
            ->select('id')
            ->where(function (Builder $query) use ($serviceIds): void {
                $query->whereHas('processingLog', fn (Builder $log): Builder => $log->whereIn('church_service_id', $serviceIds))
                    ->orWhereHas('churchServiceItem', fn (Builder $item): Builder => $item->whereIn('church_service_id', $serviceIds));
            });
    }

    /**
     * Flagged services still carrying any review trigger, so a service left
     * flagged with no actionable non-superseded section is revisited even
     * without a section change this pass. reconcileServiceReview() then recomputes
     * from live sections and drops triggers no longer substantiated — a non-empty
     * import_metadata->review_triggers key is present only while triggers remain
     * (sync() unsets it when empty).
     *
     * @param  list<int>  $serviceIds
     * @return list<int>
     */
    private function servicesWithStaleTriggers(array $serviceIds): array
    {
        $ids = ChurchService::query()
            ->where('needs_review', true)
            ->whereNotNull('import_metadata->review_triggers')
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('id', $serviceIds))
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * @return list<int>
     */
    private function serviceIdsForSection(ServiceSection $section): array
    {
        $ids = [];

        $logServiceId = $section->processingLog->church_service_id;
        if (is_int($logServiceId)) {
            $ids[] = $logServiceId;
        }

        if ($section->church_service_item_id !== null) {
            $itemServiceId = $section->churchServiceItem?->church_service_id;
            if (is_int($itemServiceId)) {
                $ids[] = $itemServiceId;
            }
        }

        return array_values(array_unique($ids));
    }
}
