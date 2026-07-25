<?php

declare(strict_types=1);

namespace App\Support;

final class MediaAssetPath
{
    /**
     * Whether a stored path carries the legacy `private/` prefix.
     *
     * Nothing writes such paths any more — the prefix, and the local disk it
     * selected, were removed with the children's-talk storage move. The
     * predicate survives only so the read-only audits can count legacy rows
     * rather than reporting them as unexplained losses.
     */
    public static function isPrivate(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, 'private/');
    }

    /**
     * The disk every stored media path lives on.
     *
     * This no longer depends on the path. Legacy `private/` rows resolve here
     * too: those files are gone, and reporting them missing on the disk their
     * replacements use is the correct outcome — it presents as needing
     * re-extraction rather than as a silent lookup against the wrong disk.
     */
    public static function disk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
    }
}
