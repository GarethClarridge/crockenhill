<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LivestreamChurchServiceProjectionService
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly LivestreamSectionToServiceItemMapper $mapper,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ) {}

    /**
     * @return array{projected: bool, reason: string, church_service_id: int|null, items_projected: int}
     */
    public function project(MediaProcessingLog $processingLog): array
    {
        $identity = $this->identityResolver->resolve($processingLog);

        if ($identity === null) {
            return $this->skipped('Unable to resolve service identity from processing log');
        }

        $sections = $this->loadClassifiedSections($processingLog);

        if ($sections->isEmpty()) {
            return $this->skipped('No classified sections available for projection');
        }

        $churchService = $this->findMatchingService($identity['date'], $identity['service']);

        $itemPayloads = $this->mapper->map($sections, $processingLog->processing_id);

        if ($itemPayloads === []) {
            // Nothing is projectable, but the sections may still carry review
            // state (an all-OTHER or all-low-confidence run is the run most in
            // need of a reviewer) — link and roll up before skipping.
            if ($churchService !== null) {
                $this->linkProcessingLogToService($processingLog, $churchService);
                $this->reviewSynchronizer->openReviewFromSections($churchService, $sections);
            }

            return $this->skipped('No projectable sections after filtering', $churchService?->id);
        }

        if ($churchService !== null && $this->hasNonLivestreamItems($churchService)) {
            $this->linkProcessingLogToService($processingLog, $churchService);

            // OoS-backed services never reach projectItems()' needs_review
            // propagation, and in primary mode no alignment pass rolls section
            // review state up to the service — without this, a low-confidence
            // structure run would never reach the review inbox.
            $this->reviewSynchronizer->openReviewFromSections($churchService, $sections);

            return $this->skipped(
                'Matching service contains non-livestream items; skipping projection',
                $churchService->id
            );
        }

        return $this->projectItems($processingLog, $sections, $itemPayloads, $churchService, $identity);
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  list<array<string, mixed>>  $itemPayloads
     * @param  array{date: string, service: SermonService}  $identity
     * @return array{projected: bool, reason: string, church_service_id: int|null, items_projected: int}
     */
    private function projectItems(
        MediaProcessingLog $processingLog,
        Collection $sections,
        array $itemPayloads,
        ?ChurchService $churchService,
        array $identity,
    ): array {
        $isNewService = $churchService === null;
        $beforeSnapshot = $this->canonicalStateService->snapshot($churchService);

        /**
         * @var array{church_service: ChurchService, sync_result: array{conflicts: array<int, array<string, mixed>>}, needs_review: bool} $result
         */
        $result = DB::transaction(function () use ($processingLog, $sections, $itemPayloads, $churchService, $identity, $isNewService): array {
            $projectionMetadata = [
                'projected_at' => now()->toIso8601String(),
                'confidence_summary' => $this->buildConfidenceSummary($itemPayloads),
            ];

            if ($isNewService) {
                $churchService = ChurchService::query()->create([
                    'date' => $identity['date'],
                    'service' => $identity['service']->value,
                    'source' => ChurchServiceItemSource::Livestream->value,
                    'needs_review' => false,
                    'import_metadata' => [
                        'livestream_projection' => $projectionMetadata,
                    ],
                ]);
            } else {
                /** @var ChurchService $churchService */
                $churchService->forceFill([
                    'import_metadata' => array_replace_recursive(
                        $churchService->import_metadata?->toArray() ?? [],
                        [
                            'livestream_projection' => $projectionMetadata,
                        ]
                    ),
                ])->saveQuietly();
            }

            $this->linkProcessingLogToService($processingLog, $churchService);

            try {
                $syncResult = $this->itemSyncService->sync(
                    $churchService,
                    $itemPayloads,
                    ChurchServiceItemSource::Livestream,
                );
            } catch (UniqueConstraintViolationException $exception) {
                if (str_contains($exception->getMessage(), 'church_service_items_active_position_unique')) {
                    Log::warning('Church service item ordering constraint violated during projection', [
                        'processing_id' => $processingLog->processing_id,
                        'church_service_id' => $churchService->id,
                    ]);

                    return $this->skipped('Service item ordering constraint violated during livestream projection', $churchService->id);
                }

                throw $exception;
            }

            $freshService = $churchService->fresh() ?? $churchService;

            // Link sections to their projected items inside the transaction so that a
            // process crash between commit and linking can never leave items without
            // section links — a retry will re-project and re-link atomically.
            $this->linkSectionsToItems($sections, $freshService);

            // Compute review state only from the payloads that were actually projected.
            // Sections filtered out by the mapper (e.g. OTHER type, sub-threshold confidence)
            // should not influence needs_review on the service.
            $needsReview = $this->computeNeedsReviewFromPayloads($itemPayloads);

            if ($needsReview) {
                $freshService->forceFill(['needs_review' => true])->saveQuietly();
            }

            return [
                'church_service' => $freshService,
                'sync_result' => $syncResult,
                'needs_review' => $needsReview,
            ];
        });

        $churchService = $result['church_service'];
        $needsReview = $result['needs_review'];

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            ChurchServiceItemSource::Livestream,
            $result['sync_result'],
        );

        $itemCount = $churchService->items()->count();

        Log::info('Livestream service structure projected', [
            'processing_id' => $processingLog->processing_id,
            'church_service_id' => $churchService->id,
            'is_new_service' => $isNewService,
            'items_projected' => $itemCount,
            'needs_review' => $needsReview,
        ]);

        return [
            'projected' => true,
            'reason' => $isNewService ? 'Created new service from livestream projection' : 'Refreshed existing livestream-only service',
            'church_service_id' => $churchService->id,
            'items_projected' => $itemCount,
        ];
    }

    /**
     * @return Collection<int, ServiceSection>
     */
    private function loadClassifiedSections(MediaProcessingLog $processingLog): Collection
    {
        return ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();
    }

    private function findMatchingService(string $date, SermonService $service): ?ChurchService
    {
        return ChurchService::query()
            ->whereDate('date', $date)
            ->where('service', $service->value)
            ->first();
    }

    private function hasNonLivestreamItems(ChurchService $churchService): bool
    {
        return $churchService->items()
            ->where(function ($query): void {
                $query->where('source', '!=', ChurchServiceItemSource::Livestream->value)
                    ->orWhereNull('source');
            })
            ->exists();
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     */
    private function linkSectionsToItems(Collection $sections, ChurchService $churchService): void
    {
        $items = $churchService->items()
            ->orderBy('position')
            ->get();

        /** @var array<int, ChurchServiceItem> $sectionIdToItem */
        $sectionIdToItem = [];

        foreach ($items as $item) {
            if (is_int($item->livestream_service_section_id)) {
                $sectionIdToItem[$item->livestream_service_section_id] = $item;
            }
        }

        foreach ($sections as $section) {
            $matchedItem = $sectionIdToItem[$section->id] ?? null;

            if ($matchedItem instanceof ChurchServiceItem) {
                $section->forceFill([
                    'church_service_item_id' => $matchedItem->id,
                ])->saveQuietly();
            }
        }
    }

    /**
     * Build a confidence summary for the service-level projection metadata.
     *
     * Counts items by confidence bucket so operators can quickly assess projection
     * quality without querying individual items.
     *
     * @param  list<array<string, mixed>>  $itemPayloads
     * @return array{high: int, medium: int, low: int, unknown: int, manual_review: int}
     */
    private function buildConfidenceSummary(array $itemPayloads): array
    {
        $summary = ['high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'manual_review' => 0];

        foreach ($itemPayloads as $payload) {
            $projection = is_array($payload['metadata']['livestream_projection'] ?? null)
                ? $payload['metadata']['livestream_projection']
                : [];

            $level = $projection['confidence_level'] ?? 'unknown';

            if (array_key_exists($level, $summary)) {
                $summary[$level]++;
            } else {
                $summary['unknown']++;
            }
        }

        return $summary;
    }

    /**
     * Compute whether the projected service needs review based only on the item
     * payloads that were actually projected — not all classified sections.
     *
     * Sections excluded by the mapper (e.g. OTHER type, below confidence threshold)
     * were never materialised as items and should not influence this flag.
     *
     * @param  list<array<string, mixed>>  $itemPayloads
     */
    private function computeNeedsReviewFromPayloads(array $itemPayloads): bool
    {
        foreach ($itemPayloads as $payload) {
            $projection = is_array($payload['metadata']['livestream_projection'] ?? null)
                ? $payload['metadata']['livestream_projection']
                : [];

            if ($projection['needs_manual_review'] ?? false) {
                return true;
            }

            $confidenceLevel = $projection['confidence_level'] ?? 'unknown';

            if (in_array($confidenceLevel, ['low', 'unknown'], true)) {
                return true;
            }
        }

        return false;
    }

    private function linkProcessingLogToService(MediaProcessingLog $processingLog, ChurchService $churchService): void
    {
        if ($processingLog->church_service_id !== $churchService->id) {
            $processingLog->forceFill([
                'church_service_id' => $churchService->id,
            ])->saveQuietly();
        }
    }

    /**
     * @return array{projected: bool, reason: string, church_service_id: int|null, items_projected: int}
     */
    private function skipped(string $reason, ?int $churchServiceId = null): array
    {
        Log::info('Livestream projection skipped', [
            'reason' => $reason,
            'church_service_id' => $churchServiceId,
        ]);

        return [
            'projected' => false,
            'reason' => $reason,
            'church_service_id' => $churchServiceId,
            'items_projected' => 0,
        ];
    }
}
