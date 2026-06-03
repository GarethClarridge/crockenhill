<?php

declare(strict_types=1);

namespace App\Services;

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
     * Scans for time patterns (HH-MM), service names ("morning", "evening"),
     * and time indicators ("AM", "PM", "18:30").
     *
     * @param  string  $filename  The filename to scan
     * @return SermonService The identified service (defaults to Morning)
     */
    public function determineServiceFromFilename(string $filename): SermonService
    {
        $lowerFilename = strtolower($filename);

        // Try to extract time in HH-MM or HH:MM format (e.g., "18-07.mkv" or "10:30.mp3")
        // Look for patterns like: 18-07, 18:07, 1807 (two digits, separator optional, two digits)
        if (preg_match('/(\d{1,2})[-:\.](\d{2})/', $filename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a reasonable time (hour 0-23, minute 0-59)
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                // Use the same 2 PM (14:00) cutoff
                if ($hour < 14) {
                    return SermonService::Morning;
                }

                return SermonService::Evening;
            }
        }

        // Morning patterns
        if (
            preg_match('/morning/', $lowerFilename) ||
            preg_match('/[^a-z]am[^a-z]/', $lowerFilename) ||
            preg_match('/^am[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]am$/', $lowerFilename) ||
            preg_match('/10[:\-\s]?30/', $lowerFilename) ||
            preg_match('/11[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::Morning;
        }

        // Evening patterns
        if (
            preg_match('/evening/', $lowerFilename) ||
            preg_match('/[^a-z]pm[^a-z]/', $lowerFilename) ||
            preg_match('/^pm[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]pm$/', $lowerFilename) ||
            preg_match('/6[:\-\s]?30/', $lowerFilename) ||
            preg_match('/7[:\-\s]?00/', $lowerFilename) ||
            preg_match('/18[:\-\s]?30/', $lowerFilename) ||
            preg_match('/19[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::Evening;
        }

        return SermonService::Morning;
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
