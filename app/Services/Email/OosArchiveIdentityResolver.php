<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;

class OosArchiveIdentityResolver
{
    private const OtherServiceEvidenceValidationReason = 'An other service requires explicit special-service evidence; ordinary notices are not a service order.';

    public function resolve(OosArchiveEntry $entry, OosEmailParseResult $parseResult): OosEmailParseResult
    {
        if ($this->hasServiceAssignments($entry)) {
            $parseResult = $this->resolveServiceAssignments($entry, $parseResult);
        } elseif ($this->hasManifestAuthoritativeIdentity($entry)) {
            $parseResult = $this->resolveManifestAuthoritativeIdentity($entry, $parseResult);
        }

        $dateResolved = $this->resolveMissingDates($entry, $parseResult);

        return $this->applyCuratedContentScope($entry, $this->resolveIdentity($entry, $dateResolved));
    }

    private function resolveMissingDates(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
    ): OosEmailParseResult {
        if ($parseResult->servicePlans === []) {
            return $parseResult;
        }

        $hasMissingDate = false;

        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->date !== null && $plan->date !== $entry->groundTruthDate) {
                return $parseResult;
            }

            if ($plan->date === null) {
                $hasMissingDate = true;
            }
        }

        if (! $hasMissingDate) {
            return $parseResult;
        }

        $primaryPlanIndex = $this->primaryPlanIndex($parseResult);
        $plans = array_map(
            fn (OosEmailServicePlan $plan): OosEmailServicePlan => $plan->date === null
                ? $this->withManifestDate($entry, $parseResult, $plan, $entry->groundTruthDate)
                : $plan,
            $parseResult->servicePlans,
        );
        $primary = $primaryPlanIndex === null
            ? $this->matchingPrimaryPlan($parseResult, $plans)
            : ($plans[$primaryPlanIndex] ?? null);

        if (! $primary instanceof OosEmailServicePlan) {
            return $parseResult;
        }

        $metadata = $parseResult->importMetadata;
        $metadata['date_extraction'] = [
            ...($metadata['date_extraction'] ?? []),
            'value' => $primary->date,
            'confidence' => $primary->confidence,
            'method' => 'archive_manifest',
            'plausible' => true,
        ];
        $metadata['service_plans'] = array_map($this->planMetadata(...), $plans);

        return new OosEmailParseResult(
            date: $primary->date,
            service: $primary->service,
            items: $primary->items,
            confidenceScore: $primary->confidence,
            needsReview: $primary->needsReview,
            shouldImport: $primary->shouldImport,
            importMetadata: $metadata,
            servicePlans: $plans,
            isLegacyFlattened: $parseResult->isLegacyFlattened,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $parseResult->extractionAttempts,
            consensus: $parseResult->consensus,
            adjudicated: $parseResult->adjudicated,
            ignoredLines: $parseResult->ignoredLines,
        );
    }

    private function withManifestDate(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        string $date,
    ): OosEmailServicePlan {
        $disposition = $plan->service instanceof SermonService
            ? $this->resolvedDisposition($parseResult, $plan, $plan->service, $plan->confidence, $plan->contentScope)
            : $plan->disposition;

        return new OosEmailServicePlan(
            service: $plan->service,
            date: $date,
            items: $plan->items,
            confidence: $plan->confidence,
            needsReview: $disposition !== OosEmailParseDisposition::AutoImportable,
            shouldImport: $disposition === OosEmailParseDisposition::AutoImportable,
            disposition: $disposition,
            validationReasons: $plan->validationReasons,
            contentValidationReasons: $plan->contentValidationReasons,
            holdReasons: $this->resolvedHoldReasons(
                $plan,
                $disposition,
                $plan->service,
                $date,
                $plan->confidence,
                $plan->contentScope,
                $this->hasNamedSpecialServiceIdentity($entry, $plan->service),
            ),
            sourceProvenance: [
                ...$plan->sourceProvenance,
                'archive_identity' => 'manifest',
            ],
            contentScope: $plan->contentScope,
        );
    }

    private function hasManifestAuthoritativeIdentity(OosArchiveEntry $entry): bool
    {
        return $entry->curation['parse_decision'] === 'manifest-authoritative';
    }

    private function hasServiceAssignments(OosArchiveEntry $entry): bool
    {
        return $this->hasManifestAuthoritativeIdentity($entry)
            && $entry->curation['service_assignments'] !== [];
    }

    /**
     * A manifest service assignment corrects a known historical parser slot,
     * rather than teaching the parser a new time-of-day heuristic. Each plan
     * remains independently bound to the source lines from which it came, and
     * the assignment itself travels in provenance for portable preflight.
     */
    private function resolveServiceAssignments(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
    ): OosEmailParseResult {
        /** @var list<array{source_service:string,resolved_service:string}> $assignments */
        $assignments = $entry->curation['service_assignments'];
        $bySource = [];

        foreach ($assignments as $assignment) {
            $bySource[$assignment['source_service']] = $assignment['resolved_service'];
        }

        $plans = array_map(function (OosEmailServicePlan $plan) use ($bySource, $entry, $parseResult): OosEmailServicePlan {
            if (! $plan->service instanceof SermonService || ! isset($bySource[$plan->service->value])) {
                return $plan;
            }

            $sourceService = $plan->service;
            $resolvedService = SermonService::from($bySource[$sourceService->value]);
            $validationReasons = $this->manifestIdentityValidationReasons($plan, $resolvedService);
            $contentValidationReasons = $this->manifestIdentityValidationReasons($plan, $resolvedService, contentReasons: true);
            $authorityPlan = new OosEmailServicePlan(
                service: $resolvedService,
                date: $plan->date,
                items: $plan->items,
                confidence: $plan->confidence,
                needsReview: true,
                shouldImport: false,
                disposition: OosEmailParseDisposition::ReviewRequired,
                validationReasons: $validationReasons,
                contentValidationReasons: $contentValidationReasons,
                holdReasons: $plan->holdReasons,
                sourceProvenance: $plan->sourceProvenance,
                contentScope: $plan->contentScope,
            );
            $disposition = $this->resolvedDisposition(
                $parseResult,
                $authorityPlan,
                $resolvedService,
                $plan->confidence,
                $plan->contentScope,
            );

            return new OosEmailServicePlan(
                service: $resolvedService,
                date: $plan->date,
                items: $plan->items,
                confidence: $plan->confidence,
                needsReview: $disposition !== OosEmailParseDisposition::AutoImportable,
                shouldImport: $disposition === OosEmailParseDisposition::AutoImportable,
                disposition: $disposition,
                validationReasons: $validationReasons,
                contentValidationReasons: $contentValidationReasons,
                holdReasons: $this->resolvedHoldReasons(
                    $authorityPlan,
                    $disposition,
                    $resolvedService,
                    $plan->date,
                    $plan->confidence,
                    $plan->contentScope,
                    $this->hasNamedSpecialServiceIdentity($entry, $resolvedService),
                ),
                sourceProvenance: [
                    ...$plan->sourceProvenance,
                    'archive_identity' => 'manifest-authoritative',
                    'curated_service_assignment' => [
                        'source_service' => $sourceService->value,
                        'resolved_service' => $resolvedService->value,
                    ],
                ],
                contentScope: $plan->contentScope,
            );
        }, $parseResult->servicePlans);

        $primaryService = SermonService::tryFrom($entry->servicesPresent[0] ?? '');
        $primary = null;

        if ($primaryService instanceof SermonService) {
            foreach ($plans as $plan) {
                if ($plan->service === $primaryService && $plan->items !== []) {
                    $primary = $plan;

                    break;
                }
            }
        }

        if (! $primary instanceof OosEmailServicePlan) {
            return $parseResult;
        }

        return new OosEmailParseResult(
            date: $primary->date,
            service: $primary->service,
            items: $primary->items,
            confidenceScore: $primary->confidence,
            needsReview: $primary->needsReview,
            shouldImport: $primary->shouldImport,
            importMetadata: $this->metadata($entry, $parseResult, $primary),
            servicePlans: $plans,
            isLegacyFlattened: $parseResult->isLegacyFlattened,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $parseResult->extractionAttempts,
            consensus: $parseResult->consensus,
            adjudicated: $parseResult->adjudicated,
            ignoredLines: $parseResult->ignoredLines,
        );
    }

    private function resolveManifestAuthoritativeIdentity(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
    ): OosEmailParseResult {
        $service = SermonService::tryFrom($entry->servicesPresent[0] ?? '');

        if (! $service instanceof SermonService) {
            return $parseResult;
        }

        $plans = array_values(array_filter(
            $parseResult->servicePlans,
            static fn (OosEmailServicePlan $plan): bool => $plan->items !== [] && $plan->service === $service,
        ));

        if (count($plans) === 1) {
            $plan = $plans[0];
        } elseif (count($parseResult->servicePlans) === 1 && $parseResult->servicePlans[0]->items !== []) {
            $plan = $parseResult->servicePlans[0];
        } else {
            return $parseResult;
        }

        $validationReasons = $this->manifestIdentityValidationReasons($plan, $service);
        $contentValidationReasons = $this->manifestIdentityValidationReasons($plan, $service, contentReasons: true);

        $authorityPlan = new OosEmailServicePlan(
            service: $service,
            date: $entry->groundTruthDate,
            items: $plan->items,
            confidence: $plan->confidence,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            validationReasons: $validationReasons,
            contentValidationReasons: $contentValidationReasons,
            holdReasons: $plan->holdReasons,
            sourceProvenance: $plan->sourceProvenance,
            contentScope: $plan->contentScope,
        );
        $disposition = $this->resolvedDisposition($parseResult, $authorityPlan, $service, $plan->confidence, $plan->contentScope);
        $resolvedPlan = new OosEmailServicePlan(
            service: $service,
            date: $entry->groundTruthDate,
            items: $plan->items,
            confidence: $plan->confidence,
            needsReview: $disposition !== OosEmailParseDisposition::AutoImportable,
            shouldImport: $disposition === OosEmailParseDisposition::AutoImportable,
            disposition: $disposition,
            validationReasons: $validationReasons,
            contentValidationReasons: $contentValidationReasons,
            holdReasons: $this->resolvedHoldReasons(
                $authorityPlan,
                $disposition,
                $service,
                $entry->groundTruthDate,
                $plan->confidence,
                $plan->contentScope,
                $this->hasNamedSpecialServiceIdentity($entry, $service),
            ),
            sourceProvenance: [...$plan->sourceProvenance, 'archive_identity' => 'manifest-authoritative'],
            contentScope: $plan->contentScope,
        );

        return new OosEmailParseResult(
            date: $resolvedPlan->date,
            service: $resolvedPlan->service,
            items: $resolvedPlan->items,
            confidenceScore: $resolvedPlan->confidence,
            needsReview: $resolvedPlan->needsReview,
            shouldImport: $resolvedPlan->shouldImport,
            importMetadata: $this->metadata($entry, $parseResult, $resolvedPlan),
            servicePlans: [$resolvedPlan],
            isLegacyFlattened: $parseResult->isLegacyFlattened,
            disposition: $resolvedPlan->disposition,
            validationReasons: $resolvedPlan->validationReasons,
            extractionAttempts: $parseResult->extractionAttempts,
            consensus: $parseResult->consensus,
            adjudicated: $parseResult->adjudicated,
            ignoredLines: $parseResult->ignoredLines,
        );
    }

    /** @return list<string> */
    private function manifestIdentityValidationReasons(
        OosEmailServicePlan $plan,
        SermonService $resolvedService,
        bool $contentReasons = false,
    ): array {
        $reasons = array_values(array_filter(
            $contentReasons ? $plan->contentValidationReasons : $plan->validationReasons,
            'is_string',
        ));

        if ($plan->service !== SermonService::Other || $resolvedService === SermonService::Other) {
            return $reasons;
        }

        return array_values(array_filter(
            $reasons,
            static fn (string $reason): bool => $reason !== self::OtherServiceEvidenceValidationReason,
        ));
    }

    private function primaryPlanIndex(OosEmailParseResult $parseResult): ?int
    {
        foreach ($parseResult->servicePlans as $index => $plan) {
            if ($plan->service === $parseResult->service
                && $plan->date === $parseResult->date
                && $plan->items === $parseResult->items) {
                return $index;
            }
        }

        return null;
    }

    private function resolveIdentity(OosArchiveEntry $entry, OosEmailParseResult $parseResult): OosEmailParseResult
    {
        if (count($parseResult->servicePlans) !== 1) {
            return $parseResult;
        }

        $plan = $parseResult->servicePlans[0];

        if ($plan->items === []) {
            return $parseResult;
        }

        if ($plan->date !== null && $plan->date !== $entry->groundTruthDate) {
            return $parseResult;
        }

        if ($plan->service instanceof SermonService
            && ! in_array($plan->service->value, $entry->servicesPresent, true)) {
            return $parseResult;
        }

        $rejectedService = $plan->sourceProvenance['rejected_service'] ?? null;

        if (is_string($rejectedService) && ! in_array($rejectedService, $entry->servicesPresent, true)) {
            return $parseResult;
        }

        if ($plan->date !== null && $plan->service instanceof SermonService) {
            return $parseResult;
        }

        $service = $plan->service ?? SermonService::tryFrom($entry->servicesPresent[0]);

        if (! $service instanceof SermonService) {
            return $parseResult;
        }

        $confidence = $this->modelConfidence($parseResult, $plan);
        $disposition = $this->resolvedDisposition($parseResult, $plan, $service, $confidence, $plan->contentScope);
        $resolvedPlan = new OosEmailServicePlan(
            service: $service,
            date: $plan->date ?? $entry->groundTruthDate,
            items: $plan->items,
            confidence: $confidence,
            needsReview: $disposition !== OosEmailParseDisposition::AutoImportable,
            shouldImport: $disposition === OosEmailParseDisposition::AutoImportable,
            disposition: $disposition,
            validationReasons: $plan->validationReasons,
            contentValidationReasons: $plan->contentValidationReasons,
            holdReasons: $this->resolvedHoldReasons(
                $plan,
                $disposition,
                $service,
                $plan->date ?? $entry->groundTruthDate,
                $confidence,
                $plan->contentScope,
                $this->hasNamedSpecialServiceIdentity($entry, $service),
            ),
            sourceProvenance: [
                ...$plan->sourceProvenance,
                'archive_identity' => 'manifest',
            ],
            contentScope: $plan->contentScope,
        );

        return new OosEmailParseResult(
            date: $resolvedPlan->date,
            service: $resolvedPlan->service,
            items: $resolvedPlan->items,
            confidenceScore: $resolvedPlan->confidence,
            needsReview: $resolvedPlan->needsReview,
            shouldImport: $resolvedPlan->shouldImport,
            importMetadata: $this->metadata($entry, $parseResult, $resolvedPlan),
            servicePlans: [$resolvedPlan],
            isLegacyFlattened: $parseResult->isLegacyFlattened,
            disposition: $resolvedPlan->disposition,
            validationReasons: $resolvedPlan->validationReasons,
            extractionAttempts: $parseResult->extractionAttempts,
            consensus: $parseResult->consensus,
            adjudicated: $parseResult->adjudicated,
            ignoredLines: $parseResult->ignoredLines,
        );
    }

    private function applyCuratedContentScope(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
    ): OosEmailParseResult {
        $contentScope = OosEmailContentScope::from($entry->contentScope);
        $plans = array_map(
            fn (OosEmailServicePlan $plan): OosEmailServicePlan => $plan->date === $entry->groundTruthDate
                && $plan->service instanceof SermonService
                && in_array($plan->service->value, $entry->servicesPresent, true)
                    ? $this->withCuratedContentScope($entry, $parseResult, $plan, $contentScope)
                    : $plan,
            $parseResult->servicePlans,
        );

        if ($plans === []) {
            return $parseResult;
        }

        $primary = $this->matchingPrimaryPlan($parseResult, $plans);

        if (! $primary instanceof OosEmailServicePlan) {
            return $parseResult;
        }

        $metadata = $parseResult->importMetadata;
        $metadata['service_plans'] = array_map($this->planMetadata(...), $plans);

        return new OosEmailParseResult(
            date: $primary->date,
            service: $primary->service,
            items: $primary->items,
            confidenceScore: $primary->confidence,
            needsReview: $primary->needsReview,
            shouldImport: $primary->shouldImport,
            importMetadata: $metadata,
            servicePlans: $plans,
            isLegacyFlattened: $parseResult->isLegacyFlattened,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $parseResult->extractionAttempts,
            consensus: $parseResult->consensus,
            adjudicated: $parseResult->adjudicated,
            ignoredLines: $parseResult->ignoredLines,
        );
    }

    private function withCuratedContentScope(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        OosEmailContentScope $contentScope,
    ): OosEmailServicePlan {
        if (! $plan->service instanceof SermonService) {
            return $plan;
        }

        $disposition = $this->resolvedDisposition($parseResult, $plan, $plan->service, $plan->confidence, $contentScope);

        return new OosEmailServicePlan(
            service: $plan->service,
            date: $plan->date,
            items: $plan->items,
            confidence: $plan->confidence,
            needsReview: $disposition !== OosEmailParseDisposition::AutoImportable,
            shouldImport: $disposition === OosEmailParseDisposition::AutoImportable,
            disposition: $disposition,
            validationReasons: $plan->validationReasons,
            contentValidationReasons: $plan->contentValidationReasons,
            holdReasons: $this->resolvedHoldReasons(
                $plan,
                $disposition,
                $plan->service,
                $plan->date,
                $plan->confidence,
                $contentScope,
                $this->hasNamedSpecialServiceIdentity($entry, $plan->service),
            ),
            sourceProvenance: $plan->sourceProvenance,
            contentScope: $contentScope,
        );
    }

    /**
     * @param  list<OosEmailServicePlan>  $plans
     */
    private function matchingPrimaryPlan(OosEmailParseResult $parseResult, array $plans): ?OosEmailServicePlan
    {
        foreach ($plans as $plan) {
            if ($plan->date === $parseResult->date && $plan->service === $parseResult->service) {
                return $plan;
            }
        }

        return $plans[0] ?? null;
    }

    private function modelConfidence(OosEmailParseResult $parseResult, OosEmailServicePlan $plan): float
    {
        foreach ($parseResult->extractionAttempts as $attempt) {
            if (($attempt['selected'] ?? false) !== true || ! is_array($attempt['plans'] ?? null)) {
                continue;
            }

            $candidate = $attempt['plans'][0]['confidence'] ?? null;

            if (is_numeric($candidate)) {
                return round(max(0.0, min(1.0, (float) $candidate)), 2);
            }
        }

        return $plan->confidence;
    }

    private function hasNamedSpecialServiceIdentity(OosArchiveEntry $entry, ?SermonService $service): bool
    {
        return $service === SermonService::Other
            && is_string($entry->curation['service_label'] ?? null)
            && trim($entry->curation['service_label']) !== '';
    }

    /**
     * `$contentScope` is the scope the replacement plan will carry, which is not always the one
     * the plan arrives with: applying a curated scope replaces an extractor `unknown` with the
     * completeness the manifest establishes. Classifying against the arriving scope would hold
     * the plan on a value the same call is discarding (F63).
     */
    /**
     * The hold census after identity resolution: extraction-owned reasons carry over untouched,
     * identity-owned ones are recomputed against the resolved date, confidence and scope.
     *
     * @return list<OosEmailPlanHoldReason>
     */
    private function resolvedHoldReasons(
        OosEmailServicePlan $plan,
        OosEmailParseDisposition $disposition,
        ?SermonService $service,
        ?string $date,
        float $confidence,
        OosEmailContentScope $contentScope,
        bool $hasNamedSpecialServiceIdentity = false,
    ): array {
        if ($disposition === OosEmailParseDisposition::AutoImportable) {
            return [];
        }

        $reasons = array_values(array_filter(
            $plan->holdReasons,
            static fn (OosEmailPlanHoldReason $reason): bool => $reason->ownedByExtraction(),
        ));

        if ($contentScope === OosEmailContentScope::Unknown) {
            $reasons[] = OosEmailPlanHoldReason::UnknownContentScope;
        }

        if ($service === null || $date === null || ($service === SermonService::Other && ! $hasNamedSpecialServiceIdentity)) {
            $reasons[] = OosEmailPlanHoldReason::MissingIdentity;
        }

        if ($confidence < (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90)) {
            $reasons[] = OosEmailPlanHoldReason::LowConfidence;
        }

        return $reasons;
    }

    private function resolvedDisposition(
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
        SermonService $service,
        float $confidence,
        OosEmailContentScope $contentScope,
    ): OosEmailParseDisposition {
        if ($plan->disposition === OosEmailParseDisposition::InvalidExtraction || $plan->validationReasons !== []) {
            return $plan->disposition;
        }

        if ($contentScope === OosEmailContentScope::Unknown) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        if ($service === SermonService::Other) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);

        if ($confidence >= $autoImportThreshold) {
            return OosEmailParseDisposition::AutoImportable;
        }

        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);

        if ($parseResult->consensus && $confidence >= $reviewThreshold) {
            return OosEmailParseDisposition::AutoImportable;
        }

        return OosEmailParseDisposition::ReviewRequired;
    }

    /** @return array<string, mixed> */
    private function metadata(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        OosEmailServicePlan $plan,
    ): array {
        $metadata = $parseResult->importMetadata;
        $metadata['confidence_score'] = $plan->confidence;
        $metadata['date_extraction'] = [
            ...($metadata['date_extraction'] ?? []),
            'value' => $plan->date,
            'confidence' => $plan->confidence,
            'method' => 'archive_manifest',
            'plausible' => true,
        ];
        $metadata['service_extraction'] = [
            ...($metadata['service_extraction'] ?? []),
            'value' => $plan->service?->value,
            'confidence' => $plan->confidence,
            'method' => 'archive_manifest',
        ];
        $metadata['service_plans'] = [$this->planMetadata($plan)];
        $metadata['archive_identity'] = [
            'method' => 'manifest',
            'source_key' => $entry->sourceKey,
            'inherited_from_predecessor' => $entry->supersedesSourceKey !== null,
        ];

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function planMetadata(OosEmailServicePlan $plan): array
    {
        return $plan->toMetadataArray();
    }
}
