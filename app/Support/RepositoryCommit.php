<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The commit the application is running from, read straight off `.git` rather than shelled out to.
 *
 * No `git` binary, no `Process`, no failure mode about which image the code happens to be running
 * in. It is provenance for a report, so it must not be able to take an evaluation down with it: an
 * unreadable repository yields `null` and the caller records that it could not be determined.
 *
 * A commit id is deliberately *not* the strongest provenance an evaluation has. It says nothing
 * about uncommitted edits, which is exactly the risk while a comparison is being set up, so callers
 * that need to prove two runs used the same code hash the file contents instead and keep this as the
 * human-readable anchor.
 */
class RepositoryCommit
{
    public static function current(?string $basePath = null): ?string
    {
        $git = ($basePath ?? base_path()).'/.git';

        if (! is_dir($git)) {
            return null;
        }

        $head = self::read("{$git}/HEAD");

        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref:')) {
            return self::sha($head);
        }

        $ref = trim(substr($head, 4));

        return self::sha(self::read("{$git}/{$ref}") ?? '') ?? self::packed($git, $ref);
    }

    private static function packed(string $git, string $ref): ?string
    {
        $packed = self::read("{$git}/packed-refs");

        if ($packed === null) {
            return null;
        }

        foreach (explode("\n", $packed) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) === 2 && $parts[1] === $ref) {
                return self::sha($parts[0]);
            }
        }

        return null;
    }

    private static function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : trim($contents);
    }

    private static function sha(string $candidate): ?string
    {
        return preg_match('/\A[0-9a-f]{40}\z/', $candidate) === 1 ? $candidate : null;
    }
}
