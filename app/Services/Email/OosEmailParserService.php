<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\CorrectiveOosEmailItemExtractor;
use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailExtractionValidationResult;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Enums\OosEmailParseDisposition;
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
        $initialValidation = $this->extractionValidator->validate($source, $initialExtraction);
        $extraction = $initialExtraction;
        $validation = $initialValidation;
        $attempts = [$this->attemptMetadata(1, $initialExtraction, $initialValidation, true)];
        $consensus = false;
        $validAttemptsDisagree = false;
        $retryWarnings = [];
        $retryReasons = $this->retryReasons($initialExtraction, $initialValidation);

        if ($retryReasons !== [] && $this->itemExtractor instanceof CorrectiveOosEmailItemExtractor) {
            try {
                $correctedExtraction = $this->itemExtractor->correct(
                    $inboundEmail->subject,
                    $body,
                    $receivedAt->toDateString(),
                    $initialExtraction,
                    $retryReasons,
                );
                $correctedValidation = $this->extractionValidator->validate($source, $correctedExtraction);
                $useCorrected = $correctedValidation->reasonCount() <= $initialValidation->reasonCount();

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
                    $retryWarnings[] = 'Two structurally valid extraction attempts disagreed; human review is required.';
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
                'source_message_id' => $inboundEmail->message_id,
                'source_subject' => $inboundEmail->subject,
            ],
            servicePlans: $servicePlans,
            disposition: $primary->disposition,
            validationReasons: $primary->validationReasons,
            extractionAttempts: $attempts,
            consensus: $consensus,
        );
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
                $extraction->items,
                $extraction->confidence,
                [],
                $source,
                $extraction->provenanceComplete,
                $validation->reasonsForPlan(0),
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
                $rawPlan['items'],
                $rawPlan['confidence'],
                $rawPlan['service_evidence_line_ids'] ?? [],
                $source,
                $extraction->provenanceComplete,
                $validation->reasonsForPlan($planIndex),
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
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool}>  $rawItems
     * @param  list<int>  $serviceEvidenceLineIds
     * @param  list<string>  $structuralReasons
     * @param  list<string>  $warnings
     * @param  array<string, array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}>  $validations
     */
    private function buildPlan(
        int $planIndex,
        ?string $rawService,
        ?string $rawDate,
        array $rawItems,
        float $rawConfidence,
        array $serviceEvidenceLineIds,
        OosEmailSourceDocument $source,
        bool $provenanceComplete,
        array $structuralReasons,
        string $subject,
        ?string $sourceMessageId,
        array &$warnings,
        array &$validations,
        bool $consensus,
        bool $validAttemptsDisagree,
    ): OosEmailServicePlan {
        $service = $this->validatedService($rawService);
        $date = $this->validatedDate($rawDate);
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
        $validationReasons = $structuralReasons;

        if ($items === []) {
            $validationReasons[] = 'The service order contains no extractable items.';
        }

        $validationReasons = array_values(array_unique($validationReasons));
        $disposition = $this->planDisposition(
            $service,
            $date,
            $items,
            $confidence,
            $validationReasons,
            $reviewThreshold,
            $autoImportThreshold,
            $consensus,
            $validAttemptsDisagree,
        );
        if ($validAttemptsDisagree) {
            $validationReasons[] = 'Two valid extraction attempts disagreed about the service structure.';
        }
        $validationReasons = array_values(array_unique($validationReasons));
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
            sourceProvenance: [
                'plan_index' => $planIndex,
                'service_evidence_line_ids' => $this->integerLineIds($serviceEvidenceLineIds),
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
     * @return list<array{plan_key:string,service:?string,date:?string,items:array<int,array<string,mixed>>,confidence:float,needs_review:bool,should_import:bool,disposition:string,validation_reasons:list<string>,source_provenance:array<string,mixed>}>
     */
    private function servicePlansMetadata(array $plans): array
    {
        return array_map(static fn (OosEmailServicePlan $plan): array => [
            'plan_key' => $plan->key(),
            'service' => $plan->service?->value,
            'date' => $plan->date,
            'items' => $plan->items,
            'confidence' => $plan->confidence,
            'needs_review' => $plan->needsReview,
            'should_import' => $plan->shouldImport,
            'disposition' => $plan->disposition->value,
            'validation_reasons' => $plan->validationReasons,
            'source_provenance' => $plan->sourceProvenance,
        ], $plans);
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
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  list<string>  $validationReasons
     */
    private function planDisposition(
        ?SermonService $service,
        ?string $date,
        array $items,
        float $confidence,
        array $validationReasons,
        float $reviewThreshold,
        float $autoImportThreshold,
        bool $consensus,
        bool $validAttemptsDisagree,
    ): OosEmailParseDisposition {
        if ($validationReasons !== [] || $items === []) {
            return OosEmailParseDisposition::InvalidExtraction;
        }

        if ($validAttemptsDisagree) {
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
     * @return list<string>
     */
    private function retryReasons(
        OosEmailItemExtractionResult $extraction,
        OosEmailExtractionValidationResult $validation,
    ): array {
        $reasons = $validation->allReasons();
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);

        foreach ($extraction->services as $planIndex => $service) {
            $confidence = $service['confidence'];

            if ($confidence < $autoImportThreshold) {
                $reasons[] = 'Service plan '.($planIndex + 1).' is below the automatic-import confidence threshold.';
            }

            if (($service['service'] ?? null) === 'unknown' || ($service['service'] ?? null) === 'other') {
                $reasons[] = 'Service plan '.($planIndex + 1).' has an uncertain or special service type.';
            }

            if (($service['date'] ?? null) === null) {
                $reasons[] = 'Service plan '.($planIndex + 1).' has no resolved date.';
            }
        }

        return array_values(array_unique($reasons));
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
                'confidence' => $service['confidence'],
                'item_count' => count($service['items']),
            ], $extraction->services),
        ];
    }

    private function extractionSignature(OosEmailItemExtractionResult $extraction): string
    {
        $signature = array_map(fn (array $service): array => [
            'service' => $service['service'] ?? null,
            'date' => $service['date'] ?? null,
            'evidence' => $this->integerLineIds($service['service_evidence_line_ids'] ?? []),
            'items' => array_map(fn (array $item): array => [
                'type' => $item['type'],
                'source_line_ids' => $this->integerLineIds($item['source_line_ids'] ?? []),
            ], $service['items']),
        ], $extraction->services);

        return hash('sha256', (string) json_encode($signature));
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
