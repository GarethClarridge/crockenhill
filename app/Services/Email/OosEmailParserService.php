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

        $dateResolution = $this->extractDate($inboundEmail->subject, $body, $receivedAt);
        $serviceResolution = $this->extractService($inboundEmail->subject, $body);
        $itemExtraction = $this->itemExtractor->extract($inboundEmail->subject, $body);

        $warnings = [];

        foreach ($itemExtraction->notes as $note) {
            $warnings[] = $note;
        }

        // One email routinely carries both a morning and an evening order. Build a plan per
        // service (or a single plan for the legacy flattened shape), each with its own date,
        // items, confidence and plausibility hold.
        [$servicePlans, $planWarnings] = $this->buildServicePlans(
            $itemExtraction,
            $dateResolution,
            $serviceResolution,
            $inboundEmail->subject,
            $body,
            $receivedAt,
        );

        foreach ($planWarnings as $planWarning) {
            $warnings[] = $planWarning;
        }

        // Legacy/primary fields describe the morning-first plan for stored-metadata
        // compatibility and inbox display; imports iterate $servicePlans.
        $primary = $this->primaryPlan($servicePlans);
        $primaryDate = $primary instanceof OosEmailServicePlan ? $primary->date : null;
        $primaryService = $primary instanceof OosEmailServicePlan ? $primary->service : null;
        $primaryItems = $primary instanceof OosEmailServicePlan ? $primary->items : [];
        $primaryConfidence = $primary instanceof OosEmailServicePlan ? $primary->confidence : 0.0;

        if ($primaryDate === null) {
            $warnings[] = 'Could not confidently infer the service date from the email.';
        }

        if ($primaryService === null) {
            $warnings[] = 'Could not confidently infer the service type from the email.';
        }

        // Re-derive the primary plan's plausibility purely to surface its suggested date in
        // the display metadata (the confidence cap itself was already applied per plan).
        $primaryPlausibility = $this->validateDatePlausibility($primaryDate, $inboundEmail->subject, $body, $receivedAt);

        return new OosEmailParseResult(
            date: $primaryDate,
            service: $primaryService,
            items: $primaryItems,
            confidenceScore: $primaryConfidence,
            needsReview: $primary instanceof OosEmailServicePlan ? $primary->needsReview : false,
            shouldImport: $primary instanceof OosEmailServicePlan ? $primary->shouldImport : false,
            importMetadata: [
                'confidence_score' => $primaryConfidence,
                'parse_method' => 'email_llm',
                'warnings' => array_values(array_unique($warnings)),
                'date_extraction' => [
                    'value' => $primaryDate,
                    'confidence' => $dateResolution['confidence'],
                    'method' => $dateResolution['method'],
                    'plausible' => $primaryPlausibility['plausible'],
                    'suggested_date' => $primaryPlausibility['suggested_date'],
                    'implausibility_reasons' => $primaryPlausibility['reasons'],
                ],
                'service_extraction' => [
                    'value' => $primaryService?->value,
                    'confidence' => $serviceResolution['confidence'],
                    'method' => $serviceResolution['method'],
                ],
                'item_extraction' => [
                    'confidence' => round($itemExtraction->confidence, 2),
                    'item_count' => count($primaryItems),
                    'notes' => $itemExtraction->notes,
                ],
                'service_plans' => $this->servicePlansMetadata($servicePlans),
                'source_message_id' => $inboundEmail->message_id,
                'source_subject' => $inboundEmail->subject,
            ],
            servicePlans: $servicePlans,
        );
    }

    /**
     * @param  array{date:?string,confidence:float,method:?string}  $dateResolution
     * @param  array{service:?SermonService,confidence:float,method:?string}  $serviceResolution
     * @return array{0:list<OosEmailServicePlan>,1:list<string>}
     */
    private function buildServicePlans(
        OosEmailItemExtractionResult $itemExtraction,
        array $dateResolution,
        array $serviceResolution,
        string $subject,
        string $body,
        CarbonImmutable $receivedAt,
    ): array {
        $warnings = [];

        // Legacy single-list extraction: one plan from the flattened items, corroborated by
        // the regex-derived service and email-level date.
        if ($itemExtraction->services === []) {
            $plan = $this->buildPlan(
                null,
                null,
                $itemExtraction->items,
                $itemExtraction->confidence,
                $dateResolution,
                $serviceResolution,
                $subject,
                $body,
                $receivedAt,
                $warnings,
            );

            return [[$plan], $warnings];
        }

        $plans = [];

        foreach ($itemExtraction->services as $rawPlan) {
            $plans[] = $this->buildPlan(
                $rawPlan['service'],
                $rawPlan['date'],
                $rawPlan['items'],
                $rawPlan['confidence'],
                $dateResolution,
                $serviceResolution,
                $subject,
                $body,
                $receivedAt,
                $warnings,
            );
        }

        return [$plans, $warnings];
    }

    /**
     * @param  array<int, array{type:string,title:string}>  $rawItems
     * @param  array{date:?string,confidence:float,method:?string}  $dateResolution
     * @param  array{service:?SermonService,confidence:float,method:?string}  $serviceResolution
     * @param  list<string>  $warnings
     */
    private function buildPlan(
        ?string $rawService,
        ?string $rawDate,
        array $rawItems,
        float $rawConfidence,
        array $dateResolution,
        array $serviceResolution,
        string $subject,
        string $body,
        CarbonImmutable $receivedAt,
        array &$warnings,
    ): OosEmailServicePlan {
        $service = $this->resolvePlanService($rawService, $serviceResolution);
        $serviceConfidence = $service === null
            ? 0.0
            : ($rawService !== null ? 0.9 : $serviceResolution['confidence']);

        [$date, $dateConfidence] = $this->resolvePlanDate($rawDate, $dateResolution, $receivedAt);
        $items = $this->normaliseItems($rawItems);

        $confidence = $this->calculateConfidence(
            $dateConfidence,
            $serviceConfidence,
            $rawConfidence,
            $items,
            $date !== null,
            $service instanceof SermonService,
        );

        $plausibility = $this->validateDatePlausibility($date, $subject, $body, $receivedAt);
        if (! $plausibility['plausible']) {
            $confidence = round(min($confidence, 0.74), 2);

            foreach ($plausibility['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }

        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $shouldImport = $confidence >= $reviewThreshold
            && $date !== null
            && $service instanceof SermonService
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

    /**
     * @param  array{service:?SermonService,confidence:float,method:?string}  $serviceResolution
     */
    private function resolvePlanService(?string $rawService, array $serviceResolution): ?SermonService
    {
        // No per-plan label at all (legacy single-list extraction): corroborate with the
        // email-level regex service.
        if ($rawService === null) {
            return $serviceResolution['service'];
        }

        // An explicit per-plan label the LLM emitted. Map it; an "unknown"/unmapped label
        // must NOT inherit the email-level service — that regex often resolves to the first
        // mention (e.g. morning), so an unknown second plan would silently land in the
        // morning slot. Return null instead so the plan is held for review.
        return match (strtolower(trim($rawService))) {
            'morning', 'am' => SermonService::Morning,
            'evening', 'pm' => SermonService::Evening,
            'other', 'special', 'carols', 'christmas' => SermonService::Other,
            default => null,
        };
    }

    /**
     * @param  array{date:?string,confidence:float,method:?string}  $dateResolution
     * @return array{0:?string,1:float}
     */
    private function resolvePlanDate(?string $rawDate, array $dateResolution, CarbonImmutable $receivedAt): array
    {
        if (is_string($rawDate) && $rawDate !== '') {
            $candidate = $this->safeDateFromFormat('Y-m-d', $rawDate);

            if ($candidate instanceof CarbonImmutable) {
                $date = $candidate->format('Y-m-d');

                // An extractor-supplied plan date may carry a hallucinated year — the model
                // defaults to its training period when the email names no year (e.g.
                // "sunday 14th June" received June 2026 came back as 2023-06-14). Trust it
                // only when it falls inside the received-at window; otherwise prefer the
                // email-level extraction, whose year inference is anchored to received_at.
                // Only the window applies here: a multi-date email's Christmas-morning plan
                // legitimately misses the subject's claimed weekday, so the weekday check
                // stays a per-plan review hold rather than a date-arbitration signal.
                if ($this->withinReceivedWindow($candidate, $receivedAt)) {
                    return [$date, 0.9];
                }

                if (is_string($dateResolution['date']) && $dateResolution['date'] !== $date) {
                    return [$dateResolution['date'], $dateResolution['confidence']];
                }

                // No better candidate; keep the out-of-window date and let the per-plan
                // plausibility cap hold it for review.
                return [$date, 0.9];
            }
        }

        return [$dateResolution['date'], $dateResolution['confidence']];
    }

    private function withinReceivedWindow(CarbonImmutable $resolved, CarbonImmutable $receivedAt): bool
    {
        $windowStart = $receivedAt->startOfDay();
        $maxFutureDays = (int) config('service-tracking.email_parsing.max_future_days', 14);

        return ! $resolved->startOfDay()->lessThan($windowStart)
            && ! $resolved->startOfDay()->greaterThan($windowStart->addDays($maxFutureDays));
    }

    /**
     * Morning-first, then the first importable plan, then the first plan of any shape.
     *
     * @param  list<OosEmailServicePlan>  $plans
     */
    private function primaryPlan(array $plans): ?OosEmailServicePlan
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

        return $plans[0] ?? null;
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
     * @return array{date:?string,confidence:float,method:?string}
     */
    private function extractDate(string $subject, string $body, CarbonImmutable $receivedAt): array
    {
        $sources = [
            'subject' => $subject,
            'body' => $body,
        ];

        foreach ($sources as $source => $text) {
            $candidate = $this->extractIsoDate($text, $source);
            if ($candidate !== null) {
                return $candidate;
            }

            $candidate = $this->extractNumericDate($text, $source, $receivedAt);
            if ($candidate !== null) {
                return $candidate;
            }

            $candidate = $this->extractTextualDate($text, $source, $receivedAt);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return [
            'date' => null,
            'confidence' => 0.0,
            'method' => null,
        ];
    }

    /**
     * @return array{service:?SermonService,confidence:float,method:?string}
     */
    private function extractService(string $subject, string $body): array
    {
        foreach (['subject' => $subject, 'body' => $body] as $source => $text) {
            if (preg_match('/\bmorning\b/i', $text) === 1 || preg_match('/(?:^|[\s(\[])(AM)(?:$|[\s)\]])/u', $text) === 1) {
                return [
                    'service' => SermonService::Morning,
                    'confidence' => $source === 'subject' ? 1.0 : 0.95,
                    'method' => "{$source}_keyword",
                ];
            }

            if (preg_match('/\bevening\b/i', $text) === 1 || preg_match('/(?:^|[\s(\[])(PM)(?:$|[\s)\]])/u', $text) === 1) {
                return [
                    'service' => SermonService::Evening,
                    'confidence' => $source === 'subject' ? 1.0 : 0.95,
                    'method' => "{$source}_keyword",
                ];
            }

            if (preg_match('/\b(\d{1,2})(?::|\.)?(\d{2})?\s*(am|pm)\b/i', $text, $matches) === 1) {
                $meridiem = strtolower($matches[3]);

                return [
                    'service' => $meridiem === 'am' ? SermonService::Morning : SermonService::Evening,
                    'confidence' => $source === 'subject' ? 0.82 : 0.78,
                    'method' => "{$source}_time_hint",
                ];
            }
        }

        return [
            'service' => null,
            'confidence' => 0.0,
            'method' => null,
        ];
    }

    /**
     * @param  array<int, array{type:string,title:string}>  $items
     * @return array<int, array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function normaliseItems(array $items): array
    {
        $normalised = [];
        $position = 1;

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

            $metadata = $storageType === 'custom'
                ? ['email_type' => $semanticType]
                : null;

            $normalised[] = [
                'position' => $position,
                'type' => $storageType,
                'section_type' => $semanticType,
                'title' => $title,
                'source_title' => $title,
                'openlp_search_title' => null,
                'metadata' => $metadata,
            ];

            $position++;
        }

        return $normalised;
    }

    /**
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     */
    private function calculateConfidence(
        float $dateConfidence,
        float $serviceConfidence,
        float $itemConfidence,
        array $items,
        bool $hasDate,
        bool $hasService,
    ): float {
        $confidence = ($dateConfidence * 0.35) + ($serviceConfidence * 0.20) + ($itemConfidence * 0.45);

        if (! $hasDate || ! $hasService) {
            $confidence = min($confidence, 0.74);
        }

        if ($items === []) {
            $confidence = min($confidence, 0.40);
        }

        return round(max(0.0, min(1.0, $confidence)), 2);
    }

    /**
     * @return array{date:string,confidence:float,method:string}|null
     */
    private function extractIsoDate(string $text, string $source): ?array
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches) !== 1) {
            return null;
        }

        $candidate = $this->safeDateFromFormat('Y-m-d', $matches[1]);
        if (! $candidate instanceof CarbonImmutable) {
            return null;
        }

        return [
            'date' => $candidate->format('Y-m-d'),
            'confidence' => $source === 'subject' ? 1.0 : 0.97,
            'method' => "{$source}_iso",
        ];
    }

    /**
     * @return array{date:string,confidence:float,method:string}|null
     */
    private function extractNumericDate(string $text, string $source, CarbonImmutable $receivedAt): ?array
    {
        // Slash-separated only: a hyphen separator turns every Bible verse range in these
        // emails ("Luke 2:1-7") into a phantom day-month date, and ISO dates are handled by
        // the dedicated extractIsoDate() pass.
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $text, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = $this->resolveYear($matches[3] ?? null, $month, $day, $receivedAt);

        $candidate = $this->safeDate($year, $month, $day);
        if ($candidate === null) {
            return null;
        }

        return [
            'date' => $candidate->format('Y-m-d'),
            'confidence' => $matches[3] ?? null
                ? ($source === 'subject' ? 0.95 : 0.92)
                : ($source === 'subject' ? 0.84 : 0.80),
            'method' => ($matches[3] ?? null) ? "{$source}_numeric" : "{$source}_numeric_inferred_year",
        ];
    }

    /**
     * @return array{date:string,confidence:float,method:string}|null
     */
    private function extractTextualDate(string $text, string $source, CarbonImmutable $receivedAt): ?array
    {
        $monthPattern = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';

        if (preg_match("/\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+({$monthPattern})(?:\\s+(\\d{4}))?\\b/i", $text, $matches) === 1) {
            $day = (int) $matches[1];
            $month = $this->monthNumber($matches[2]);
            $year = $this->resolveYear($matches[3] ?? null, $month, $day, $receivedAt);
            $candidate = $this->safeDate($year, $month, $day);

            if ($candidate !== null) {
                return [
                    'date' => $candidate->format('Y-m-d'),
                    'confidence' => $matches[3] ?? null
                        ? ($source === 'subject' ? 0.94 : 0.90)
                        : ($source === 'subject' ? 0.82 : 0.78),
                    'method' => ($matches[3] ?? null) ? "{$source}_textual" : "{$source}_textual_inferred_year",
                ];
            }
        }

        if (preg_match("/\\b({$monthPattern})\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,?\\s+(\\d{4}))?\\b/i", $text, $matches) === 1) {
            $month = $this->monthNumber($matches[1]);
            $day = (int) $matches[2];
            $year = $this->resolveYear($matches[3] ?? null, $month, $day, $receivedAt);
            $candidate = $this->safeDate($year, $month, $day);

            if ($candidate !== null) {
                return [
                    'date' => $candidate->format('Y-m-d'),
                    'confidence' => $matches[3] ?? null
                        ? ($source === 'subject' ? 0.94 : 0.90)
                        : ($source === 'subject' ? 0.82 : 0.78),
                    'method' => ($matches[3] ?? null) ? "{$source}_textual" : "{$source}_textual_inferred_year",
                ];
            }
        }

        return null;
    }

    private function resolveYear(?string $explicitYear, int $month, int $day, CarbonImmutable $receivedAt): int
    {
        if (is_string($explicitYear) && $explicitYear !== '') {
            $year = (int) $explicitYear;

            return $year < 100 ? 2000 + $year : $year;
        }

        $candidate = $this->safeDate($receivedAt->year, $month, $day);
        if (! $candidate instanceof CarbonImmutable) {
            return $receivedAt->year;
        }

        if ($candidate->lessThan($receivedAt->subMonths(6))) {
            return $receivedAt->year + 1;
        }

        if ($candidate->greaterThan($receivedAt->addMonths(6))) {
            return $receivedAt->year - 1;
        }

        return $receivedAt->year;
    }

    private function safeDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        try {
            // createSafe() throws InvalidDateException for out-of-range components
            // (e.g. month 15, 31 February, 29 February in a non-leap year) rather than
            // silently overflowing into an adjacent month/year like create() does.
            $candidate = CarbonImmutable::createSafe($year, $month, $day, 12, 0, 0);

            return $candidate instanceof CarbonImmutable ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeDateFromFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            $candidate = CarbonImmutable::createFromFormat($format, $value);

            if (! $candidate instanceof CarbonImmutable) {
                return null;
            }

            // createFromFormat() overflows impossible calendar dates silently
            // ('2025-02-31' becomes '2025-03-03'), so require an exact round-trip.
            return $candidate->format($format) === $value ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sanity-check a syntactically valid resolved date against the email arrival window and
     * any weekday claimed in the subject. Plan emails are forward-looking and short-horizon,
     * so a resolved date in the past, far in the future, or on the wrong weekday is suspect.
     *
     * @return array{plausible:bool,warnings:list<string>,suggested_date:?string,reasons:list<string>,claimed_weekday:?string}
     */
    private function validateDatePlausibility(?string $date, string $subject, string $body, CarbonImmutable $receivedAt): array
    {
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
        // Only trust a weekday claimed in the (short, authoritative) subject line — body text
        // routinely names other weekdays (e.g. midweek meetings) that would cause false holds.
        if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $subject, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Offer a single corrected date within the plausibility window: the same day-of-month in a
     * nearby month that lands on the claimed weekday (e.g. "Sunday 5 June" → 5 July). Only a
     * unique candidate is suggested; ambiguity yields no suggestion.
     */
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

    private function monthNumber(string $month): int
    {
        return match (strtolower($month)) {
            'jan', 'january' => 1,
            'feb', 'february' => 2,
            'mar', 'march' => 3,
            'apr', 'april' => 4,
            'may' => 5,
            'jun', 'june' => 6,
            'jul', 'july' => 7,
            'aug', 'august' => 8,
            'sep', 'sept', 'september' => 9,
            'oct', 'october' => 10,
            'nov', 'november' => 11,
            default => 12,
        };
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
            'other' => 'other',
            default => 'other',
        };
    }

    private function normaliseWhitespace(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = preg_replace("/\r\n?/", "\n", $value) ?? $value;
        $normalised = preg_replace("/[ \t]+/", ' ', $normalised) ?? $normalised;
        $normalised = preg_replace("/\n{3,}/", "\n\n", $normalised) ?? $normalised;
        $trimmed = trim($normalised);

        return $trimmed === '' ? null : $trimmed;
    }
}
