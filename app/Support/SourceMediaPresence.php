<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Whether the original source recording behind a processing run is still on
 * disk, which is what decides re-derivability: a derived asset that has been
 * lost can be regenerated only while its source survives.
 *
 * `MediaProcessingLog::$source_file_path` is stored either relative to the temp
 * disk (`livestream/temp/…`, the normal upload path) or as an absolute path
 * (historic imports and some operator-supplied runs), so both shapes resolve
 * here. Results are memoised because several talks and sections routinely share
 * one processing run.
 */
class SourceMediaPresence
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function exists(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        return $this->cache[$path] = $this->resolve($path);
    }

    private function resolve(string $path): bool
    {
        try {
            if (self::isAbsolute($path)) {
                return is_file($path);
            }

            return Storage::disk($this->tempDisk())->exists($path);
        } catch (Throwable) {
            // An unreadable disk is not evidence the source survived.
            return false;
        }
    }

    private function tempDisk(): string
    {
        return (string) config('media-processing.storage.temp_disk', 'local');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }
}
