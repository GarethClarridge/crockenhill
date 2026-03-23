<?php

declare(strict_types=1);

namespace App\Support;

final class MediaAssetPath
{
    public static function isPrivate(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, 'private/');
    }

    public static function diskForPath(?string $path, ?string $publicDisk = null): string
    {
        if (self::isPrivate($path)) {
            return 'local';
        }

        return $publicDisk ?? (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
    }
}
