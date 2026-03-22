<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailParseResult;
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

        $dateResolution = $this->extractDate($inboundEmail->subject, $body, CarbonImmutable::instance($inboundEmail->received_at));
        $serviceResolution = $this->extractService($inboundEmail->subject, $body);
        $itemExtraction = $this->itemExtractor->extract($inboundEmail->subject, $body);

        $warnings = [];

        if ($dateResolution['date'] === null) {
            $warnings[] = 'Could not confidently infer the service date from the email.';
        }

        if ($serviceResolution['service'] === null) {
            $warnings[] = 'Could not confidently infer the service type from the email.';
        }

        foreach ($itemExtraction->notes as $note) {
            $warnings[] = $note;
        }

        $items = $this->normaliseItems($itemExtraction->items);
        $confidenceScore = $this->calculateConfidence(
            $dateResolution['confidence'],
            $serviceResolution['confidence'],
            $itemExtraction->confidence,
            $items,
            $dateResolution['date'] !== null,
            $serviceResolution['service'] !== null,
        );

        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $shouldImport = $confidenceScore >= $reviewThreshold
            && $dateResolution['date'] !== null
            && $serviceResolution['service'] instanceof SermonService;

        return new OosEmailParseResult(
            date: $dateResolution['date'],
            service: $serviceResolution['service'],
            items: $items,
            confidenceScore: $confidenceScore,
            needsReview: $shouldImport && $confidenceScore < $autoImportThreshold,
            shouldImport: $shouldImport,
            importMetadata: [
                'confidence_score' => $confidenceScore,
                'parse_method' => 'email_llm',
                'warnings' => array_values(array_unique($warnings)),
                'date_extraction' => [
                    'value' => $dateResolution['date'],
                    'confidence' => $dateResolution['confidence'],
                    'method' => $dateResolution['method'],
                ],
                'service_extraction' => [
                    'value' => $serviceResolution['service']?->value,
                    'confidence' => $serviceResolution['confidence'],
                    'method' => $serviceResolution['method'],
                ],
                'item_extraction' => [
                    'confidence' => round($itemExtraction->confidence, 2),
                    'item_count' => count($items),
                    'notes' => $itemExtraction->notes,
                ],
                'source_message_id' => $inboundEmail->message_id,
                'source_subject' => $inboundEmail->subject,
            ],
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
                    'service' => SermonService::MORNING,
                    'confidence' => $source === 'subject' ? 1.0 : 0.95,
                    'method' => "{$source}_keyword",
                ];
            }

            if (preg_match('/\bevening\b/i', $text) === 1 || preg_match('/(?:^|[\s(\[])(PM)(?:$|[\s)\]])/u', $text) === 1) {
                return [
                    'service' => SermonService::EVENING,
                    'confidence' => $source === 'subject' ? 1.0 : 0.95,
                    'method' => "{$source}_keyword",
                ];
            }

            if (preg_match('/\b(\d{1,2})(?::|\.)?(\d{2})?\s*(am|pm)\b/i', $text, $matches) === 1) {
                $meridiem = strtolower($matches[3]);

                return [
                    'service' => $meridiem === 'am' ? SermonService::MORNING : SermonService::EVENING,
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
        if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{2,4}))?\b/', $text, $matches) !== 1) {
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
            $candidate = CarbonImmutable::create($year, $month, $day, 12, 0, 0);

            return $candidate instanceof CarbonImmutable ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeDateFromFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            $candidate = CarbonImmutable::createFromFormat($format, $value);

            return $candidate instanceof CarbonImmutable ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
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
