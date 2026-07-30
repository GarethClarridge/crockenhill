<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Support\CanonicalJson;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricProcessingResultBundleFiles
{
    /** @param array<string, mixed> $bundle */
    public function write(string $path, array $bundle): string
    {
        $resolved = $this->resolveWritablePath($path);
        $temporary = $resolved.'.'.Str::uuid().'.tmp';

        try {
            $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

            if (file_put_contents($temporary, $json, LOCK_EX) === false || ! chmod($temporary, 0600)) {
                throw new RuntimeException('Historic processing bundle could not be written securely.');
            }

            if (! rename($temporary, $resolved)) {
                throw new RuntimeException('Historic processing bundle could not be moved into place.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $resolved;
    }

    public function logicalFileHash(string $path): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Historic processing bundle could not be read.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return CanonicalJson::hash($decoded);
    }

    private function resolveWritablePath(string $path): string
    {
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $directory = realpath(dirname($candidate));

        if (! is_string($directory) || ! is_writable($directory) || is_link($candidate)) {
            throw new RuntimeException('Historic processing bundle output directory is invalid.');
        }

        $resolved = $directory.DIRECTORY_SEPARATOR.basename($candidate);
        $scratch = realpath(storage_path('scratch'));
        $private = realpath(storage_path('app/private'));
        $allowed = collect([$scratch, $private])
            ->filter(fn (mixed $root): bool => is_string($root))
            ->contains(fn (string $root): bool => $resolved === $root || str_starts_with($resolved, $root.DIRECTORY_SEPARATOR));

        if (! $allowed) {
            throw new RuntimeException('Historic processing bundles must stay under storage/scratch or storage/app/private.');
        }

        return $resolved;
    }
}
