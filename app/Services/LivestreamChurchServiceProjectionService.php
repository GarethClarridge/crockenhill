<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection;
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

        $itemPayloads = $this->mapper->map($sections, $processingLog->processing_id);

        if ($itemPayloads === []) {
            return $this->skipped('No projectable sections after filtering');
        }

        $churchService = $this->findMatchingService($identity['date'], $identity['service']);

        if ($churchService !== null && $this->hasNonLivestreamItems($churchService)) {
            $this->linkProcessingLogToService($processingLog, $churchService);

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
         * @var array{church_service: ChurchService, sync_result: array{conflicts: array<int, array<string, mixed>>}} $result
         */
        $result = DB::transaction(function () use ($processingLog, $itemPayloads, $churchService, $identity, $isNewService): array {
            if ($isNewService) {
                $churchService = ChurchService::query()->create([
                    'date' => $identity['date'],
                    'service' => $identity['service']->value,
                    'source' => ChurchServiceItemSource::LIVESTREAM->value,
                    'needs_review' => false,
                    'import_metadata' => [
                        'livestream_projection' => [
                            'projected_at' => now()->toIso8601String(),
                            'processing_id' => $processingLog->processing_id,
                        ],
                    ],
                ]);
            } else {
                /** @var ChurchService $churchService */
                $churchService->forceFill([
                    'import_metadata' => array_replace_recursive(
                        $churchService->import_metadata?->toArray() ?? [],
                        [
                            'livestream_projection' => [
                                'projected_at' => now()->toIso8601String(),
                                'processing_id' => $processingLog->processing_id,
                            ],
                        ]
                    ),
                ])->saveQuietly();
            }

            $this->linkProcessingLogToService($processingLog, $churchService);

            $syncResult = $this->itemSyncService->sync(
                $churchService,
                $itemPayloads,
                ChurchServiceItemSource::LIVESTREAM,
            );

            return [
                'church_service' => $churchService->fresh() ?? $churchService,
                'sync_result' => $syncResult,
            ];
        });

        $churchService = $result['church_service'];

        $this->linkSectionsToItems($sections, $churchService);

        $needsReview = $this->computeNeedsReview($sections);

        if ($needsReview) {
            $churchService->forceFill(['needs_review' => true])->saveQuietly();
        }

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            ChurchServiceItemSource::LIVESTREAM,
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
                $query->where('source', '!=', ChurchServiceItemSource::LIVESTREAM->value)
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
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $projection = is_array($metadata['livestream_projection'] ?? null) ? $metadata['livestream_projection'] : [];
            $sectionId = $projection['service_section_id'] ?? null;

            if (is_int($sectionId)) {
                $sectionIdToItem[$sectionId] = $item;
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
     * @param  Collection<int, ServiceSection>  $sections
     */
    private function computeNeedsReview(Collection $sections): bool
    {
        foreach ($sections as $section) {
            if ($section->needs_manual_review) {
                return true;
            }

            if (is_numeric($section->confidence) && (float) $section->confidence < 0.5) {
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
        return [
            'projected' => false,
            'reason' => $reason,
            'church_service_id' => $churchServiceId,
            'items_projected' => 0,
        ];
    }
}
