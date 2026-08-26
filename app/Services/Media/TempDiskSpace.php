<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Single source of truth for the local temp disk's location and free-space floor.
 *
 * The temp disk (not the sermon/Spaces disk) is the real pipeline bottleneck: it
 * stages every source upload and intermediate FFmpeg artefact. Routing both the
 * upload validator and the historic importer guard through this helper keeps their
 * notion of "enough headroom to dispatch" identical and reads the threshold from
 * one config key (media-processing.storage.temp_disk_min_free_gb).
 */
class TempDiskSpace
{
    /**
     * Absolute filesystem path backing the configured temp disk. Falls back to
     * storage_path('app') when the disk has no `root` (e.g. a misconfigured cloud
     * disk) so callers always probe a real local path rather than null.
     */
    public static function path(): string
    {
        $disk = config('media-processing.storage.temp_disk', 'local');

        $root = config("filesystems.disks.{$disk}.root");

        return is_string($root) && $root !== '' ? $root : storage_path('app');
    }

    /**
     * Configured minimum free-space floor for the temp disk, in bytes.
     */
    public static function minFreeBytes(): int
    {
        return (int) config('media-processing.storage.temp_disk_min_free_gb', 20) * 1024 ** 3;
    }

    /**
     * Whether the operator has declared this temp volume unmeasurable.
     *
     * This exists because `disk_free_space()` does not always fail honestly. Through a Docker bind
     * mount onto a nested APFS volume it returns the *parent* filesystem's figure — a confidently
     * wrong number rather than `false` — so no amount of care inside a check can tell it is being
     * lied to. The operator declares the volume unreadable and carries the headroom judgement.
     *
     * Deliberately its own key rather than a floor of zero. Zero already means "no floor, but still
     * refuse work larger than the readable free space", which TempDiskSpaceTest pins; unmeasurable
     * is a different statement from unbounded.
     *
     * Every gate that sizes work from free space must consult this, or disabling it at one gate
     * simply moves the failure to the next one.
     */
    public static function checksDisabled(): bool
    {
        return (bool) config('media-processing.storage.temp_disk_unmeasurable', false);
    }

    /**
     * Whether the temp disk has room for `$requiredBytes` of work without dropping
     * below the configured free-space floor. Returns true when free space cannot be
     * measured (e.g. unsupported filesystem) so an unmeasurable disk never blocks.
     */
    public static function hasSpaceFor(int $requiredBytes): bool
    {
        if (self::checksDisabled()) {
            return true;
        }

        $freeBytes = @disk_free_space(self::path());

        if ($freeBytes === false) {
            return true;
        }

        return $freeBytes >= max(self::minFreeBytes(), $requiredBytes);
    }
}
