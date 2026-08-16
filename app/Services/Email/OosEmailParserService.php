<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\AdjudicatingOosEmailItemExtractor;
use App\Contracts\CorrectiveOosEmailItemExtractor;
use App\Contracts\OosEmailItemExtractor;
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
        private readonly OosEmailItemExtractor $itemExtractor,
        private readonly ExistingEmailImportLookup $existingEmailImports,
        private readonly ServiceItemTitleCleaner $titleCleaner,
        ?OosEmailExtractionValidator $extractionValidator = null,
    ) {
        $this->extractionValidator = $extractionValidator ?? new OosEmailExtractionValidator;
    }

    public function parse(InboundEmail $inboundEmail): OosEmailParseResult
    {
        $body = $this->preferredBody($inboundEmail);
        $source = OosEmailSourceDocument::fromBody($body);
        $receivedAt = CarbonImmutable::instance($inboundEmail->received_at);
        $initialExtraction = $this->itemExtractor->extract(
            $inboundEmail->subject,
            $body,
            $receivedAt->toDateString(),
        );
        $initialExtraction = $this->guardUnsupportedEveningPlans($source, $initialExtraction, $inboundEmail->subject);
        $initialValidation = $this->extractionValidator->validate($source, $initialExtraction, $inboundEmail->subject);
        $extraction = $initialExtraction;
        $validation = $initialValidation;
        $attempts = [$this->attemptMetadata(1, $initialExtraction, $initialValidation, true)];
        $consensus = false;
        $adjudicated = false;
        $validAttemptsDisagree = false;
        $retryWarnings = [];
        $retryReasons = $this->retryReasons($initialValidation);

        if ($retryReasons !== [] && $this->itemExtractor instanceof CorrectiveOosEmailItemExtractor) {
            try {
                $correctedExtraction = $this->itemExtractor->correct(
                    $inboundEmail->subject,
                    $body,
                    $receivedAt->toDateString(),
                    $initialExtraction,
                    $retryReasons,
                );
                $correctedExtraction = $this->guardUnsupportedEveningPlans($source, $correctedExtraction, $inboundEmail->subject);
                $correctedValidation = $this->extractionValidator->validate($source, $correctedExtraction, $inboundEmail->subject);
                $useCorrected = $correctedValidation->reasonCount() < $initialValidation->reasonCount();

                if ($useCorrected) {
                    $extraction = $correctedExtraction;
                    $validation = $correctedValidation;
                    $attempts[0]['selected'] = false;
                }

                $attempts[] = $this->attemptMetadata(2, $correctedExtraction, $correctedValidation, $useCorrected);
                $consensus = $initialValidation->isValid()
                    && $correctedValidation->isValid()
                    && $this->extractionSignature($initialExtraction) === $this->extractionSignature($correctedExtraction);
                $validAttemptsDisagree = $initialValidation->isValid()
                    && $correctedValidation->isValid()
                    && ! $consensus;

                if ($validAttemptsDisagree) {
                    $disagreementCategories = $this->extractionDisagreementCategories($initialExtraction, $correctedExtraction);
                    $attempts[1]['disagreement_categories'] = $disagreementCategories;
                    $attempts[1]['disagreement_item_type_changes'] = $this->extractionItemTypeChanges(
                        $initialExtraction,
                        $correctedExtraction,
                    );

                    if ($this->itemExtractor instanceof AdjudicatingOosEmailItemExtractor) {
                        [$extraction, $validation, $attempts, $adjudicated] = $this->adjudicateDisagreement(
                            $this->itemExtractor,
                            $inboundEmail,
                            $source,
                            $body,
                            $receivedAt->toDateString(),
                            $initialExtraction,
                            $correctedExtraction,
                            $extraction,
                            $validation,
                            $attempts,
                            $disagreementCategories,
                        );
                    }

                    $retryWarnings[] = $adjudicated
                        ? 'Two valid extraction attempts disagreed; adjudication chose between them, so this is held for review rather than imported unattended.'
                        : 'Structurally valid extraction attempts disagreed and adjudication did not resolve them; human review is required.';
                }
            } catch (Throwable $exception) {
                report($exception);
                $retryWarnings[] = 'The corrective extraction attempt failed; the first result has been held for review.';
                $attempts[] = [
                    'attempt' => 2,
                    'selected' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $warnings = array_merge($extraction->notes, $retryWarnings);
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
            ],
            servicePlans: $servicePlans,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $attempts,
            consensus: $consensus,
            adjudicated: $adjudicated,
        );
    }

    /**
     * Ask a third call to resolve two structurally valid but disagreeing attempts.
     *
     * The adjudicated order is adopted when it is valid and reproduces one of the two candidates,
     * because it is the model's own resolution of a diff it was shown. It never clears the import
     * gate: the caller keeps `$validAttemptsDisagree` true, so the plan stays `ReviewRequired` and
     * an operator sees the resolution instead of the pipeline acting on it. Adjudication is
     * strictly weaker evidence than consensus — the adjudicator is the same model, handed both
     * answers and told not to invent a third — so it is reported, not trusted (HIR-D6).
     *
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<string>  $disagreementCategories
     * @return array{OosEmailItemExtractionResult,OosEmailExtractionValidationResult,list<array<string,mixed>>,bool}
     */
    private function adjudicateDisagreement(
        AdjudicatingOosEmailItemExtractor $adjudicator,
        InboundEmail $inboundEmail,
        OosEmailSourceDocument $source,
        string $body,
        string $receivedDate,
        OosEmailItemExtractionResult $initialExtraction,
        OosEmailItemExtractionResult $correctedExtraction,
        OosEmailItemExtractionResult $selectedExtraction,
        OosEmailExtractionValidationResult $selectedValidation,
        array $attempts,
        array $disagreementCategories,
    ): array {
        try {
            $adjudicatedExtraction = $adjudicator->adjudicate(
                $inboundEmail->subject,
                $body,
                $receivedDate,
                $initialExtraction,
                $correctedExtraction,
                $disagreementCategories,
            );
            $adjudicatedExtraction = $this->guardUnsupportedEveningPlans($source, $adjudicatedExtraction, $inboundEmail->subject);
            $adjudicatedValidation = $this->extractionValidator->validate($source, $adjudicatedExtraction, $inboundEmail->subject);
            $adjudicatedSignature = $this->extractionSignature($adjudicatedExtraction);
            $matchesCandidate = $adjudicatedSignature === $this->extractionSignature($initialExtraction)
                || $adjudicatedSignature === $this->extractionSignature($correctedExtraction);
            $resolved = $adjudicatedValidation->isValid() && $matchesCandidate;

            $attempts[] = $this->attemptMetadata(3, $adjudicatedExtraction, $adjudicatedValidation, $resolved)
                + ['adjudication' => $resolved ? 'matched_candidate' : 'third_interpretation'];

            if (! $resolved) {
                return [$selectedExtraction, $selectedValidation, $attempts, false];
            }

            $attempts[0]['selected'] = false;
            $attempts[1]['selected'] = false;

            return [$adjudicatedExtraction, $adjudicatedValidation, $attempts, true];
        } catch (Throwable $exception) {
            report($exception);
            $attempts[] = [
                'attempt' => 3,
                'selected' => false,
                'adjudication' => 'error',
                'error' => $exception->getMessage(),
            ];

            return [$selectedExtraction, $selectedValidation, $attempts, false];
        }
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
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool}>  $rawItems
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
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool}>  $items
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
                'metadata' => $this->itemMetadata($sectionType, $semanticType, $storageType, $title, $rawTitle),
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
     * Retry only an extraction that deterministic validation has shown to be defective.
     * Confidence and unresolved identity remain review signals, not reasons to perturb a valid
     * first reading.
     *
     * @return list<string>
     */
    private function retryReasons(
        OosEmailExtractionValidationResult $validation,
    ): array {
        return $validation->allReasons();
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
     * Two attempts "agree" when they extracted the same order, so the signature covers only what
     * a service is built from. `service_evidence_line_ids` is deliberately excluded: it is
     * optional for a single-plan email, is never written to a service, and including it recorded
     * 70 of the 125 disagreements in the 2026-08-14 review as structural conflicts when the two
     * attempts had produced identical plans, dates, scopes and item counts (F65).
     */
    private function extractionSignature(OosEmailItemExtractionResult $extraction): string
    {
        $signature = array_map(fn (array $service): array => [
            'service' => $service['service'] ?? null,
            'date' => $service['date'] ?? null,
            'content_scope' => $service['content_scope'] ?? 'full',
            'items' => array_map(fn (array $item): array => [
                'type' => $item['type'],
                'source_line_ids' => $this->integerLineIds($item['source_line_ids'] ?? []),
            ], $service['items']),
        ], $extraction->services);

        return hash('sha256', (string) json_encode($signature));
    }

    /** @return list<string> */
    private function extractionDisagreementCategories(
        OosEmailItemExtractionResult $initialExtraction,
        OosEmailItemExtractionResult $correctedExtraction,
    ): array {
        $categories = [];
        $initialPlans = $initialExtraction->services;
        $correctedPlans = $correctedExtraction->services;

        if (count($initialPlans) !== count($correctedPlans)) {
            $categories[] = 'plan_count';
        }

        foreach (['service', 'date', 'content_scope'] as $field) {
            if (array_column($initialPlans, $field) !== array_column($correctedPlans, $field)) {
                $categories[] = $field;
            }
        }

        if (array_map(static fn (array $plan): int => count($plan['items']), $initialPlans)
            !== array_map(static fn (array $plan): int => count($plan['items']), $correctedPlans)) {
            $categories[] = 'item_count';
        }

        foreach (['type', 'title', 'source_line_ids'] as $field) {
            $initialItems = array_map(
                static fn (array $plan): array => array_column($plan['items'], $field),
                $initialPlans,
            );
            $correctedItems = array_map(
                static fn (array $plan): array => array_column($plan['items'], $field),
                $correctedPlans,
            );

            if ($initialItems !== $correctedItems) {
                $categories[] = $field === 'type' ? 'item_type_or_order' : $field;
            }
        }

        return $categories === [] ? ['unclassified'] : $categories;
    }

    /**
     * Which item types the two attempts actually swapped, as `from→to` counts.
     *
     * `item_type_or_order` is by far the commonest disagreement — 48 of the 102 in the 2026-08-16
     * rehearsal were that and nothing else, meaning identical titles and source lines with a
     * different label somewhere — but the category cannot say whether the argument was over a
     * consequential type or over `other` against `prayer`. Only plans whose item counts match are
     * compared: without that, position `n` in one attempt is not position `n` in the other, and
     * `item_count` already records the difference.
     *
     * @return array<string, int>
     */
    private function extractionItemTypeChanges(
        OosEmailItemExtractionResult $initialExtraction,
        OosEmailItemExtractionResult $correctedExtraction,
    ): array {
        $changes = [];

        foreach ($initialExtraction->services as $planIndex => $initialPlan) {
            $correctedPlan = $correctedExtraction->services[$planIndex] ?? null;

            if (! is_array($correctedPlan) || count($initialPlan['items']) !== count($correctedPlan['items'])) {
                continue;
            }

            foreach ($initialPlan['items'] as $itemIndex => $initialItem) {
                $from = $initialItem['type'];
                $to = $correctedPlan['items'][$itemIndex]['type'];

                if ($from !== $to) {
                    $changes["{$from}→{$to}"] = ($changes["{$from}→{$to}"] ?? 0) + 1;
                }
            }
        }

        ksort($changes);

        return $changes;
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
    ): ?array {
        // Recorded here so the passage is available to every reader of the item without
        // re-parsing a title, matching what LLM structure detection stores for a
        // detected reading — which is what ServiceFlowBuilder already looks for.
        if ($sectionType === ServiceSectionType::BibleReading) {
            $reference = $this->titleCleaner->readingReference($title, $rawTitle);

            return $reference === null ? null : ['reading_reference' => $reference];
        }

        return $storageType === 'custom' ? ['email_type' => $semanticType] : null;
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
