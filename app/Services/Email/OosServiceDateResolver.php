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
            return $this->date('!Y-m-d', $matches[0], (int) $matches[3], (int) $matches[1]);
        }

        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](20\d{2})\b/', $evidence, $matches) === 1) {
            // `$matches[0]` rather than the recomposed groups on purpose: the pattern also admits a
            // `.` separator, which this format has never parsed, and normalising it here would newly
            // resolve dates the corpus was measured without. That is a separate question from the
            // overflow guard below and is left to the historic lane to decide deliberately.
            return $this->date('!j/n/Y', $matches[0], (int) $matches[1], (int) $matches[3]);
        }

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)(?:\s+(20\d{2}))?\b/iu', $evidence, $matches) === 1) {
            $year = $matches[3] ?? $this->contextYear($source, $matches[2]);

            return $year === null
                ? null
                : $this->date('!j F Y', "{$matches[1]} {$matches[2]} {$year}", (int) $matches[1], (int) $year);
        }

        if (preg_match('/\bchristmas\s+(?:morning|day)\b/iu', $evidence) === 1) {
            return $this->contextDate($source, 12, 25);
        }

        if (preg_match('/\bsunday(?:\s+(?:morning|evening))?\s*\(?\s*(\d{1,2})(?:st|nd|rd|th)?\b/iu', $evidence, $matches) === 1) {
            return $this->contextMonthSunday($source, (int) $matches[1]);
        }

        return $this->relativeDate($source, $evidence);
    }

    private function contextDate(OosEmailSourceDocument $source, int $month, int $day): ?string
    {
        $received = $this->receivedDate($source);

        if (! $received instanceof CarbonImmutable) {
            return null;
        }

        $date = CarbonImmutable::create($received->year, $month, $day);

        if (! $date instanceof CarbonImmutable || $date->month !== $month || $date->day !== $day) {
            return null;
        }

        return $date->toDateString();
    }

    private function contextMonthSunday(OosEmailSourceDocument $source, int $day): ?string
    {
        $received = $this->receivedDate($source);

        if (! $received instanceof CarbonImmutable) {
            return null;
        }

        $date = CarbonImmutable::create($received->year, $received->month, $day);

        if (! $date instanceof CarbonImmutable
            || $date->month !== $received->month
            || $date->day !== $day
            || ! $date->isSunday()) {
            return null;
        }

        return $date->toDateString();
    }

    private function relativeDate(OosEmailSourceDocument $source, string $evidence): ?string
    {
        $received = $this->receivedDate($source);

        if (! $received instanceof CarbonImmutable) {
            return null;
        }

        /**
         * `tomorrow` is honoured only where it lands on a Sunday.
         *
         * The word is matched anywhere in the flattened evidence, so it frequently modifies
         * something other than the service: "I'll send the PPT to the admin address tomorrow or
         * Saturday" in a Thursday email resolved a Sunday service to the Friday, because this rung
         * was tested before every other and returned unconditionally. Nothing here can tell what a
         * relative marker refers to, but a `tomorrow` that is not a Sunday is evidence it refers to
         * something else, and the rungs below — a named day, or the Sunday on or after receipt —
         * answer that source better than a bare offset does.
         *
         * Almost every genuine use arrives on a Saturday, where the guard is a no-op.
         */
        if (preg_match('/\btomorrow\b/iu', $evidence) === 1) {
            $tomorrow = $received->addDay();

            if ($tomorrow->isSunday()) {
                return $tomorrow->toDateString();
            }
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

    /**
     * These emails normally describe services from the receipt date through the following two
     * weeks (see relativeDate() above), so a yearless month name is always the *next* occurrence
     * of that month on or after receipt — not necessarily the receipt date's own calendar year.
     * A month chronologically earlier than the received month names a date the corpus has already
     * passed if read in the received year, so it must be next year's occurrence instead: the
     * December-email-about-a-January-service shape that crosses a year boundary.
     */
    private function contextYear(OosEmailSourceDocument $source, string $monthName): ?string
    {
        $received = $this->receivedDate($source);

        if (! $received instanceof CarbonImmutable) {
            return null;
        }

        $month = CarbonImmutable::parse("1 {$monthName}")->month;

        return (string) ($month < $received->month ? $received->year + 1 : $received->year);
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

    /**
     * Parse an explicit source-stated date, refusing one that does not exist.
     *
     * Carbon *normalises* an overflowing date rather than rejecting it: `31 February 2018` parses
     * to `3 March 2018`, and `31/2/2018` the same. Without this check an impossible source value
     * would silently become a plausible *different* service identity, which is worse than no date
     * at all: the archive path has manifest corroboration to catch it, but the weekly evidence path
     * has no manifest and would keep the wrong identity.
     *
     * The guard compares the parsed day and year against the digits the source actually stated
     * rather than reformatting the string, because the calling patterns accept separators and month
     * spellings that a round-trip would not reproduce (`8.1.2018`, `2nd february`). Any overflow
     * moves the day, so a day that survives unchanged means the date exists. {@see self::receivedDate()}
     * makes the same refusal for the single fixed format it accepts.
     */
    private function date(string $format, string $value, int $statedDay, int $statedYear): ?string
    {
        try {
            $date = CarbonImmutable::createFromFormat($format, $value);

            return $date instanceof CarbonImmutable && $date->day === $statedDay && $date->year === $statedYear
                ? $date->toDateString()
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
