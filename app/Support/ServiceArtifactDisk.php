<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Media\Audio\ServiceArtifactStorage;

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

    /**
     * Where {@see ServiceArtifactStorage::archiveAudio()} keeps a
     * run's service audio — a durable artifact beside the transcripts.
     */
    public const AUDIO_PREFIX = 'service-audio/';

    /**
     * Options for every write of a service artifact.
     *
     * Transcripts, prompts and model responses are never served. The fallback transcript
     * disk can be a publicly visible bucket, so privacy is stated on each write rather
     * than inherited from whichever disk the artifacts land on.
     */
    public const WRITE_OPTIONS = ['visibility' => 'private'];

    public static function for(?string $path): string
    {
        return self::isArtifactPath($path) ? self::name() : self::tempDisk();
    }

    /**
     * The disk durable artifacts are written to and read from: `service_artifact_disk` when
     * set, otherwise `transcript_disk`.
     *
     * The fallback is read here rather than in the config file so it follows a transcript
     * disk swapped at runtime, as the historic staging context does.
     */
    public static function name(): string
    {
        $configured = config('media-processing.storage.service_artifact_disk');

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::transcriptDisk();
    }

    /**
     * Whether the path names a durable artifact of either family, transcripts or service audio.
     */
    public static function isArtifactPath(?string $path): bool
    {
        return is_string($path)
            && (str_starts_with($path, self::DURABLE_PREFIX) || str_starts_with($path, self::AUDIO_PREFIX));
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
