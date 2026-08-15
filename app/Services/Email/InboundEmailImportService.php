<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\OosEmailImportPlanOutcome;
use App\Data\OosEmailImportResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailImportOutcome;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceCompatibilityMergeDecision;
use App\Services\ChurchService\ChurchServiceIdentityResolver;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use App\Services\ChurchService\SourceAdapters\EmailSourceAdapter;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class InboundEmailImportService
{
    use SanitizesLogData;

    public function __construct(
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceStructureMergeService $mergeService,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly EmailSourceAdapter $sourceAdapter,
        private readonly ChurchServiceCompatibilityMergeDecision $compatibilityMerge,
        private readonly ChurchServiceIdentityResolver $identityResolver,
    ) {}

    /**
     * The storable form of a parse result.
     *
     * Split out from `storeParseResult()` so a caller that needs to keep a parse
     * somewhere other than the `parsing` block can use the same encoding rather
     * than inventing a second one — the archive's raw-extraction cache is the
     * one caller that does. {@see decodeParseResult()} is its exact inverse.
     *
     * @return array<string, mixed>
     */
    public function encodeParseResult(OosEmailParseResult $parseResult): array
    {
        // The validation fields are written from the result's own properties rather than trusted
        // to be present in importMetadata: a stored disposition is what tells a later restore
        // that the parse was checked by OosEmailExtractionValidator at all. They are removed from
        // the parser's bag first because array_replace_recursive merges lists by index, so a
        // shorter validation_reasons would otherwise leave the parser's stale tail behind.
        $authoritative = [
            'resolved_date' => $parseResult->date,
            'resolved_service' => $parseResult->service?->value,
            'items' => $parseResult->items,
            'needs_review' => $parseResult->needsReview,
            'should_import' => $parseResult->shouldImport,
            'disposition' => $parseResult->disposition->value,
            'validation_reasons' => $parseResult->validationReasons,
            'consensus' => $parseResult->consensus,
            'adjudicated' => $parseResult->adjudicated,
        ];

        return array_replace(
            Arr::except($parseResult->importMetadata, array_keys($authoritative)),
            $authoritative,
        );
    }

    public function storeParseResult(InboundEmail $inboundEmail, OosEmailParseResult $parseResult, bool $isReparse = false): void
    {
        $metadata = ['parsing' => $this->encodeParseResult($parseResult)];

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

        return $this->decodeParseResult(Arr::get($processingMetadata, 'parsing'));
    }

    /**
     * Rebuild a parse result from anything {@see encodeParseResult()} wrote.
     */
    public function decodeParseResult(mixed $storedParseData): ?OosEmailParseResult
    {
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
            disposition: $this->storedDisposition(Arr::get($storedParseData, 'disposition')),
            validationReasons: $this->storedStrings(Arr::get($storedParseData, 'validation_reasons')),
            extractionAttempts: $this->storedAttempts(Arr::get($storedParseData, 'extraction_attempts')),
            consensus: (bool) Arr::get($storedParseData, 'consensus', false),
            adjudicated: (bool) Arr::get($storedParseData, 'adjudicated', false),
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
        return [new OosEmailServicePlan(
            service: $service,
            date: $date,
            items: $items,
            confidence: $confidence,
            needsReview: $needsReview,
            shouldImport: $shouldImport,
            // A flattened payload predates per-plan dispositions entirely, so it can never be
            // auto-imported unattended; ApproveInboundEmailImport re-parses it for an admin.
            disposition: OosEmailParseDisposition::ReviewRequired,
        )];
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
                disposition: $this->storedDisposition($storedPlan['disposition'] ?? null),
                validationReasons: $this->storedStrings($storedPlan['validation_reasons'] ?? null),
                contentValidationReasons: $this->storedStrings($storedPlan['content_validation_reasons'] ?? null),
                holdReasons: $this->storedHoldReasons($storedPlan['hold_reasons'] ?? null),
                sourceProvenance: is_array($storedPlan['source_provenance'] ?? null)
                    ? $storedPlan['source_provenance']
                    : [],
                contentScope: is_string($storedPlan['content_scope'] ?? null)
                    ? OosEmailContentScope::tryFrom($storedPlan['content_scope']) ?? OosEmailContentScope::Unknown
                    : OosEmailContentScope::Full,
            );
        }

        return $plans;
    }

    /**
     * The whitelist is every field {@see OosEmailParserService::normaliseItems()}
     * emits, and it has to stay that way: anything omitted here is dropped from
     * a restored parse, so it survives the first import and disappears from
     * every one made from the stored payload afterwards.
     *
     * `section_type` used to be missing, which
     * {@see ChurchServiceAssertionNormalizer} reads
     * when a parse becomes canonical assertions. The archive's raw-extraction
     * cache found it, because a decoded parse then resolved differently from
     * the one it was encoded from.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array{position:int,type:string,section_type:?string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
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
            $sectionType = $item['section_type'] ?? null;
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
                'section_type' => is_string($sectionType) ? $sectionType : null,
                'title' => $title,
                'source_title' => is_string($sourceTitle) ? $sourceTitle : null,
                'openlp_search_title' => is_string($openLpSearchTitle) ? $openLpSearchTitle : null,
                'metadata' => is_array($metadata) ? $metadata : null,
            ];
        }

        return $storedItems;
    }

    /**
     * Import every service plan in the parse result: each plan merges into the existing service
     * for its date+service slot, or creates one. The email is only marked Processed when every
     * plan reaches a terminal outcome.
     *
     * Pass `$onlyPlanKeys` to restrict the import to specific plans — the archive backfill uses
     * this to import only the plans its ground truth actually corroborates, so an extra plan the
     * parser invents for a single-service entry is never created.
     *
     * @param  list<string>|null  $onlyPlanKeys
     *
     * @throws InvalidArgumentException
     */
    public function import(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        ?int $reviewedByUserId = null,
        string $reviewMode = 'direct_approve',
        ?array $onlyPlanKeys = null,
        ?string $sourceInputHash = null,
        ?OosEmailContentScope $reviewedContentScope = null,
    ): OosEmailImportResult {
        $plans = $this->plansForImport($parseResult);

        if ($onlyPlanKeys !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (OosEmailServicePlan $plan): bool => in_array($plan->key(), $onlyPlanKeys, true),
            ));
        }

        if ($plans === []) {
            throw new InvalidArgumentException('Inbound email parse result has no service plans to import.');
        }

        $outcomes = [];

        foreach ($plans as $plan) {
            $outcomes[] = $this->importPlan(
                $inboundEmail,
                $parseResult,
                $plan,
                $reviewedByUserId,
                $reviewMode,
                $sourceInputHash,
                $reviewedContentScope,
            );
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
                disposition: $parseResult->disposition,
                validationReasons: $parseResult->validationReasons,
            )];
        }

        return [];
    }

    private function importPlan(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        ?int $reviewedByUserId,
        string $reviewMode,
        ?string $sourceInputHash,
        ?OosEmailContentScope $reviewedContentScope,
    ): OosEmailImportPlanOutcome {
        if ($reviewedByUserId !== null && $reviewedContentScope instanceof OosEmailContentScope) {
            $plan = $plan->withContentScope($reviewedContentScope);
        }

        // An admin approval imports any well-formed plan. The automated pipeline imports plans
        // confident enough to auto-import outright, and — per REV-D2 — also imports a
        // `ReviewRequired` plan whose identity is trustworthy, as unreviewed, unfinalised
        // evidence; everything else (identity failures, content-invalid extractions, a legacy
        // pre-validator parse) still holds for a human.
        $ready = $reviewedByUserId !== null
            ? $plan->isManuallyImportable()
            : ($plan->isAutoImportable() || $plan->isEvidenceImportable());

        if (! $ready || ! $plan->isImportable()) {
            return new OosEmailImportPlanOutcome(
                $plan->key(),
                $plan->service,
                $plan->date,
                OosEmailImportOutcome::HeldForReview,
            );
        }

        if ($plan->contentScope === OosEmailContentScope::Unknown) {
            return new OosEmailImportPlanOutcome(
                $plan->key(),
                $plan->service,
                $plan->date,
                OosEmailImportOutcome::HeldForReview,
            );
        }

        $importMetadata = $this->planImportMetadata($parseResult, $plan, $reviewedByUserId, $reviewMode);

        try {
            return $this->mergeOrCreatePlan($inboundEmail, $plan, $importMetadata, $reviewedByUserId, $sourceInputHash);
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
     * A stored parse with no disposition predates OosEmailExtractionValidator, so it carries no
     * source-line grounding and no validation reasons — its confidence was never checked against
     * the email body. Never infer AutoImportable from that: an admin can still approve it, and a
     * re-parse replaces the payload outright.
     */
    private function storedDisposition(mixed $value): OosEmailParseDisposition
    {
        if (is_string($value)) {
            $disposition = OosEmailParseDisposition::tryFrom($value);

            if ($disposition instanceof OosEmailParseDisposition) {
                return $disposition;
            }
        }

        return OosEmailParseDisposition::ReviewRequired;
    }

    /**
     * @return list<string>
     */
    private function storedStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @return list<OosEmailPlanHoldReason>
     */
    private function storedHoldReasons(mixed $values): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?OosEmailPlanHoldReason => OosEmailPlanHoldReason::tryFrom($value),
            $this->storedStrings($values),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedAttempts(mixed $attempts): array
    {
        if (! is_array($attempts)) {
            return [];
        }

        return array_values(array_filter($attempts, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function mergeOrCreatePlan(
        InboundEmail $inboundEmail,
        OosEmailServicePlan $plan,
        array $importMetadata,
        ?int $reviewedByUserId,
        ?string $sourceInputHash,
    ): OosEmailImportPlanOutcome {
        /** @var SermonService $service */
        $service = $plan->service;

        $existingService = $this->identityResolver->resolve((string) $plan->date, $service);

        if ($plan->contentScope === OosEmailContentScope::Partial) {
            return $this->retainPlanEvidence(
                $inboundEmail,
                $plan,
                $service,
                $existingService,
                $importMetadata,
                $reviewedByUserId,
                $sourceInputHash,
            );
        }

        if ($existingService instanceof ChurchService) {
            return $this->mergePlanIntoExistingService($inboundEmail, $plan, $existingService, $importMetadata, $reviewedByUserId, $sourceInputHash);
        }

        $churchService = $this->createNewServiceFromPlan($inboundEmail, $plan, $service, $importMetadata, $reviewedByUserId, $sourceInputHash);

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
    private function retainPlanEvidence(
        InboundEmail $inboundEmail,
        OosEmailServicePlan $plan,
        SermonService $service,
        ?ChurchService $existingService,
        array $importMetadata,
        ?int $reviewedByUserId,
        ?string $sourceInputHash,
    ): OosEmailImportPlanOutcome {
        $churchService = DB::transaction(function () use ($inboundEmail, $plan, $service, $existingService, $importMetadata, $sourceInputHash): ChurchService {
            $churchService = $existingService ?? ChurchService::query()->firstOrNew([
                'date' => $plan->date,
                'service' => $service->value,
            ]);
            $existingMetadata = $churchService->import_metadata?->toArray() ?? [];

            $churchService->fill([
                'source' => $churchService->exists
                    ? $churchService->source
                    : ChurchServiceItemSource::Email->value,
                'needs_review' => $churchService->exists
                    ? $churchService->needs_review
                    : false,
                'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
            ]);
            $churchService->save();

            $this->ingestSourceRevision->execute(
                $churchService,
                $this->sourceAdapter->adapt($inboundEmail, $plan, $sourceInputHash),
                project: false,
            );

            return $churchService->fresh(['items']) ?? $churchService;
        });

        Log::info('Incomplete church service evidence retained from email', $this->sanitizeArrayForLog([
            'admin_id' => $reviewedByUserId,
            'church_service_id' => $churchService->id,
            'plan_key' => $plan->key(),
        ]));

        return new OosEmailImportPlanOutcome(
            $plan->key(),
            $service,
            $plan->date,
            OosEmailImportOutcome::EvidenceRetained,
            $churchService,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function createNewServiceFromPlan(
        InboundEmail $inboundEmail,
        OosEmailServicePlan $plan,
        SermonService $service,
        array $importMetadata,
        ?int $reviewedByUserId,
        ?string $sourceInputHash,
    ): ChurchService {
        $churchService = DB::transaction(function () use ($inboundEmail, $plan, $service, $importMetadata, $reviewedByUserId, $sourceInputHash): ChurchService {
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

            $this->ingestSourceRevision->execute(
                $churchService,
                $this->sourceAdapter->adapt($inboundEmail, $plan, $sourceInputHash),
            );
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

        return $churchService;
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function mergePlanIntoExistingService(
        InboundEmail $inboundEmail,
        OosEmailServicePlan $plan,
        ChurchService $existingService,
        array $importMetadata,
        ?int $reviewedByUserId,
        ?string $sourceInputHash,
    ): OosEmailImportPlanOutcome {
        $mergeResult = DB::transaction(function () use ($inboundEmail, $plan, $existingService, $importMetadata, $reviewedByUserId, $sourceInputHash): StructureMergeResult {
            $existingMetadata = $existingService->import_metadata?->toArray() ?? [];
            $existingService->fill([
                'needs_review' => $reviewedByUserId === null ? $plan->needsReview : false,
                'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
            ]);
            $existingService->save();

            $hadCanonicalItems = $existingService->items()->exists();
            $usesCompatibilityMerge = $this->compatibilityMerge->usesCompatibilityMerge($existingService);
            $ingestion = $this->ingestSourceRevision->execute(
                $existingService,
                $this->sourceAdapter->adapt($inboundEmail, $plan, $sourceInputHash),
                project: ! $usesCompatibilityMerge,
            );

            if (! $usesCompatibilityMerge) {
                $churchService = $existingService->fresh(['items']) ?? $existingService;
                $wasStaged = $churchService->mergeProposals()
                    ->where('trigger_source_record_id', $ingestion->sourceRecord->id)
                    ->where('status', ChurchServiceProposalStatus::Pending)
                    ->exists();
                $this->songLinker->linkForService($churchService);

                return new StructureMergeResult(
                    churchService: $churchService,
                    incomingSource: ChurchServiceItemSource::Email,
                    wasMerged: ! $wasStaged,
                    wasStaged: $wasStaged,
                );
            }

            $mergeResult = $this->mergeService->merge(
                $existingService,
                $plan->items,
                ChurchServiceItemSource::Email,
            );

            if ($mergeResult->wasMerged && ! $hadCanonicalItems) {
                $mergeResult->churchService->forceFill([
                    'source' => ChurchServiceItemSource::Email->value,
                ])->saveQuietly();
            }

            $this->songLinker->linkForService($mergeResult->churchService);

            return $mergeResult;
        });

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
            $mergeResult->wasStaged
                ? OosEmailImportOutcome::HeldForReview
                : OosEmailImportOutcome::Merged,
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
                'content_scope' => $plan->contentScope->value,
                'disposition' => $plan->disposition->value,
                'hold_reasons' => $plan->holdReasonValues(),
                /**
                 * REV-D2: whether this import cleared the full auto-import bar (`true`) or was
                 * admitted only as unreviewed source evidence because its identity is trustworthy
                 * (`false`). An admin approval always finalises what it approves. Read by release
                 * eligibility — a service still carrying unfinalised email evidence is not
                 * release-eligible.
                 */
                'finalised' => $reviewedByUserId !== null || $plan->disposition === OosEmailParseDisposition::AutoImportable,
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

        if ($result->isFullyResolved() || ($reviewedByUserId !== null && $result->isResolvedForEmail())) {
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

        $this->stampSourceMessageId($churchService, $inboundEmail);

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

    /**
     * Record which email a service came from when it was built through the manual-edit
     * workbench. The automated path gets this from the parse metadata it copies wholesale;
     * without it here, a hand-reviewed import leaves no provenance for
     * {@see ExistingEmailImportLookup} to recognise on a later email for the same date.
     */
    private function stampSourceMessageId(ChurchService $churchService, InboundEmail $inboundEmail): void
    {
        $existingMetadata = $churchService->import_metadata?->toArray() ?? [];

        if (($existingMetadata['source_message_id'] ?? null) === $inboundEmail->message_id) {
            return;
        }

        $churchService->forceFill([
            'import_metadata' => array_replace_recursive($existingMetadata, [
                'source_message_id' => $inboundEmail->message_id,
            ]),
        ])->saveQuietly();
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
