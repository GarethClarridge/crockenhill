<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves which disk a recorded service-artifact path lives on.
 *
 * The estate is mixed on purpose. Runs completed before the durability work
 * recorded temp-disk-relative keys (`temp/service_transcript_*.json`,
 * `temp/rms_*.log`); runs after it record durable keys under
 * `service-transcripts/`. Both must stay readable, and the two disks are
 * genuinely different in production (`do_spaces` versus `local`).
 *
 * Every reader of `service_transcript_path` / `rms_log_path` must resolve the
 * disk through here. Open-coding the prefix test is how AnalyzeSegments came to
 * look for a Spaces object on the local disk.
 */
final class ServiceArtifactDisk
{
    public const DURABLE_PREFIX = 'service-transcripts/';

    public static function for(?string $path): string
    {
        return is_string($path) && str_starts_with($path, self::DURABLE_PREFIX)
            ? self::transcriptDisk()
            : self::tempDisk();
    }

    public static function isDurable(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::DURABLE_PREFIX);
    }

    private static function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', 'local');
    }

    private static function tempDisk(): string
    {
        return (string) config('media-processing.storage.temp_disk', 'local');
    }
}
