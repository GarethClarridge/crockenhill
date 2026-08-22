<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailExtractionValidationResult;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Data\OosEmailStructuralFinding;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\InboundEmail;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use Carbon\CarbonImmutable;
use Throwable;

class OosEmailParserService
{
    private OosEmailExtractionValidator $extractionValidator;

    public function __construct(
        private readonly ExistingEmailImportLookup $existingEmailImports,
        private readonly ServiceItemTitleCleaner $titleCleaner,
        private readonly OosSemanticParserCandidate $semanticParser,
        ?OosEmailExtractionValidator $extractionValidator = null,
    ) {
        $this->extractionValidator = $extractionValidator ?? new OosEmailExtractionValidator;
    }

    public function parse(InboundEmail $inboundEmail): OosEmailParseResult
    {
        $body = $this->preferredBody($inboundEmail);
        $receivedAt = CarbonImmutable::instance($inboundEmail->received_at);
        $source = OosEmailSourceDocument::fromContext(
            $inboundEmail->subject,
            $body,
            $receivedAt->toDateString(),
        );

        $semanticOutcome = $this->semanticParser->parse($source);
        $extraction = $this->guardUnsupportedEveningPlans($source, $semanticOutcome->extraction, $inboundEmail->subject);
        $validation = $this->extractionValidator->validate($source, $extraction, $inboundEmail->subject);
        $attempts = $semanticOutcome->attempts;
        $attempts[0]['compatibility_validation'] = $this->attemptMetadata(1, $extraction, $validation, true);
        $semanticRiskSignals = $semanticOutcome->riskSignals;
        $semanticRiskSignals['content_validator_findings'] = [
            'content' => $validation->contentRuleCodes(),
            'bookkeeping' => $validation->bookkeepingRuleCodes(),
        ];

        /**
         * Permanently false since the legacy whole-document path was deleted in Delivery 7.
         *
         * They are retained on {@see OosEmailParseResult} rather than removed because they are
         * downstream *policy* inputs, not parser outputs: `OosArchiveIdentityResolver` gates
         * unattended import on `consensus`, and `OosArchiveAssertionBundle` writes both into a
         * portable format that older bundles are read back from. §3 of the redesign plan puts that
         * policy and those deletion-scheduled one-shots outside this plan's authority, and §5.1
         * forbids silently changing a portable format. Retiring the fields belongs to IC8 closeout
         * alongside the bundle format, not to the parser deletion.
         *
         * Semantic repair does not resurrect them. Under HIR-D6 an arbitrating call must never set
         * `consensus`, and the semantic path has no second whole-document opinion to agree with.
         */
        $consensus = false;
        $adjudicated = false;
        $validAttemptsDisagree = false;

        $warnings = $extraction->notes;
        $validations = [];
        $servicePlans = $this->buildServicePlans(
            $extraction,
            $source,
            $validation,
            $inboundEmail->subject,
            $inboundEmail->message_id,
            $warnings,
            $validations,
            $consensus,
            $validAttemptsDisagree,
        );
        $primary = $this->primaryPlan($servicePlans);
        $primaryDate = $primary->date;
        $primaryService = $primary->service;
        $primaryItems = $primary->items;
        $primaryConfidence = $primary->confidence;

        if ($primaryDate === null) {
            $warnings[] = 'Could not confidently infer the service date from the email.';
        }

        if ($primaryService === null) {
            $warnings[] = 'Could not confidently infer the service type from the email.';
        }

        // Reused rather than recomputed: the duplicate-import half of the check is a query.
        $primaryPlausibility = $validations[$primary->key()] ?? $this->validateDatePlausibility(
            $primaryDate,
            $inboundEmail->subject,
            $inboundEmail->message_id,
        );

        return new OosEmailParseResult(
            date: $primaryDate,
            service: $primaryService,
            items: $primaryItems,
            confidenceScore: $primaryConfidence,
            needsReview: $primary->needsReview,
            shouldImport: $primary->shouldImport,
            importMetadata: [
                'confidence_score' => $primaryConfidence,
                'parse_method' => 'email_llm',
                'warnings' => array_values(array_unique($warnings)),
                'date_extraction' => [
                    'value' => $primaryDate,
                    'confidence' => $primaryDate === null ? 0.0 : $primaryConfidence,
                    'method' => $primaryDate === null ? null : 'llm',
                    'plausible' => $primaryPlausibility['plausible'],
                    'suggested_date' => $primaryPlausibility['suggested_date'],
                    'implausibility_reasons' => $primaryPlausibility['reasons'],
                ],
                'service_extraction' => [
                    'value' => $primaryService?->value,
                    'confidence' => $primaryService === null ? 0.0 : $primaryConfidence,
                    'method' => $primaryService === null ? null : 'llm',
                ],
                'item_extraction' => [
                    'confidence' => round($extraction->confidence, 2),
                    'item_count' => count($primaryItems),
                    'notes' => $extraction->notes,
                ],
                'service_plans' => $this->servicePlansMetadata($servicePlans),
                'disposition' => $primary->disposition->value,
                'validation_reasons' => $primary->validationReasons,
                'extraction_attempts' => $attempts,
                'consensus' => $consensus,
                'adjudicated' => $adjudicated,
                'source_message_id' => $inboundEmail->message_id,
                'source_subject' => $inboundEmail->subject,
                'parser_implementation' => 'semantic_annotations',
                'risk_signals' => $semanticRiskSignals,
            ],
            servicePlans: $servicePlans,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $attempts,
            consensus: $consensus,
            adjudicated: $adjudicated,
            ignoredLines: $extraction->ignoredLines,
        );
    }

    private function guardUnsupportedEveningPlans(
        OosEmailSourceDocument $source,
        OosEmailItemExtractionResult $extraction,
        string $subject,
    ): OosEmailItemExtractionResult {
        if ($extraction->services === []) {
            return $extraction;
        }

        $serviceCount = count($extraction->services);
        $services = array_map(function (array $plan) use ($source, $subject, $serviceCount): array {
            $evidenceLineIds = $plan['service_evidence_line_ids'] ?? [];

            if ($evidenceLineIds === []) {
                $evidenceLineIds = $source->lineIds();
            }

            $hasSubjectEvidence = $serviceCount === 1 && $this->containsEveningEvidence($subject);

            if (($plan['service'] ?? null) !== 'evening'
                || $this->hasEveningEvidence($source, $evidenceLineIds)
                || $hasSubjectEvidence) {
                return $plan;
            }

            $plan['service'] = 'unknown';
            $plan['rejected_service'] = 'evening';

            return $plan;
        }, $extraction->services);

        return new OosEmailItemExtractionResult(
            items: $extraction->items,
            confidence: $extraction->confidence,
            notes: $extraction->notes,
            services: $services,
            serviceCount: $extraction->serviceCount,
            ignoredLines: $extraction->ignoredLines,
            provenanceComplete: $extraction->provenanceComplete,
        );
    }

    /** @param list<int> $lineIds */
    private function hasEveningEvidence(OosEmailSourceDocument $source, array $lineIds): bool
    {
        foreach ($lineIds as $lineId) {
            $line = $source->line($lineId);

            if (is_string($line) && $this->containsEveningEvidence($line)) {
                return true;
            }
        }

        return false;
    }

    private function containsEveningEvidence(string $text): bool
    {
        return preg_match('/(?:\bafternoon\b|\bevening\b|\btonight\b|\bpm\b|\b(?:1[6-9]|2[0-3])[:.]\d{2}\b|\b(?:5|6|7|8|9)\s*(?:[.:]\d{2}\s*)?pm\b)/iu', $text) === 1;
    }

    /**
     * @param  list<string>  $warnings
     * @param  array<string, array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}>  $validations
     * @return non-empty-list<OosEmailServicePlan>
     */
    private function buildServicePlans(
        OosEmailItemExtractionResult $extraction,
        OosEmailSourceDocument $source,
        OosEmailExtractionValidationResult $validation,
        string $subject,
        ?string $sourceMessageId,
        array &$warnings,
        array &$validations,
        bool $consensus,
        bool $validAttemptsDisagree,
    ): array {
        if ($extraction->services === []) {
            return [$this->buildPlan(
                0,
                null,
                null,
                'unknown',
                null,
                $extraction->items,
                $extraction->confidence,
                [],
                $source,
                $extraction->provenanceComplete,
                $validation,
                $subject,
                $sourceMessageId,
                $warnings,
                $validations,
                $consensus,
                $validAttemptsDisagree,
            )];
        }

        $plans = [];

        foreach ($extraction->services as $planIndex => $rawPlan) {
            $plans[] = $this->buildPlan(
                $planIndex,
                $rawPlan['service'],
                $rawPlan['date'],
                $rawPlan['content_scope'] ?? 'full',
                $rawPlan['rejected_service'] ?? null,
                $rawPlan['items'],
                $rawPlan['confidence'],
                $rawPlan['service_evidence_line_ids'] ?? [],
                $source,
                $extraction->provenanceComplete,
                $validation,
                $subject,
                $sourceMessageId,
                $warnings,
                $validations,
                $consensus,
                $validAttemptsDisagree,
            );
        }

        return $plans;
    }

    /**
     * The whole validation result is passed rather than the reason lists it derives, because the
     * two lists are a subset relation and the caller used to hand the superset to a parameter
     * named `$structuralReasons` — labelling every content-invalid plan a bookkeeping hold as well.
     * Asking the result for each list by name makes that class of mix-up unavailable here.
     *
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool,semantic_kind?:?string}>  $rawItems
     * @param  list<int>  $serviceEvidenceLineIds
     * @param  list<string>  $warnings
     * @param  array<string, array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}>  $validations
     */
    private function buildPlan(
        int $planIndex,
        ?string $rawService,
        ?string $rawDate,
        string $rawContentScope,
        ?string $rejectedService,
        array $rawItems,
        float $rawConfidence,
        array $serviceEvidenceLineIds,
        OosEmailSourceDocument $source,
        bool $provenanceComplete,
        OosEmailExtractionValidationResult $validation,
        string $subject,
        ?string $sourceMessageId,
        array &$warnings,
        array &$validations,
        bool $consensus,
        bool $validAttemptsDisagree,
    ): OosEmailServicePlan {
        $contentReasons = $validation->contentReasonsForPlan($planIndex);
        $structuralReasons = $validation->structuralReasonsForPlan($planIndex);
        $service = $this->validatedService($rawService);
        $date = $this->validatedDate($rawDate);
        $contentScope = OosEmailContentScope::tryFrom($rawContentScope) ?? OosEmailContentScope::Unknown;
        $items = $this->normaliseItems($rawItems, $source, $provenanceComplete);
        $confidence = round(max(0.0, min(1.0, $rawConfidence)), 2);

        if ($rawDate !== null && $date === null) {
            $warnings[] = "The extracted service date '{$rawDate}' is not a valid YYYY-MM-DD date.";
        }

        if ($rawService !== null && $service === null) {
            $warnings[] = "The extracted service type '{$rawService}' is not recognised.";
        }

        if ($date === null || $service === null) {
            $confidence = min($confidence, 0.74);
        }

        if ($items === []) {
            $confidence = min($confidence, 0.40);
        }

        $plausibility = $this->validateDatePlausibility($date, $subject, $sourceMessageId, $service);

        if (! $plausibility['plausible']) {
            $confidence = min($confidence, 0.74);
            $warnings = array_merge($warnings, $plausibility['warnings']);
        }

        $confidence = round($confidence, 2);
        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $validationReasons = $validation->reasonsForPlan($planIndex);

        if ($items === []) {
            $validationReasons[] = 'The service order contains no extractable items.';
        }

        $validationReasons = array_values(array_unique($validationReasons));
        $disposition = $this->planDisposition(
            $service,
            $date,
            $items,
            $confidence,
            $contentReasons,
            $structuralReasons,
            $reviewThreshold,
            $autoImportThreshold,
            $consensus,
            $validAttemptsDisagree,
            $contentScope,
        );
        if ($validAttemptsDisagree) {
            $validationReasons[] = 'Two valid extraction attempts disagreed about the service structure.';
        }
        $validationReasons = array_values(array_unique($validationReasons));
        $holdReasons = $this->planHoldReasons(
            $disposition,
            $service,
            $date,
            $items,
            $confidence,
            $contentReasons,
            $structuralReasons,
            $autoImportThreshold,
            $validAttemptsDisagree,
            $contentScope,
        );
        $shouldImport = $disposition === OosEmailParseDisposition::AutoImportable;
        $needsReview = $disposition !== OosEmailParseDisposition::AutoImportable;

        if ($date !== null && $service !== null) {
            $validations["{$service->value}:{$date}"] = $plausibility;
        }

        $warnings = array_merge($warnings, $validationReasons);

        return new OosEmailServicePlan(
            service: $service,
            date: $date,
            items: $items,
            confidence: $confidence,
            needsReview: $needsReview,
            shouldImport: $shouldImport,
            disposition: $disposition,
            validationReasons: $validationReasons,
            contentValidationReasons: $contentReasons,
            holdReasons: $holdReasons,
            sourceProvenance: [
                'plan_index' => $planIndex,
                'rejected_service' => $rejectedService,
                'service_evidence_line_ids' => $this->integerLineIds($serviceEvidenceLineIds),
                /**
                 * Which line each bookkeeping hold is about, and the items it sits between. The
                 * reasons above say the same thing in prose, which nothing downstream can read:
                 * the archive census could only attribute a hold to every item of the plan, so it
                 * reported the corpus-wide item mix back as if it were a finding about songs.
                 */
                'structural_findings' => array_map(
                    static fn (OosEmailStructuralFinding $finding): array => $finding->toArray(),
                    $validation->structuralFindingsForPlan($planIndex),
                ),
                'items' => array_map(
                    fn (array $item, int $index): array => [
                        'position' => $index + 1,
                        'source_line_ids' => $this->integerLineIds($item['source_line_ids'] ?? []),
                        'continuation' => ($item['continuation'] ?? false) === true,
                        'semantic_kind' => $item['semantic_kind'] ?? null,
                    ],
                    $rawItems,
                    array_keys($rawItems),
                ),
            ],
            contentScope: $contentScope,
        );
    }

    private function validatedService(?string $rawService): ?SermonService
    {
        if ($rawService === null) {
            return null;
        }

        return SermonService::tryFrom(strtolower(trim($rawService)));
    }

    private function validatedDate(?string $rawDate): ?string
    {
        if ($rawDate === null) {
            return null;
        }

        return $this->safeDateFromFormat('Y-m-d', trim($rawDate))?->format('Y-m-d');
    }

    /**
     * Morning-first, then the first importable plan, then the first plan of any shape.
     *
     * @param  non-empty-list<OosEmailServicePlan>  $plans
     */
    private function primaryPlan(array $plans): OosEmailServicePlan
    {
        foreach ($plans as $plan) {
            if ($plan->service === SermonService::Morning) {
                return $plan;
            }
        }

        foreach ($plans as $plan) {
            if ($plan->isImportable()) {
                return $plan;
            }
        }

        return $plans[0];
    }

    /**
     * @param  list<OosEmailServicePlan>  $plans
     * @return list<array{plan_key:string,service:?string,date:?string,content_scope:string,items:array<int,array<string,mixed>>,confidence:float,needs_review:bool,should_import:bool,disposition:string,validation_reasons:list<string>,content_validation_reasons:list<string>,hold_reasons:list<string>,source_provenance:array<string,mixed>}>
     */
    private function servicePlansMetadata(array $plans): array
    {
        return array_map(
            static fn (OosEmailServicePlan $plan): array => $plan->toMetadataArray(),
            $plans,
        );
    }

    private function preferredBody(InboundEmail $inboundEmail): string
    {
        $plain = $this->normaliseWhitespace($inboundEmail->body_plain);

        if ($plain !== null) {
            return $plain;
        }

        $html = $inboundEmail->body_html;

        if (! is_string($html) || trim($html) === '') {
            return '';
        }

        $withBreaks = preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", $html) ?? $html;
        $stripped = strip_tags($withBreaks);

        return $this->normaliseWhitespace($stripped) ?? '';
    }

    /**
     * `title` is the cleaned, readable form and `source_title` the line the email actually
     * carried. They are deliberately allowed to differ here: cross-source matching and song
     * resolution read `source_title`, so cleaning the display title costs no match strength.
     *
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool,semantic_kind?:?string}>  $items
     * @return array<int, array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function normaliseItems(
        array $items,
        OosEmailSourceDocument $source,
        bool $provenanceComplete,
    ): array {
        $normalised = [];

        foreach ($items as $item) {
            $semanticType = $this->normaliseSemanticType($item['type']);
            $richerSemanticKind = is_string($item['semantic_kind'] ?? null) ? $item['semantic_kind'] : null;
            $sourceLineIds = $this->integerLineIds($item['source_line_ids'] ?? []);
            $sourceTitle = $provenanceComplete ? $source->textFor($sourceLineIds) : null;
            $rawTitle = $this->normaliseWhitespace($sourceTitle ?? $item['title']);

            if ($rawTitle === null) {
                continue;
            }

            $sectionType = ServiceSectionType::tryFrom($semanticType) ?? ServiceSectionType::Other;
            $title = $this->titleCleaner->displayTitle($rawTitle, $sectionType);

            $storageType = match ($semanticType) {
                'song' => 'songs',
                'bible_reading' => 'bibles',
                default => 'custom',
            };

            $normalised[] = [
                'position' => count($normalised) + 1,
                'type' => $storageType,
                'section_type' => $semanticType,
                'title' => $title,
                'source_title' => $rawTitle,
                'openlp_search_title' => null,
                'metadata' => $this->itemMetadata($sectionType, $semanticType, $storageType, $title, $rawTitle, $richerSemanticKind),
            ];
        }

        return $normalised;
    }

    /**
     * Only a reason that impeaches the extracted order invalidates the plan. Bookkeeping reasons
     * — an unaccounted sign-off line, an aside ignored between two items — leave the plan held for
     * review and therefore still importable by a human (F65).
     *
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  list<string>  $contentReasons
     * @param  list<string>  $structuralReasons
     */
    private function planDisposition(
        ?SermonService $service,
        ?string $date,
        array $items,
        float $confidence,
        array $contentReasons,
        array $structuralReasons,
        float $reviewThreshold,
        float $autoImportThreshold,
        bool $consensus,
        bool $validAttemptsDisagree,
        OosEmailContentScope $contentScope,
    ): OosEmailParseDisposition {
        if ($contentReasons !== [] || $items === []) {
            return OosEmailParseDisposition::InvalidExtraction;
        }

        // Bookkeeping alone never invalidates, but it is still unexplained: an unaccounted line
        // may be an item the model dropped. Hold it for a human rather than importing it.
        if ($structuralReasons !== []) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        if ($validAttemptsDisagree) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        if ($contentScope === OosEmailContentScope::Unknown) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        if ($service === null || $date === null || $service === SermonService::Other) {
            return OosEmailParseDisposition::ReviewRequired;
        }

        if ($confidence >= $autoImportThreshold) {
            return OosEmailParseDisposition::AutoImportable;
        }

        if ($consensus && $confidence >= $reviewThreshold) {
            return OosEmailParseDisposition::AutoImportable;
        }

        return OosEmailParseDisposition::ReviewRequired;
    }

    /**
     * Every reason this plan is not auto-importable, recorded here because this is the only place
     * that knows them. The archive report used to rebuild this census downstream by inspecting
     * reason strings and attempt arrays, which merged F65 bookkeeping holds with attempt
     * disagreements and read a failed corrective API call as a parser disagreement.
     *
     * An auto-importable plan has no hold reasons; every other plan has at least one.
     *
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  list<string>  $contentReasons
     * @param  list<string>  $structuralReasons
     * @return list<OosEmailPlanHoldReason>
     */
    private function planHoldReasons(
        OosEmailParseDisposition $disposition,
        ?SermonService $service,
        ?string $date,
        array $items,
        float $confidence,
        array $contentReasons,
        array $structuralReasons,
        float $autoImportThreshold,
        bool $validAttemptsDisagree,
        OosEmailContentScope $contentScope,
    ): array {
        if ($disposition === OosEmailParseDisposition::AutoImportable) {
            return [];
        }

        $reasons = [];

        if ($contentReasons !== []) {
            $reasons[] = OosEmailPlanHoldReason::ContentInvalid;
        }

        if ($items === []) {
            $reasons[] = OosEmailPlanHoldReason::NoItems;
        }

        if ($structuralReasons !== []) {
            $reasons[] = OosEmailPlanHoldReason::Bookkeeping;
        }

        if ($validAttemptsDisagree) {
            $reasons[] = OosEmailPlanHoldReason::AttemptDisagreement;
        }

        if ($contentScope === OosEmailContentScope::Unknown) {
            $reasons[] = OosEmailPlanHoldReason::UnknownContentScope;
        }

        if ($service === null || $date === null || $service === SermonService::Other) {
            $reasons[] = OosEmailPlanHoldReason::MissingIdentity;
        }

        if ($confidence < $autoImportThreshold) {
            $reasons[] = OosEmailPlanHoldReason::LowConfidence;
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptMetadata(
        int $attempt,
        OosEmailItemExtractionResult $extraction,
        OosEmailExtractionValidationResult $validation,
        bool $selected,
    ): array {
        return [
            'attempt' => $attempt,
            'selected' => $selected,
            'reported_service_count' => $extraction->serviceCount,
            'returned_service_count' => count($extraction->services),
            'confidence' => $extraction->confidence,
            'validation_reasons' => $validation->allReasons(),
            'validation_rule_codes' => [
                'content' => $validation->contentRuleCodes(),
                'bookkeeping' => $validation->bookkeepingRuleCodes(),
            ],
            'plans' => array_map(static fn (array $service): array => [
                'service' => $service['service'] ?? null,
                'date' => $service['date'] ?? null,
                'content_scope' => $service['content_scope'] ?? 'full',
                'confidence' => $service['confidence'],
                'item_count' => count($service['items']),
            ], $extraction->services),
        ];
    }

    /**
     * @return list<int>
     */
    private function integerLineIds(mixed $lineIds): array
    {
        if (! is_array($lineIds)) {
            return [];
        }

        return array_values(array_filter($lineIds, is_int(...)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemMetadata(
        ServiceSectionType $sectionType,
        string $semanticType,
        string $storageType,
        string $title,
        string $rawTitle,
        ?string $richerSemanticKind,
    ): ?array {
        // Recorded here so the passage is available to every reader of the item without
        // re-parsing a title, matching what LLM structure detection stores for a
        // detected reading — which is what ServiceFlowBuilder already looks for.
        if ($sectionType === ServiceSectionType::BibleReading) {
            $reference = $this->titleCleaner->readingReference($title, $rawTitle);

            $metadata = $reference === null ? [] : ['reading_reference' => $reference];

            return $richerSemanticKind === null ? ($metadata === [] ? null : $metadata) : [
                ...$metadata,
                'semantic_kind' => $richerSemanticKind,
            ];
        }

        $metadata = $storageType === 'custom' ? ['email_type' => $semanticType] : [];

        if ($richerSemanticKind !== null) {
            $metadata['semantic_kind'] = $richerSemanticKind;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * Hold a resolved date for review when it is not a Sunday, or when that date already
     * carries an order of service imported from a different email.
     *
     * Nothing here compares the date to when the email arrived. Emails are entered by hand from
     * the archive as well as received live, so "before the email was received" says nothing
     * about correctness. A wrong day-of-month typo, by contrast, lands off Sunday six times in
     * seven, and a second email for a Sunday already imported is the shape of a correction.
     *
     * @return array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}
     */
    private function validateDatePlausibility(
        ?string $date,
        string $subject,
        ?string $sourceMessageId,
        ?SermonService $service = null,
    ): array {
        $plausible = [
            'plausible' => true,
            'warnings' => [],
            'suggested_date' => null,
            'reasons' => [],
            'claimed_weekday' => null,
        ];

        if ($date === null) {
            return $plausible;
        }

        $resolved = $this->safeDateFromFormat('Y-m-d', $date)?->startOfDay();

        if (! $resolved instanceof CarbonImmutable) {
            return $plausible;
        }

        $claimedWeekday = $this->claimedWeekday($subject);
        $plausible['claimed_weekday'] = $claimedWeekday;
        $resolvedWeekday = strtolower($resolved->englishDayOfWeek);
        $reasons = [];
        $warnings = [];
        $suggestedDate = null;

        if ($claimedWeekday !== null && $claimedWeekday !== $resolvedWeekday) {
            $reasons[] = "the email refers to a {$claimedWeekday} but {$date} is a {$resolvedWeekday}";
        }

        if ($resolvedWeekday !== 'sunday') {
            $reasons[] = "{$date} is a {$resolvedWeekday}, not a Sunday";
        }

        if ($reasons !== []) {
            $suggestedDate = $this->nearestSunday($resolved);
            $warnings[] = "Resolved service date {$date} looks implausible (".implode('; ', $reasons).').';

            if ($suggestedDate !== null) {
                $warnings[] = "The nearest Sunday is {$suggestedDate}; confirm before importing.";
            }
        }

        $alreadyImported = $this->existingEmailImports->servicesImportedFromOtherEmails(
            $date,
            $sourceMessageId,
            $service,
        );

        if ($alreadyImported !== []) {
            $reasons[] = "{$date} already has an order of service imported from another email (".implode(', ', $alreadyImported).')';
            $warnings[] = "{$date} already has an order of service imported from another email ("
                .implode(', ', $alreadyImported).'); check this is not a duplicate before importing.';
        }

        if ($reasons === []) {
            return $plausible;
        }

        return [
            'plausible' => false,
            'warnings' => $warnings,
            'suggested_date' => $suggestedDate,
            'reasons' => $reasons,
            'claimed_weekday' => $claimedWeekday,
        ];
    }

    private function claimedWeekday(string $subject): ?string
    {
        if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $subject, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * The Sunday a non-Sunday date most likely meant. Every weekday is strictly closer to one
     * Sunday than the other, so this is never ambiguous; a Sunday has no suggestion to make.
     */
    private function nearestSunday(CarbonImmutable $resolved): ?string
    {
        $daysAfterSunday = (int) $resolved->dayOfWeek;

        if ($daysAfterSunday === 0) {
            return null;
        }

        return $daysAfterSunday <= 3
            ? $resolved->subDays($daysAfterSunday)->format('Y-m-d')
            : $resolved->addDays(7 - $daysAfterSunday)->format('Y-m-d');
    }

    private function safeDateFromFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            $candidate = CarbonImmutable::createFromFormat($format, $value);

            if (! $candidate instanceof CarbonImmutable || $candidate->format($format) !== $value) {
                return null;
            }

            return $candidate;
        } catch (Throwable) {
            return null;
        }
    }

    private function normaliseSemanticType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'song', 'songs', 'hymn', 'hymns' => 'song',
            'bible', 'reading', 'bible_reading', 'scripture' => 'bible_reading',
            'welcome' => 'welcome',
            'prayer', 'prayers' => 'prayer',
            'notice', 'notices', 'announcement', 'announcements' => 'notices',
            'children', 'childrens_talk', 'children_talk', 'children\'s talk' => 'childrens_talk',
            'sermon', 'message' => 'sermon',
            default => 'other',
        };
    }

    private function normaliseWhitespace(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = preg_replace("/\r\n?/", "\n", $value) ?? $value;
        $normalised = preg_replace('/[ \t]+/', ' ', $normalised) ?? $normalised;
        $normalised = preg_replace("/\n{3,}/", "\n\n", $normalised) ?? $normalised;
        $trimmed = trim($normalised);

        return $trimmed === '' ? null : $trimmed;
    }
}
