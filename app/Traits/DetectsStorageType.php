<?php

namespace App\Traits;

use App\Services\StorageAdapterHelper;
use Illuminate\Contracts\Filesystem\Filesystem;

trait DetectsStorageType
{
    /**
     * Check if the given disk is S3-compatible (DigitalOcean Spaces, AWS S3, etc.)
     */
    protected function isS3Disk(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        return isset($diskConfig['driver']) && $diskConfig['driver'] === 's3';
    }

    /**
     * Check if a path is an S3 URL (http/https)
     */
    protected function isS3Path(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Check if a disk instance uses an S3-compatible adapter at runtime.
     * Delegates to StorageAdapterHelper.
     */
    protected function isS3CompatibleDisk(Filesystem $disk): bool
    {
        return app(StorageAdapterHelper::class)->isS3CompatibleDisk($disk);
    }
}
