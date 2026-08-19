<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use Carbon\CarbonImmutable;
use Throwable;

class OosServiceDateResolver
{
    /** @param list<int> $evidenceLineIds */
    public function resolve(OosEmailSourceDocument $source, array $evidenceLineIds): ?string
    {
        $evidence = implode("\n", array_filter([
            $source->subject,
            ...array_map($source->line(...), $evidenceLineIds),
        ], is_string(...)));

        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $evidence, $matches) === 1) {
            return $this->date('Y-m-d', $matches[0]);
        }

        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](20\d{2})\b/', $evidence, $matches) === 1) {
            return $this->date('!j/n/Y', $matches[0]);
        }

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)(?:\s+(20\d{2}))?\b/iu', $evidence, $matches) === 1) {
            $year = $matches[3] ?? $this->contextYear($source);

            return $year === null ? null : $this->date('!j F Y', "{$matches[1]} {$matches[2]} {$year}");
        }

        return $this->relativeDate($source, $evidence);
    }

    private function relativeDate(OosEmailSourceDocument $source, string $evidence): ?string
    {
        $received = $this->receivedDate($source);

        if (! $received instanceof CarbonImmutable) {
            return null;
        }

        if (preg_match('/\btomorrow\b/iu', $evidence) === 1) {
            return $received->addDay()->toDateString();
        }

        if (preg_match('/\b(?:this|next)\s+sunday\b/iu', $evidence, $matches) === 1) {
            return mb_strtolower($matches[0]) === 'this sunday'
                ? $this->sundayOnOrAfter($received)
                : $received->next(CarbonImmutable::SUNDAY)->toDateString();
        }

        // Lowest rung: no source-stated date, phrase or explicit relative marker was found at
        // all. This is the commonest form in the corpus ("details for Sun", "Sunday morning",
        // "order of service for Sunday") and every explicit pattern above already had first
        // refusal, so a source-stated date always wins over this guess. It is suppressed for a
        // named special service (Christmas, Easter, a wedding, ...), because those are not "the
        // next Sunday" and guessing one produces a plausible but wrong date rather than a
        // correctly-held null.
        if (preg_match(OosEmailExtractionValidator::SPECIAL_SERVICE_PATTERN, $evidence) === 1) {
            return null;
        }

        return $this->sundayOnOrAfter($received);
    }

    private function sundayOnOrAfter(CarbonImmutable $date): string
    {
        return $date->isSunday() ? $date->toDateString() : $date->next(CarbonImmutable::SUNDAY)->toDateString();
    }

    private function contextYear(OosEmailSourceDocument $source): ?string
    {
        return $this->receivedDate($source)?->format('Y');
    }

    private function receivedDate(OosEmailSourceDocument $source): ?CarbonImmutable
    {
        if ($source->receivedDate === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $source->receivedDate);

            return $date instanceof CarbonImmutable && $date->toDateString() === $source->receivedDate
                ? $date
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function date(string $format, string $value): ?string
    {
        try {
            $date = CarbonImmutable::createFromFormat($format, $value);

            return $date instanceof CarbonImmutable ? $date->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
