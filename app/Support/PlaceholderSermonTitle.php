<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether a sermon title is a machine placeholder rather than editorial.
 *
 * The pipeline creates a sermon before it has anything to call it, so the record
 * starts life holding whatever the source filename or the service slot produced.
 * AI analysis later offers a real title, and it may only take the record's title
 * when the incumbent is one of these placeholders — a curated title always wins.
 *
 * Recognising too little is the failure the historic-video pilot hit: all fifteen
 * sermons kept a filename title even though good analysis titles existed for them.
 * Recognising too much is worse, because it would let the model overwrite a title
 * an editor chose, so every rule here matches a shape only a machine produces.
 */
final class PlaceholderSermonTitle
{
    /**
     * Bracketed provenance suffixes such as "[YouTube backup]".
     *
     * Nothing but a filename carries one: it is how the archive marks which
     * upload a recording was recovered from. Ten of the fifteen pilot titles
     * ended in one, behind date, church-name and service-name prefixes too
     * varied to enumerate, so the suffix alone settles the title.
     */
    private const BACKUP_SUFFIX = '/\[[^\]]*\bback[\s-]?up\b[^\]]*\]\s*$/i';

    /** Bare service slots — "Morning", "Evening service" — with no title at all. */
    private const BARE_SERVICE_SLOT = '/^(morning|evening|afternoon|other)(\s+service)?$/i';

    public static function matches(string $title): bool
    {
        $normalized = self::normalize($title);

        if ($normalized === '') {
            return true;
        }

        if (preg_match(self::BACKUP_SUFFIX, $normalized) === 1) {
            return true;
        }

        if (preg_match(self::BARE_SERVICE_SLOT, $normalized) === 1) {
            return true;
        }

        if (preg_match('/^untitled(?:\s+sermon)?$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(morning|evening|other)\s+sermon\s+-\s+/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^sermon\s+-\s+/i', $normalized) === 1) {
            return true;
        }

        /** A media extension survived into the title. */
        if (preg_match('/\.(mp3|wav|m4a|mp4|mov|avi|mkv|flac|aac|ogg)$/i', $normalized) === 1) {
            return true;
        }

        /** Bare time-like fragments cut from raw filenames, e.g. "18 08", "10 31 2". */
        if (preg_match('/^\d{1,2}(?:\s+\d{2})+(?:\s+\d+)?$/', $normalized) === 1) {
            return true;
        }

        /** Date-only titles from date-named uploads, e.g. "Sunday 3Rd May 2026". */
        if (preg_match(
            '/^(?:(?:sun|mon|tues|wednes|thurs|fri|satur)day\s+)?\d{1,2}(?:st|nd|rd|th)?\s+[a-z]+(?:\s+\d{4})?$/i',
            $normalized,
        ) === 1) {
            return true;
        }

        /** Hash-like runs and UUIDs that only a machine would name a sermon. */
        if (preg_match('/[a-z0-9]{20,}/i', $normalized) === 1) {
            return true;
        }

        return preg_match(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $normalized,
        ) === 1;
    }

    /**
     * Titles cut from filenames carry runs of whitespace where separators were
     * removed, so every rule here reads a single-spaced form.
     */
    private static function normalize(string $title): string
    {
        return trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    }
}
