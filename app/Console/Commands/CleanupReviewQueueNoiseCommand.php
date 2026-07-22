<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChurchServiceReviewState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewStateService;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use App\Support\ServiceSectionConfidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * One-shot cleanup for review-queue Workstream B.
 *
 * Delete this command and its tests after B1/B2 have been executed in every
 * deployed environment, the local July 2026 corpus has been re-synchronised,
 * and a final dry run reports zero candidates (target: 2026 Q4 debt review).
 */
class CleanupReviewQueueNoiseCommand extends Command
{
    private const BULK_IMPORT_DATE = '2026-05-08';

    private const DEFAULT_CORPUS_RUN_DATES = [
        '2026-07-09',
        '2026-07-10',
    ];

    protected $signature = 'services:cleanup-review-queue-noise
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--chunk=200 : Number of services or sections to inspect per chunk}
        {--corpus-run-date=* : Override the local corpus processing-log creation dates (YYYY-MM-DD)}';

    protected $description = 'Preview or apply the guarded review-queue noise cleanup from Workstream B';

    public function __construct(
        private readonly SectionReviewFlagRecalculator $recalculator,
    ) {
        parent::__construct();
    }

    public function handle(
        ChurchServiceReviewStateService $reviewStateService,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ): int {
        $execute = (bool) $this->option('execute');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        $corpusRunDates = $this->corpusRunDates();
        if ($corpusRunDates === null) {
            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No rows will be updated; pass --execute to persist these changes.');
        }

        $bulkImport = $this->cleanupBulkImportReviewFlags($execute, $chunkSize);
        $canonicalConflicts = $this->normalizeFirstPopulationConflicts(
            $reviewStateService,
            $execute,
            $chunkSize,
        );
        $corpus = $this->resyncCorpusSections(
            $reviewSynchronizer,
            $corpusRunDates,
            $execute,
            $chunkSize,
        );

        $this->line($bulkImport['ids'] === []
            ? 'Bulk-import service IDs: none'
            : 'Bulk-import service IDs: '.implode(', ', $bulkImport['ids']));
        $this->line($canonicalConflicts['ids'] === []
            ? 'First-population conflict service IDs: none'
            : 'First-population conflict service IDs: '.implode(', ', $canonicalConflicts['ids']));

        $this->table(
            ['Cleanup', 'Eligible', $execute ? 'Updated' : 'Would update'],
            [
                ['B1 bulk-import review flags', $bulkImport['eligible'], $execute ? $bulkImport['updated'] : $bulkImport['eligible']],
                ['B2 first-population conflicts', $canonicalConflicts['eligible'], $execute ? $canonicalConflicts['updated'] : $canonicalConflicts['eligible']],
                ['B3 LLM corpus sections', $corpus['eligible_sections'], $execute ? $corpus['updated_sections'] : $corpus['eligible_sections']],
                ['B3 corpus services', $corpus['eligible_services'], $execute ? $corpus['synced_services'] : $corpus['eligible_services']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array{eligible: int, updated: int, ids: list<int>}
     */
    private function cleanupBulkImportReviewFlags(bool $execute, int $chunkSize): array
    {
        $eligibleIds = [];
        $updated = 0;

        ChurchService::query()
            ->with([
                'mediaProcessingLogs.serviceSections.churchServiceItem',
                'items.serviceSections.churchServiceItem',
            ])
            ->where('source', 'openlp')
            ->where('needs_review', true)
            ->whereDate('created_at', self::BULK_IMPORT_DATE)
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $services) use (&$eligibleIds, &$updated, $execute): void {
                foreach ($services as $service) {
                    if (! $this->isBulkImportReviewOnly($service)) {
                        continue;
                    }

                    $eligibleIds[] = $service->id;

                    if (! $execute) {
                        continue;
                    }

                    $service->forceFill(['needs_review' => false])->saveQuietly();
                    $updated++;
                }
            });

        sort($eligibleIds);

        return [
            'eligible' => count($eligibleIds),
            'updated' => $updated,
            'ids' => $eligibleIds,
        ];
    }

    private function isBulkImportReviewOnly(ChurchService $service): bool
    {
        $metadata = $service->import_metadata?->toArray() ?? [];
        $confidence = $metadata['confidence_score'] ?? null;
        $reviewTriggers = $metadata['review_triggers'] ?? [];

        if (! is_numeric($confidence) || (float) $confidence !== 0.5) {
            return false;
        }

        if (is_array($reviewTriggers) && $reviewTriggers !== []) {
            return false;
        }

        if ($service->pending_structure_merge_source !== null) {
            return false;
        }

        if ($service->review_state === ChurchServiceReviewState::Reopened) {
            return false;
        }

        if ($service->getRawOriginal('canonical_conflict_state') === 'reopened') {
            return false;
        }

        return ! $this->serviceHasReviewSection($service);
    }

    private function serviceHasReviewSection(ChurchService $service): bool
    {
        $sections = $service->mediaProcessingLogs
            ->flatMap(fn (MediaProcessingLog $log): EloquentCollection => $log->serviceSections)
            ->merge($service->items->flatMap(fn ($item): EloquentCollection => $item->serviceSections))
            ->unique('id');

        return $sections->contains(
            fn (ServiceSection $section): bool => $this->sectionIsReviewCandidate($section),
        );
    }

    private function sectionIsReviewCandidate(ServiceSection $section): bool
    {
        if ($section->needs_manual_review) {
            return true;
        }

        if ($section->publication_status === ServiceSectionPublicationStatus::PendingApproval) {
            return true;
        }

        if (in_array($section->song_match_type, [
            ServiceSectionSongMatchType::Inferred,
            ServiceSectionSongMatchType::Unmatched,
        ], true)) {
            return true;
        }

        if (
            $section->section_type->requiresStructuralUncertaintyReview()
            && $section->confidence !== null
            && $section->confidence < ServiceSectionConfidence::HIGH_THRESHOLD
        ) {
            return true;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $reviewFlags = $metadata['review_flags'] ?? [];

        if (is_array($reviewFlags) && $reviewFlags !== []) {
            return true;
        }

        if ($section->section_type !== ServiceSectionType::Song) {
            return false;
        }

        return $section->church_service_item_id === null
            || $section->churchServiceItem?->song_id === null;
    }

    /**
     * @return array{eligible: int, updated: int, ids: list<int>}
     */
    private function normalizeFirstPopulationConflicts(
        ChurchServiceReviewStateService $reviewStateService,
        bool $execute,
        int $chunkSize,
    ): array {
        $eligibleIds = [];
        $updated = 0;

        ChurchService::query()
            ->where('canonical_conflict_state', 'detected')
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewStateService, &$eligibleIds, &$updated, $execute): void {
                foreach ($services as $service) {
                    $metadata = $this->rawImportMetadata($service);
                    if (! $this->hasOnlyFirstPopulationConflict($metadata)) {
                        continue;
                    }

                    $eligibleIds[] = $service->id;

                    if (! $execute) {
                        continue;
                    }

                    unset($metadata['canonical_conflict']);

                    $service->forceFill([
                        'import_metadata' => $metadata,
                        'canonical_conflict_state' => 'none',
                        'canonical_conflict_detected_at' => null,
                        'canonical_conflict_incoming_source' => null,
                        'canonical_conflict_reviewed_previously' => null,
                        'canonical_conflict_canonical_changed' => null,
                        'canonical_conflict_reason' => null,
                        ...$reviewStateService->normalizedReviewColumns($metadata),
                    ])->saveQuietly();
                    $updated++;
                }
            });

        sort($eligibleIds);

        return [
            'eligible' => count($eligibleIds),
            'updated' => $updated,
            'ids' => $eligibleIds,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function hasOnlyFirstPopulationConflict(array $metadata): bool
    {
        $conflict = $metadata['canonical_conflict'] ?? null;
        $history = $metadata['canonical_conflict_history'] ?? null;

        if (! is_array($conflict) || ! is_array($history) || count($history) !== 1) {
            return false;
        }

        if (! is_array($history[0] ?? null) || $history[0] != $conflict) {
            return false;
        }

        if (($conflict['canonical_changed'] ?? null) !== true) {
            return false;
        }

        if (($conflict['review_reopened'] ?? null) !== false || ($conflict['reviewed_previously'] ?? null) !== false) {
            return false;
        }

        $conflicts = $conflict['conflicts'] ?? [];
        $changes = $conflict['changes'] ?? [];

        if (! is_array($conflicts) || $conflicts !== [] || ! is_array($changes) || $changes === []) {
            return false;
        }

        foreach ($changes as $change) {
            if (! is_array($change) || ($change['type'] ?? null) !== 'added_item') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function rawImportMetadata(ChurchService $service): array
    {
        $rawMetadata = $service->getRawOriginal('import_metadata');

        if (! is_string($rawMetadata) || blank($rawMetadata)) {
            return [];
        }

        $metadata = json_decode($rawMetadata, true);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  list<string>  $corpusRunDates
     * @return array{eligible_sections: int, updated_sections: int, eligible_services: int, synced_services: int}
     */
    private function resyncCorpusSections(
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
        array $corpusRunDates,
        bool $execute,
        int $chunkSize,
    ): array {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $this->comment('Corpus re-sync skipped: B3 is restricted to local and testing environments.');

            return [
                'eligible_sections' => 0,
                'updated_sections' => 0,
                'eligible_services' => 0,
                'synced_services' => 0,
            ];
        }

        $runs = MediaProcessingLog::query()
            ->whereNotNull('church_service_id')
            ->where(function (Builder $query) use ($corpusRunDates): void {
                foreach ($corpusRunDates as $date) {
                    $query->orWhereDate('created_at', $date);
                }
            });
        $runIds = (clone $runs)->pluck('id');
        $serviceIds = (clone $runs)
            ->pluck('church_service_id')
            ->filter(fn (mixed $id): bool => is_int($id))
            ->unique()
            ->values();

        $eligibleSections = 0;
        $updatedSections = 0;

        ServiceSection::query()
            ->whereIn('media_processing_log_id', $runIds)
            ->where('metadata->classification_mode', 'llm_structure')
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $sections) use (&$eligibleSections, &$updatedSections, $execute): void {
                foreach ($sections as $section) {
                    $updates = $this->corpusSectionUpdates($section);
                    if ($updates === []) {
                        continue;
                    }

                    $eligibleSections++;

                    if (! $execute) {
                        continue;
                    }

                    $section->forceFill($updates)->saveQuietly();
                    $updatedSections++;
                }
            });

        $syncedServices = 0;

        if ($execute) {
            ChurchService::query()
                ->whereKey($serviceIds)
                ->orderBy('id')
                ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewSynchronizer, &$syncedServices): void {
                    foreach ($services as $service) {
                        $sections = $this->sectionsForService($service);
                        $reviewTriggers = $service->import_metadata?->reviewTriggers;
                        $reviewTriggers ??= [];

                        if (! $sections->contains(fn (ServiceSection $section): bool => $section->needs_manual_review)) {
                            $reviewTriggers = array_values(array_filter(
                                $reviewTriggers,
                                fn (string $trigger): bool => $trigger !== 'manual_review_sections',
                            ));
                        }

                        $reviewSynchronizer->sync($service, $sections, $reviewTriggers);
                        $syncedServices++;
                    }
                });
        }

        return [
            'eligible_sections' => $eligibleSections,
            'updated_sections' => $updatedSections,
            'eligible_services' => $serviceIds->count(),
            'synced_services' => $syncedServices,
        ];
    }

    /** @return array<string, mixed> */
    private function corpusSectionUpdates(ServiceSection $section): array
    {
        return $this->recalculator->updatesFor($section);
    }

    /** @return EloquentCollection<int, ServiceSection> */
    private function sectionsForService(ChurchService $service): EloquentCollection
    {
        return ServiceSection::query()
            ->where(function (Builder $query) use ($service): void {
                $query->whereHas(
                    'processingLog',
                    fn (Builder $query): Builder => $query->where('church_service_id', $service->id),
                )->orWhereHas(
                    'churchServiceItem',
                    fn (Builder $query): Builder => $query->where('church_service_id', $service->id),
                );
            })
            ->get();
    }

    /** @return list<string>|null */
    private function corpusRunDates(): ?array
    {
        $dates = $this->option('corpus-run-date');
        $dates = $dates !== [] ? $dates : self::DEFAULT_CORPUS_RUN_DATES;
        $normalizedDates = [];

        foreach ($dates as $date) {
            if (! is_string($date)) {
                $this->error('Each --corpus-run-date value must use YYYY-MM-DD.');

                return null;
            }

            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            } catch (\Throwable) {
                $parsed = null;
            }

            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
                $this->error("Invalid corpus run date [{$date}]; expected YYYY-MM-DD.");

                return null;
            }

            $normalizedDates[] = $date;
        }

        return array_values(array_unique($normalizedDates));
    }
}
