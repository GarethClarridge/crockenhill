<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class SermonPromotionBundleFiles
{
    private const int MAX_BUNDLE_BYTES = 52_428_800;

    public function read(string $path): string
    {
        $resolvedPath = $this->resolveExistingPrivatePath($path);
        $size = filesize($resolvedPath);

        if (! is_int($size) || $size > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Promotion bundle exceeds the 50 MB safety limit.');
        }

        $contents = file_get_contents($resolvedPath);

        if (! is_string($contents)) {
            throw new RuntimeException('Promotion bundle could not be read.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $bundle
     *
     * @throws JsonException
     */
    public function write(string $path, array $bundle): string
    {
        $resolvedPath = $this->resolveWritablePrivatePath($path);
        $temporaryPath = $resolvedPath.'.'.Str::uuid().'.tmp';
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        try {
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Promotion bundle could not be written.');
            }

            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Promotion bundle permissions could not be restricted.');
            }

            if (! rename($temporaryPath, $resolvedPath)) {
                throw new RuntimeException('Promotion bundle could not be moved into place.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return $resolvedPath;
    }

    private function resolveExistingPrivatePath(string $path): string
    {
        $candidate = $this->absolutePath($path);

        if (is_link($candidate)) {
            throw new RuntimeException('Promotion bundle paths must not be symbolic links.');
        }

        $resolvedPath = realpath($candidate);

        if (! is_string($resolvedPath) || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException('Promotion bundle does not exist or is not readable.');
        }

        $this->guardPrivateStoragePath($resolvedPath);

        return $resolvedPath;
    }

    private function resolveWritablePrivatePath(string $path): string
    {
        $candidate = $this->absolutePath($path);

        if (is_link($candidate)) {
            throw new RuntimeException('Promotion bundle paths must not be symbolic links.');
        }

        $directory = realpath(dirname($candidate));

        if (! is_string($directory) || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Promotion bundle output directory does not exist or is not writable.');
        }

        $resolvedPath = $directory.DIRECTORY_SEPARATOR.basename($candidate);
        $this->guardPrivateStoragePath($resolvedPath);

        return $resolvedPath;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('A promotion bundle path is required.');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);
    }

    private function guardPrivateStoragePath(string $path): void
    {
        $storageRoot = realpath(storage_path());

        if (! is_string($storageRoot)) {
            throw new RuntimeException('Application storage root could not be resolved.');
        }

        $storagePrefix = $storageRoot.DIRECTORY_SEPARATOR;
        $publicPrefix = storage_path('app/public').DIRECTORY_SEPARATOR;

        if (! str_starts_with($path, $storagePrefix)) {
            throw new RuntimeException('Promotion bundles must stay under the application storage directory.');
        }

        if ($path === storage_path('app/public') || str_starts_with($path, $publicPrefix)) {
            throw new RuntimeException('Promotion bundles must not be stored on the public disk.');
        }
    }
}
