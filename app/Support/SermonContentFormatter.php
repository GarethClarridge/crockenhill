<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterval;
use Illuminate\Support\Str;

/**
 * Pure, dependency-free formatting of sermon scalar content.
 *
 * Extracted from SermonViewPresenter so duration and outline formatting can be
 * unit-tested in isolation, without the model, storage faking, or the presenter's
 * request-level memoization. Every method is a deterministic function of its
 * primitive inputs, so it needs no caching of its own.
 */
final class SermonContentFormatter
{
    /**
     * Format a duration in seconds as a human-friendly string (e.g. "1h 30m" or "45m").
     *
     * Returns null for missing or non-positive durations.
     */
    public static function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
    }

    /**
     * Format a duration in seconds as an ISO 8601 duration string (e.g. "PT45M").
     *
     * Returns null for missing or non-positive durations.
     */
    public static function iso8601Duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return CarbonInterval::seconds($seconds)->cascade()->spec();
    }

    /**
     * Render structured sermon points as a numbered plain-text outline.
     *
     * Each point may be a scalar (the point text) or an array shaped like
     * `['point' => string, 'sub_points' => array<int, scalar>]`. Empty points and
     * sub-points are skipped; an untitled point with sub-points is labelled
     * "(Untitled point)". Returns null when there is nothing to render.
     *
     * @param  mixed  $points
     */
    public static function plainTextOutline($points): ?string
    {
        if (! is_array($points) || $points === []) {
            return null;
        }

        $outline = '';
        $counter = 1;

        foreach ($points as $pointItem) {
            $mainText = '';
            $subLines = [];

            if (is_array($pointItem)) {
                $mainText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? trim((string) $pointItem['point']) : '';
                $subPoints = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];

                foreach ($subPoints as $subPoint) {
                    if (is_scalar($subPoint) && filled($subPoint)) {
                        $subLines[] = '   - '.trim((string) $subPoint);
                    }
                }
            } elseif (is_scalar($pointItem)) {
                $mainText = trim((string) $pointItem);
            }

            if ($mainText !== '' || count($subLines) > 0) {
                $outline .= "{$counter}. ".($mainText !== '' ? $mainText : '(Untitled point)')."\n";

                foreach ($subLines as $subLine) {
                    $outline .= "{$subLine}\n";
                }

                $counter++;
            }
        }

        return trim($outline) ?: null;
    }

    /**
     * The maximum length of a generated SEO meta description, before any
     * truncation ellipsis is appended.
     *
     * Set to 160 (Google's practical desktop rendering ceiling) so the brand
     * boilerplate ("at Crockenhill Baptist Church") and service phrase added to
     * the base sentence do not crowd out the scripture reference, series, and
     * summary excerpt that follow it in priority order.
     */
    private const META_DESCRIPTION_LIMIT = 160;

    /**
     * Assemble an SEO meta description from already-resolved sermon facts.
     *
     * This is the pure-string half of the presenter's metaDescription(): every
     * input is a primitive the caller has already resolved from the model, its
     * relations, and the exposure policy. The verb is chosen from media
     * availability, the base sentence is built from the title/preacher/date plus
     * optional reference and series, and an optional summary is appended only if
     * the whole thing still fits within META_DESCRIPTION_LIMIT (otherwise it is
     * truncated to fit, or dropped entirely when there is no room).
     *
     * @param  string  $title  The sermon title.
     * @param  string  $preacherName  The display preacher name (caller substitutes a fallback such as "Unknown preacher").
     * @param  string  $humanDate  The human-friendly preached-on date (e.g. "March 14, 2025").
     * @param  ?string  $reference  The scripture reference, or null.
     * @param  ?string  $series  The series name, or null.
     * @param  ?string  $serviceLabel  The service label (e.g. "Morning"), or null to omit the "during our … service" phrase.
     * @param  bool  $hasVideo  Whether a video is exposed for this sermon.
     * @param  bool  $hasAudio  Whether audio is available for this sermon.
     * @param  ?string  $summary  The plain-text summary to append, or null to omit it (caller strips tags and applies show_summary).
     */
    public static function metaDescription(
        string $title,
        string $preacherName,
        string $humanDate,
        ?string $reference,
        ?string $series,
        ?string $serviceLabel,
        bool $hasVideo,
        bool $hasAudio,
        ?string $summary,
    ): string {
        $verb = match (true) {
            $hasVideo && $hasAudio => 'Watch or listen to',
            $hasVideo => 'Watch',
            default => 'Listen to',
        };

        $suffix = '';

        if (filled($reference)) {
            $suffix .= " - {$reference}";
        }

        if (filled($series)) {
            $suffix .= " (Part of our {$series} series)";
        }

        $lead = "{$verb} '{$title}' by {$preacherName} preached at Crockenhill Baptist Church on {$humanDate}";
        $servicePhrase = filled($serviceLabel) ? " during our {$serviceLabel} service" : '';

        $summary = $summary === null ? '' : trim($summary);

        // The service phrase is the lowest-priority enrichment, below both the
        // scripture reference/series suffix and the summary excerpt. It is only
        // woven in after the date when doing so leaves everything else intact
        // within the limit: the suffix is never truncated mid-word, and the full
        // untruncated summary (when present) is never shortened just to keep it.
        $costOfSummary = $summary === '' ? 0 : Str::length(". {$summary}");
        $base = ($servicePhrase !== '' && Str::length($lead.$servicePhrase.$suffix) + $costOfSummary <= self::META_DESCRIPTION_LIMIT)
            ? $lead.$servicePhrase.$suffix
            : $lead.$suffix;

        if ($summary === '') {
            return Str::limit($base, self::META_DESCRIPTION_LIMIT);
        }

        $full = "{$base}. {$summary}";
        if (Str::length($full) <= self::META_DESCRIPTION_LIMIT) {
            return $full;
        }

        $remaining = self::META_DESCRIPTION_LIMIT - Str::length($base) - 2; // 2 for ". "

        if ($remaining > 0) {
            return $base.'. '.Str::limit($summary, $remaining);
        }

        return Str::limit($base, self::META_DESCRIPTION_LIMIT);
    }
}
