<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use Carbon\CarbonImmutable;

class OosEmailParserService
{
    public function __construct(
        private readonly OosEmailItemExtractor $itemExtractor,
    ) {}

    public function parse(InboundEmail $inboundEmail): OosEmailParseResult
    {
        $body = $this->preferredBody($inboundEmail);
        $receivedAt = CarbonImmutable::instance($inboundEmail->received_at);
        $extraction = $this->itemExtractor->extract(
            $inboundEmail->subject,
            $body,
            $receivedAt->toDateString(),
        );
        $warnings = $extraction->notes;
        $servicePlans = $this->buildServicePlans(
            $extraction,
            $inboundEmail->subject,
            $receivedAt,
            $warnings,
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

        $primaryPlausibility = $this->validateDatePlausibility(
            $primaryDate,
            $inboundEmail->subject,
            $receivedAt,
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
                'source_message_id' => $inboundEmail->message_id,
                'source_subject' => $inboundEmail->subject,
            ],
            servicePlans: $servicePlans,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return non-empty-list<OosEmailServicePlan>
     */
    private function buildServicePlans(
        OosEmailItemExtractionResult $extraction,
        string $subject,
        CarbonImmutable $receivedAt,
        array &$warnings,
    ): array {
        if ($extraction->services === []) {
            return [$this->buildPlan(
                null,
                null,
                $extraction->items,
                $extraction->confidence,
                $subject,
                $receivedAt,
                $warnings,
            )];
        }

        $plans = [];

        foreach ($extraction->services as $rawPlan) {
            $plans[] = $this->buildPlan(
                $rawPlan['service'],
                $rawPlan['date'],
                $rawPlan['items'],
                $rawPlan['confidence'],
                $subject,
                $receivedAt,
                $warnings,
            );
        }

        return $plans;
    }

    /**
     * @param  array<int, array{type:string,title:string}>  $rawItems
     * @param  list<string>  $warnings
     */
    private function buildPlan(
        ?string $rawService,
        ?string $rawDate,
        array $rawItems,
        float $rawConfidence,
        string $subject,
        CarbonImmutable $receivedAt,
        array &$warnings,
    ): OosEmailServicePlan {
        $service = $this->validatedService($rawService);
        $date = $this->validatedDate($rawDate);
        $items = $this->normaliseItems($rawItems);
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

        $plausibility = $this->validateDatePlausibility($date, $subject, $receivedAt);

        if (! $plausibility['plausible']) {
            $confidence = min($confidence, 0.74);
            $warnings = array_merge($warnings, $plausibility['warnings']);
        }

        $confidence = round($confidence, 2);
        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $shouldImport = $confidence >= $reviewThreshold
            && $date !== null
            && $service !== null
            && $items !== [];

        return new OosEmailServicePlan(
            service: $service,
            date: $date,
            items: $items,
            confidence: $confidence,
            needsReview: $shouldImport && $confidence < $autoImportThreshold,
            shouldImport: $shouldImport,
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
     * @return list<array{plan_key:string,service:?string,date:?string,items:array<int,array<string,mixed>>,confidence:float,needs_review:bool,should_import:bool}>
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
     * @param  array<int, array{type:string,title:string}>  $items
     * @return array<int, array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function normaliseItems(array $items): array
    {
        $normalised = [];

        foreach ($items as $item) {
            $semanticType = $this->normaliseSemanticType($item['type']);
            $title = $this->normaliseWhitespace($item['title']);

            if ($title === null) {
                continue;
            }

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
                'source_title' => $title,
                'openlp_search_title' => null,
                'metadata' => $storageType === 'custom' ? ['email_type' => $semanticType] : null,
            ];
        }

        return $normalised;
    }

    /**
     * @return array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}
     */
    private function validateDatePlausibility(
        ?string $date,
        string $subject,
        CarbonImmutable $receivedAt,
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

        if ($claimedWeekday !== null && $claimedWeekday !== $resolvedWeekday) {
            $reasons[] = "the email refers to a {$claimedWeekday} but {$date} is a {$resolvedWeekday}";
        }

        $windowStart = $receivedAt->startOfDay();
        $maxFutureDays = (int) config('service-tracking.email_parsing.max_future_days', 14);
        $windowEnd = $windowStart->addDays($maxFutureDays);

        if ($resolved->lessThan($windowStart)) {
            $reasons[] = "{$date} is before the email was received";
        } elseif ($resolved->greaterThan($windowEnd)) {
            $reasons[] = "{$date} is more than {$maxFutureDays} days after the email was received";
        }

        if ($reasons === []) {
            return $plausible;
        }

        $suggestedDate = $this->suggestPlausibleDate($resolved, $windowStart, $windowEnd, $claimedWeekday);
        $warnings = ["Resolved service date {$date} looks implausible (".implode('; ', $reasons).').'];

        if ($suggestedDate !== null) {
            $warnings[] = "The email most likely refers to {$suggestedDate}; confirm before importing.";
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

    private function suggestPlausibleDate(
        CarbonImmutable $resolved,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        ?string $claimedWeekday,
    ): ?string {
        $targetDay = $resolved->day;
        $candidates = [];
        $cursor = $windowStart;

        while ($cursor->lessThanOrEqualTo($windowEnd)) {
            $matchesDayOfMonth = $cursor->day === $targetDay;
            $matchesWeekday = $claimedWeekday === null || strtolower($cursor->englishDayOfWeek) === $claimedWeekday;

            if ($matchesDayOfMonth && $matchesWeekday) {
                $candidates[$cursor->format('Y-m-d')] = true;
            }

            $cursor = $cursor->addDay();
        }

        return count($candidates) === 1 ? (string) array_key_first($candidates) : null;
    }

    private function safeDateFromFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            $candidate = CarbonImmutable::createFromFormat($format, $value);

            if (! $candidate instanceof CarbonImmutable || $candidate->format($format) !== $value) {
                return null;
            }

            return $candidate;
        } catch (\Throwable) {
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
