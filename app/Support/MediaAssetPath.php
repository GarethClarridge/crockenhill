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

    /**
     * The sermon disk when it is a private holding disk, null when it is where media is published.
     *
     * An environment whose sermon disk is historic staging or quarantine (the follow-up round's
     * frozen clone) writes nothing a viewer may reach, so what it makes is held whichever lane made
     * it: quarantined, naming the disk its bytes are on. A weekly run there published straight
     * away, with no disk recorded, and its thumbnail candidates resolved to the public bucket
     * (893, 2026-10-15). Production's sermon disk publishes, so routine processing is unchanged.
     */
    public static function holdingDisk(): ?string
    {
        $disk = self::disk();
        $holding = [
            config('media-processing.storage.historic_staging_disk'),
            config('media-processing.storage.historic_quarantine_disk'),
        ];

        return in_array($disk, $holding, true) ? $disk : null;
    }
}
