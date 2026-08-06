<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use RuntimeException;

/**
 * Staging output is unpromoted media sitting under keys that belong to
 * production rows, so it must never become addressable over HTTP.
 *
 * The trigger is the disk's identity, not whether a batch happens to be
 * running. A reviewer's browser request holds no staging context, so a
 * context-scoped check would pass in exactly the situation that matters —
 * a page rendering a URL for media that only exists in staging.
 */
final class HistoricStagingUrlGuard
{
    public static function assertAllowed(string $disk): void
    {
        if (self::isStagingDisk($disk)) {
            throw new RuntimeException(
                "Historic staging disk '{$disk}' holds unpromoted media, so it cannot be exposed through a public or CDN URL."
            );
        }
    }

    /**
     * For request-handling callers, which should present a missing asset rather
     * than a 500 when a staging batch has the media disks pointed at staging.
     */
    public static function isStagingDisk(string $disk): bool
    {
        $staging = (string) config('media-processing.storage.historic_staging_disk', '');

        return $staging !== '' && $disk === $staging;
    }
}
