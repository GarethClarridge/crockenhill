<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonService;
use Carbon\Carbon;

/**
 * Pure parsing of sermon dates and services from filenames and timestamps.
 *
 * Extracted from MetadataExtractionService so the regex-heavy, IO-free
 * inference rules can be unit-tested in isolation, without FFprobe, GetID3, or
 * Storage. Every method here is a deterministic function of its inputs.
 */
class SermonFilenameParser
{
    /**
     * @var array<string, int>
     */
    private const MONTH_NAME_NUMBERS = [
        'jan' => 1,
        'january' => 1,
        'feb' => 2,
        'february' => 2,
        'mar' => 3,
        'march' => 3,
        'apr' => 4,
        'april' => 4,
        'may' => 5,
        'jun' => 6,
        'june' => 6,
        'jul' => 7,
        'july' => 7,
        'aug' => 8,
        'august' => 8,
        'sep' => 9,
        'sept' => 9,
        'september' => 9,
        'oct' => 10,
        'october' => 10,
        'nov' => 11,
        'november' => 11,
        'dec' => 12,
        'december' => 12,
    ];

    /**
     * Extract a sermon date from a filename using multiple regex patterns.
     *
     * Strategy prioritizes ISO format (YYYY-MM-DD) for its lack of ambiguity,
     * followed by European format (DD-MM-YYYY) and compact format (YYYYMMDD).
     * If no full date is found, it attempts to extract a 4-digit year.
     *
     * @param  string  $filename  The filename to parse
     * @return Carbon The extracted date, or Carbon::today() as a final fallback
     */
    public function extractDateFromFilename(string $filename): Carbon
    {
        // Remove only the last extension (e.g., .mp3) to preserve dates with dots
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        $matched = false;
        $explicitDate = $this->parseExplicitDate($nameWithoutExtension, $matched);

        if ($explicitDate !== null) {
            return $explicitDate;
        }

        // If we found a date pattern but it was invalid, don't try year extraction
        if ($matched) {
            return Carbon::today();
        }

        // Named month format: 5th April 2026, 5 April 2026, April 5 2026
        $namedMonthDate = $this->tryExtractNamedMonthDate($nameWithoutExtension);
        if ($namedMonthDate !== null) {
            return $namedMonthDate;
        }

        // Year only extraction (only if no date pattern was found)
        if (preg_match('/(\d{4})/', $nameWithoutExtension, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= now()->year + 1) {
                return Carbon::createFromDate($year, now()->month, now()->day);
            }
        }

        // Fallback to current date
        return Carbon::today();
    }

    /**
     * Like extractDateFromFilename() but returns null when no recognisable date pattern is found,
     * rather than falling back to Carbon::today(). Used for date-comparison logic where the
     * absence of a date must be distinguishable from a real extracted date.
     */
    public function tryExtractDateFromFilename(string $filename): ?Carbon
    {
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        $matched = false;
        $explicitDate = $this->parseExplicitDate($nameWithoutExtension, $matched);

        if ($explicitDate !== null || $matched) {
            return $explicitDate;
        }

        // Named month format: 5th April 2026, 5 April 2026, April 5 2026
        return $this->tryExtractNamedMonthDate($nameWithoutExtension);
    }

    /**
     * Determine the sermon service based on a specific time of day.
     *
     * Uses a fixed cutoff: services starting between 06:00 and 13:59 are
     * classified as Morning, all others as Evening.
     *
     * @param  Carbon  $time  The time to evaluate
     * @return SermonService The identified service
     */
    public function determineServiceFromTime(Carbon $time): SermonService
    {
        $hour = $time->hour;

        if ($hour >= 6 && $hour < 14) {
            return SermonService::Morning;
        }

        return SermonService::Evening;
    }

    /**
     * Determine the sermon service based on filename keywords and patterns.
     *
     * Scans for service names ("morning", "evening"), time indicators ("AM", "PM"),
     * and robust time patterns (HH-MM, HH:MM, HHMM after date).
     *
     * @param  string  $filename  The filename to scan
     * @return SermonService The identified service (defaults to Morning)
     */
    public function determineServiceFromFilename(string $filename): SermonService
    {
        $lowerFilename = strtolower($filename);

        // Strategy 1: Keywords take highest precedence
        if (
            str_contains($lowerFilename, 'evening') ||
            preg_match('/[^a-z]pm[^a-z]/', $lowerFilename) ||
            preg_match('/^pm[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]pm$/', $lowerFilename)
        ) {
            return SermonService::Evening;
        }

        if (
            str_contains($lowerFilename, 'morning') ||
            preg_match('/[^a-z]am[^a-z]/', $lowerFilename) ||
            preg_match('/^am[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]am$/', $lowerFilename)
        ) {
            return SermonService::Morning;
        }

        // Strategy 2: Common fixed service times
        if (
            preg_match('/6[:\-\s]?30/', $lowerFilename) ||
            preg_match('/7[:\-\s]?00/', $lowerFilename) ||
            preg_match('/18[:\-\s]?30/', $lowerFilename) ||
            preg_match('/19[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::Evening;
        }

        if (
            preg_match('/10[:\-\s]?30/', $lowerFilename) ||
            preg_match('/11[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::Morning;
        }

        // Strategy 3: Robust time extraction
        $hour = $this->extractHourFromFilename($filename);
        if ($hour !== null) {
            // Use 2 PM (14:00) cutoff for church service classification
            return $hour < 14 ? SermonService::Morning : SermonService::Evening;
        }

        return SermonService::Morning;
    }

    /**
     * Extract hour from filename using multiple strategies.
     */
    private function extractHourFromFilename(string $filename): ?int
    {
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        // Strategy A: Match time with colon separator (safest as colons aren't used in dates)
        if (preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)/', $nameWithoutExtension, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy E: Match HH-MM or HH.MM as the standalone filename (common for segment recording)
        if (preg_match('/^(\d{1,2})[\.\-](\d{2})$/', $nameWithoutExtension, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy B: Match time with dot or dash separator AFTER a date (to avoid confusion with date parts)
        $datePattern = '(?:\d{4}[-_\s\.\/]\d{1,2}[-_\s\.\/]\d{1,2}|\d{1,2}[-_\s\.\/]\d{1,2}[-_\s\.\/]\d{4}|\d{8})';
        if (preg_match('/'.$datePattern.'[-_\s\.\/](\d{1,2})[\.\-](\d{2})(?!\d)/', $nameWithoutExtension, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy C: Match HHMM format after a date
        if (preg_match('/'.$datePattern.'[-_\s](\d{2})(\d{2})/', $nameWithoutExtension, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy D: Match standalone HHMM format at start of filename
        if (preg_match('/^(\d{2})(\d{2})(?![-_]?\d)/', $nameWithoutExtension, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        return null;
    }

    /**
     * Verify if the given year, month, and day form a valid calendar date.
     *
     * Constraints: Year must be between 1900 and (current year + 1).
     *
     * @param  int  $year  Four-digit year
     * @param  int  $month  Month (1-12)
     * @param  int  $day  Day (1-31)
     * @return bool True if the date is valid and within bounds
     */
    public function isValidDate(int $year, int $month, int $day): bool
    {
        if ($year < 1900 || $year > now()->year + 1) {
            return false;
        }

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return checkdate($month, $day, $year);
    }

    /**
     * Parse explicit date patterns (ISO, European, Compact) from a filename.
     */
    private function parseExplicitDate(string $nameWithoutExtension, bool &$matched): ?Carbon
    {
        // ISO format: YYYY-MM-DD
        if (preg_match('/(\d{4})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{1,2})/', $nameWithoutExtension, $matches)) {
            $matched = true;
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return $this->isValidDate($year, $month, $day) ? Carbon::createFromDate($year, $month, $day) : null;
        }

        // European format: DD-MM-YYYY
        if (preg_match('/(\d{1,2})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{4})/', $nameWithoutExtension, $matches)) {
            $matched = true;
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            return $this->isValidDate($year, $month, $day) ? Carbon::createFromDate($year, $month, $day) : null;
        }

        // Compact format: YYYYMMDD
        if (preg_match('/(\d{4})(\d{2})(\d{2})/', $nameWithoutExtension, $matches)) {
            $matched = true;
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return $this->isValidDate($year, $month, $day) ? Carbon::createFromDate($year, $month, $day) : null;
        }

        $matched = false;

        return null;
    }

    private function tryExtractNamedMonthDate(string $nameWithoutExtension): ?Carbon
    {
        $monthNames = implode('|', array_keys(self::MONTH_NAME_NUMBERS));

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?[\s_\-]+('.$monthNames.')[\s_\-,]+(\d{4})\b/i', $nameWithoutExtension, $matches)) {
            $day = (int) $matches[1];
            $month = self::MONTH_NAME_NUMBERS[strtolower($matches[2])];
            $year = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }

            return null;
        }

        if (preg_match('/\b('.$monthNames.')[\s_\-]+(\d{1,2})(?:st|nd|rd|th)?(?:,)?[\s_\-,]+(\d{4})\b/i', $nameWithoutExtension, $matches)) {
            $month = self::MONTH_NAME_NUMBERS[strtolower($matches[1])];
            $day = (int) $matches[2];
            $year = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }

            return null;
        }

        return null;
    }
}
