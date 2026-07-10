<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailImportPlanOutcome;
use App\Data\OosEmailImportResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailImportOutcome;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use App\Traits\SanitizesLogData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class InboundEmailImportService
{
    use SanitizesLogData;

    public function __construct(
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceStructureMergeService $mergeService,
    ) {}

    public function storeParseResult(InboundEmail $inboundEmail, OosEmailParseResult $parseResult, bool $isReparse = false): void
    {
        $metadata = [
            'parsing' => array_replace_recursive($parseResult->importMetadata, [
                'resolved_date' => $parseResult->date,
                'resolved_service' => $parseResult->service?->value,
                'items' => $parseResult->items,
                'needs_review' => $parseResult->needsReview,
                'should_import' => $parseResult->shouldImport,
            ]),
        ];

        if ($isReparse) {
            $metadata['reparsed_at'] = now()->toIso8601String();
            $metadata['failure'] = null;
        }

        $merged = $this->mergeProcessingMetadata($inboundEmail->processing_metadata, $metadata);

        // The parse payload holds numeric lists (service_plans, items). array_replace_recursive
        // merges those by index, so a re-parse that shrinks from two plans to one would leave the
        // stale second plan behind — restorable and importable from the inbox. Every parse fully
        // recomputes the payload, so replace the whole subtree rather than merging into it.
        $merged['parsing'] = $metadata['parsing'];

        $inboundEmail->processing_metadata = $merged;
        $inboundEmail->save();
    }

    public function storedParseResult(InboundEmail $inboundEmail): ?OosEmailParseResult
    {
        $processingMetadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $storedParseData = Arr::get($processingMetadata, 'parsing');

        if (! is_array($storedParseData) || ! is_array($storedParseData['items'] ?? null)) {
            return null;
        }

        $resolvedDate = Arr::get($storedParseData, 'resolved_date');
        $resolvedService = Arr::get($storedParseData, 'resolved_service');
        $confidenceScore = Arr::get($storedParseData, 'confidence_score');
        $items = $this->storedItems($storedParseData['items']);

        $storedPlans = Arr::get($storedParseData, 'service_plans');
        $isLegacyFlattened = ! is_array($storedPlans);

        $servicePlans = $isLegacyFlattened
            ? $this->legacyServicePlan(
                is_string($resolvedDate) && $resolvedDate !== '' ? $resolvedDate : null,
                is_string($resolvedService) ? SermonService::tryFrom($resolvedService) : null,
                $items,
                is_numeric($confidenceScore) ? round((float) $confidenceScore, 2) : 0.0,
                (bool) Arr::get($storedParseData, 'needs_review', false),
                (bool) Arr::get($storedParseData, 'should_import', false),
            )
            : $this->servicePlansFromStored($storedPlans);

        return new OosEmailParseResult(
            date: is_string($resolvedDate) && $resolvedDate !== '' ? $resolvedDate : null,
            service: is_string($resolvedService) ? SermonService::tryFrom($resolvedService) : null,
            items: $items,
            confidenceScore: is_numeric($confidenceScore) ? round((float) $confidenceScore, 2) : 0.0,
            needsReview: (bool) Arr::get($storedParseData, 'needs_review', false),
            shouldImport: (bool) Arr::get($storedParseData, 'should_import', false),
            importMetadata: Arr::except($storedParseData, [
                'resolved_date',
                'resolved_service',
                'items',
                'needs_review',
                'should_import',
            ]),
            servicePlans: $servicePlans,
            isLegacyFlattened: $isLegacyFlattened,
        );
    }

    /**
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @return list<OosEmailServicePlan>
     */
    private function legacyServicePlan(
        ?string $date,
        ?SermonService $service,
        array $items,
        float $confidence,
        bool $needsReview,
        bool $shouldImport,
    ): array {
        return [new OosEmailServicePlan($service, $date, $items, $confidence, $needsReview, $shouldImport)];
    }

    /**
     * @param  array<int, mixed>  $storedPlans
     * @return list<OosEmailServicePlan>
     */
    private function servicePlansFromStored(array $storedPlans): array
    {
        $plans = [];

        foreach ($storedPlans as $storedPlan) {
            if (! is_array($storedPlan)) {
                continue;
            }

            $service = is_string($storedPlan['service'] ?? null) ? SermonService::tryFrom($storedPlan['service']) : null;
            $date = is_string($storedPlan['date'] ?? null) && $storedPlan['date'] !== '' ? $storedPlan['date'] : null;
            $confidence = is_numeric($storedPlan['confidence'] ?? null) ? round((float) $storedPlan['confidence'], 2) : 0.0;

            $plans[] = new OosEmailServicePlan(
                service: $service,
                date: $date,
                items: $this->storedItems(is_array($storedPlan['items'] ?? null) ? $storedPlan['items'] : []),
                confidence: $confidence,
                needsReview: (bool) ($storedPlan['needs_review'] ?? false),
                shouldImport: (bool) ($storedPlan['should_import'] ?? false),
            );
        }

        return $plans;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function storedItems(array $items): array
    {
        $storedItems = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $position = $item['position'] ?? null;
            $type = $item['type'] ?? null;
            $title = $item['title'] ?? null;
            $sourceTitle = $item['source_title'] ?? null;
            $openLpSearchTitle = $item['openlp_search_title'] ?? null;
            $metadata = $item['metadata'] ?? null;

            if (! is_int($position) || ! is_string($type) || ! is_string($title)) {
                continue;
            }

            $storedItems[] = [
                'position' => $position,
                'type' => $type,
                'title' => $title,
                'source_title' => is_string($sourceTitle) ? $sourceTitle : null,
                'openlp_search_title' => is_string($openLpSearchTitle) ? $openLpSearchTitle : null,
                'metadata' => is_array($metadata) ? $metadata : null,
            ];
        }

        return $storedItems;
    }

    /**
     * Import every service plan in the parse result. Create-only never overwrites an existing
     * date+service slot; the normal path merges into an existing service or creates a new one.
     * The email is only marked Processed when every plan reaches a terminal outcome.
     *
     * @throws InvalidArgumentException
     */
    public function import(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        ?int $reviewedByUserId = null,
        string $reviewMode = 'direct_approve',
        bool $createOnly = false,
    ): OosEmailImportResult {
        $plans = $this->plansForImport($parseResult);

        if ($plans === []) {
            throw new InvalidArgumentException('Inbound email parse result has no service plans to import.');
        }

        $outcomes = [];

        foreach ($plans as $plan) {
            $outcomes[] = $this->importPlan($parseResult, $plan, $reviewedByUserId, $reviewMode, $createOnly);
        }

        $result = new OosEmailImportResult($outcomes);
        $this->recordImportOutcome($inboundEmail, $result, $reviewedByUserId, $reviewMode);

        return $result;
    }

    /**
     * Prefer the explicit service plans; fall back to synthesising a single plan from the
     * primary/legacy fields so a directly-constructed parse result still imports as one service.
     *
     * @return list<OosEmailServicePlan>
     */
    private function plansForImport(OosEmailParseResult $parseResult): array
    {
        if ($parseResult->servicePlans !== []) {
            return $parseResult->servicePlans;
        }

        if ($parseResult->service instanceof SermonService && is_string($parseResult->date) && $parseResult->items !== []) {
            return [new OosEmailServicePlan(
                service: $parseResult->service,
                date: $parseResult->date,
                items: $parseResult->items,
                confidence: $parseResult->confidenceScore,
                needsReview: $parseResult->needsReview,
                shouldImport: $parseResult->shouldImport,
            )];
        }

        return [];
    }

    private function importPlan(
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        ?int $reviewedByUserId,
        string $reviewMode,
        bool $createOnly,
    ): OosEmailImportPlanOutcome {
        // An admin approval imports any well-formed plan; the automated pipeline only imports
        // plans confident enough to auto-import, holding the rest for review.
        $ready = $reviewedByUserId !== null ? $plan->isImportable() : $plan->shouldImport;

        if (! $ready || ! $plan->isImportable()) {
            return new OosEmailImportPlanOutcome(
                $plan->key(),
                $plan->service,
                $plan->date,
                OosEmailImportOutcome::HeldForReview,
            );
        }

        $importMetadata = $this->planImportMetadata($parseResult, $plan, $reviewedByUserId, $reviewMode);

        try {
            return $createOnly
                ? $this->createOnlyPlan($plan, $importMetadata)
                : $this->mergeOrCreatePlan($plan, $importMetadata, $reviewedByUserId);
        } catch (Throwable $exception) {
            report($exception);

            return new OosEmailImportPlanOutcome(
                $plan->key(),
                $plan->service,
                $plan->date,
                OosEmailImportOutcome::Failed,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function createOnlyPlan(OosEmailServicePlan $plan, array $importMetadata): OosEmailImportPlanOutcome
    {
        /** @var SermonService $service */
        $service = $plan->service;
        $existed = false;

        try {
            $churchService = DB::transaction(function () use ($plan, $service, $importMetadata, &$existed): ChurchService {
                $existingService = ChurchService::query()
                    ->where('date', $plan->date)
                    ->where('service', $service->value)
                    ->lockForUpdate()
                    ->first();

                if ($existingService instanceof ChurchService) {
                    $existed = true;

                    return $existingService;
                }

                return $this->createServiceFromPlan($plan, $service, $importMetadata);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Under create-only semantics a race loser and a pre-existing service are identical.
            $existingService = ChurchService::query()
                ->where('date', $plan->date)
                ->where('service', $service->value)
                ->first();

            if (! $existingService instanceof ChurchService) {
                throw $exception;
            }

            return new OosEmailImportPlanOutcome(
                $plan->key(),
                $service,
                $plan->date,
                OosEmailImportOutcome::SkippedExisting,
                $existingService,
            );
        }

        return new OosEmailImportPlanOutcome(
            $plan->key(),
            $service,
            $plan->date,
            $existed ? OosEmailImportOutcome::SkippedExisting : OosEmailImportOutcome::Created,
            $churchService,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function mergeOrCreatePlan(OosEmailServicePlan $plan, array $importMetadata, ?int $reviewedByUserId): OosEmailImportPlanOutcome
    {
        /** @var SermonService $service */
        $service = $plan->service;

        $existingService = ChurchService::query()
            ->where('date', $plan->date)
            ->where('service', $service->value)
            ->first();

        if ($existingService instanceof ChurchService) {
            return $this->mergePlanIntoExistingService($plan, $existingService, $importMetadata, $reviewedByUserId);
        }

        $churchService = $this->createNewServiceFromPlan($plan, $service, $importMetadata, $reviewedByUserId);

        return new OosEmailImportPlanOutcome(
            $plan->key(),
            $service,
            $plan->date,
            OosEmailImportOutcome::Created,
            $churchService,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function createServiceFromPlan(OosEmailServicePlan $plan, SermonService $service, array $importMetadata): ChurchService
    {
        $churchService = new ChurchService;
        $churchService->fill([
            'date' => $plan->date,
            'service' => $service->value,
            'source' => ChurchServiceItemSource::Email->value,
            'needs_review' => $plan->needsReview,
            'import_metadata' => $importMetadata,
        ]);
        $churchService->save();

        $syncResult = $this->itemSyncService->sync($churchService, $plan->items, ChurchServiceItemSource::Email);
        $this->songLinker->linkForService($churchService);

        /** @var ChurchService $freshChurchService */
        $freshChurchService = $churchService->fresh(['items']) ?? $churchService;

        return $this->canonicalUpdateService->finalize(
            $freshChurchService,
            [],
            ChurchServiceItemSource::Email,
            $syncResult,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function createNewServiceFromPlan(
        OosEmailServicePlan $plan,
        SermonService $service,
        array $importMetadata,
        ?int $reviewedByUserId,
    ): ChurchService {
        $syncResult = [];

        $churchService = DB::transaction(function () use ($plan, $service, $importMetadata, $reviewedByUserId, &$syncResult): ChurchService {
            $churchService = ChurchService::query()->firstOrNew([
                'date' => $plan->date,
                'service' => $service->value,
            ]);
            $existingMetadata = $churchService->import_metadata?->toArray() ?? [];

            $churchService->fill([
                'source' => ChurchServiceItemSource::Email->value,
                'needs_review' => $reviewedByUserId === null ? $plan->needsReview : false,
                'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
            ]);
            $churchService->save();

            $syncResult = $this->itemSyncService->sync($churchService, $plan->items, ChurchServiceItemSource::Email);
            $this->songLinker->linkForService($churchService);

            /** @var ChurchService $freshChurchService */
            $freshChurchService = $churchService->fresh(['items']) ?? $churchService;

            return $freshChurchService;
        });

        Log::warning('Church service imported from email (new)', $this->sanitizeArrayForLog([
            'admin_id' => $reviewedByUserId,
            'church_service_id' => $churchService->id,
            'plan_key' => $plan->key(),
        ]));

        return $this->canonicalUpdateService->finalize(
            $churchService,
            [],
            ChurchServiceItemSource::Email,
            $syncResult,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function mergePlanIntoExistingService(
        OosEmailServicePlan $plan,
        ChurchService $existingService,
        array $importMetadata,
        ?int $reviewedByUserId,
    ): OosEmailImportPlanOutcome {
        $existingMetadata = $existingService->import_metadata?->toArray() ?? [];

        // Update import provenance metadata, but defer the source field update until after
        // the merge decision — if the merge is staged for review the service items are still
        // livestream-derived and source should continue to reflect their actual provenance.
        $existingService->fill([
            'needs_review' => $reviewedByUserId === null ? $plan->needsReview : false,
            'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
        ]);
        $existingService->save();

        $mergeResult = $this->mergeService->merge(
            $existingService,
            $plan->items,
            ChurchServiceItemSource::Email,
        );

        if ($mergeResult->wasMerged) {
            // Only update source to email once items have actually been applied.
            $mergeResult->churchService->forceFill([
                'source' => ChurchServiceItemSource::Email->value,
            ])->saveQuietly();

            $this->songLinker->linkForService($mergeResult->churchService);
        }

        Log::warning('Church service imported from email (existing)', $this->sanitizeArrayForLog([
            'admin_id' => $reviewedByUserId,
            'church_service_id' => $existingService->id,
            'plan_key' => $plan->key(),
            'was_merged' => $mergeResult->wasMerged,
        ]));

        return new OosEmailImportPlanOutcome(
            $plan->key(),
            $plan->service,
            $plan->date,
            OosEmailImportOutcome::Merged,
            $mergeResult->churchService,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planImportMetadata(
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        ?int $reviewedByUserId,
        string $reviewMode,
    ): array {
        $importMetadata = array_replace_recursive($parseResult->importMetadata, [
            'plan' => [
                'key' => $plan->key(),
                'service' => $plan->service?->value,
                'date' => $plan->date,
                'confidence' => $plan->confidence,
            ],
        ]);

        if ($reviewedByUserId !== null) {
            $importMetadata = array_replace_recursive($importMetadata, [
                'admin_review' => [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => $reviewMode,
                ],
            ]);
        }

        return $importMetadata;
    }

    private function recordImportOutcome(
        InboundEmail $inboundEmail,
        OosEmailImportResult $result,
        ?int $reviewedByUserId,
        string $reviewMode,
    ): void {
        $resolvedKeys = [];
        foreach ($result->plans as $plan) {
            if ($plan->outcome->isTerminal()) {
                $resolvedKeys[] = $plan->planKey;
            }
        }

        $primaryService = $result->primaryService();

        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'imported_church_service_id' => $primaryService?->id,
                'imported_church_service_ids' => $result->importedServiceIds(),
                'imported_at' => now()->toIso8601String(),
                'plan_outcomes' => $result->toArray(),
                'resolved_plan_keys' => array_values(array_unique($resolvedKeys)),
                'review' => $reviewedByUserId === null ? [] : [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => $reviewMode,
                ],
            ],
        );

        if ($result->isFullyResolved()) {
            $inboundEmail->status = InboundEmailStatus::Processed;
        }

        $inboundEmail->save();
    }

    /**
     * Record completion of a single plan from the manual-edit workbench. The email only becomes
     * Processed once every importable plan has been resolved (created/merged/edited), so editing
     * one order of a two-order email leaves the other visible in the inbox.
     */
    public function markAsProcessedFromManualReview(
        InboundEmail $inboundEmail,
        ChurchService $churchService,
        int $reviewedByUserId,
        ?string $planKey = null,
    ): void {
        $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];

        $resolvedKeys = Arr::get($metadata, 'resolved_plan_keys', []);
        $resolvedKeys = is_array($resolvedKeys)
            ? array_values(array_filter($resolvedKeys, 'is_string'))
            : [];

        $resolvedKeys[] = $planKey ?? $this->planKeyForService($churchService);
        $resolvedKeys = array_values(array_unique($resolvedKeys));

        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $metadata,
            [
                'imported_church_service_id' => $churchService->id,
                'imported_at' => now()->toIso8601String(),
                'resolved_plan_keys' => $resolvedKeys,
                'review' => [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => 'manual_edit',
                ],
            ],
        );

        if ($this->allImportablePlansResolved($metadata, $resolvedKeys)) {
            $inboundEmail->status = InboundEmailStatus::Processed;
        }

        $inboundEmail->save();
    }

    private function planKeyForService(ChurchService $churchService): string
    {
        return "{$churchService->service->value}:{$churchService->date->toDateString()}";
    }

    /**
     * Legacy single-service parses (no stored service_plans) resolve as soon as any plan is
     * completed. Multi-plan parses require every importable plan key to be resolved.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $resolvedKeys
     */
    private function allImportablePlansResolved(array $metadata, array $resolvedKeys): bool
    {
        $storedPlans = Arr::get($metadata, 'parsing.service_plans');

        if (! is_array($storedPlans)) {
            return true;
        }

        $importableKeys = [];

        foreach ($storedPlans as $storedPlan) {
            if (! is_array($storedPlan)) {
                continue;
            }

            $service = $storedPlan['service'] ?? null;
            $date = $storedPlan['date'] ?? null;
            $items = $storedPlan['items'] ?? null;

            if (is_string($service) && is_string($date) && $date !== '' && is_array($items) && $items !== []) {
                $importableKeys[] = "{$service}:{$date}";
            }
        }

        if ($importableKeys === []) {
            return true;
        }

        return array_diff(array_values(array_unique($importableKeys)), $resolvedKeys) === [];
    }

    /**
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array<string, mixed>  $newMetadata
     * @return array<string, mixed>
     */
    private function mergeProcessingMetadata(?array $existingMetadata, array $newMetadata): array
    {
        $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];

        return array_replace_recursive($existingMetadata, $newMetadata);
    }
}
