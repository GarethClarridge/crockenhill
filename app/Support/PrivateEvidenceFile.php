<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Create-once, private, durable evidence files.
 *
 * Every artifact in the historic import's custody chain shares one requirement:
 * it is written exactly once, below `storage/app/private`, and either lands
 * complete on the disk or does not exist at all. A partially written custody
 * document that a later reader treats as whole is the same class of failure
 * HIR5 found in recovery evidence.
 *
 * Extracted from the acquisition verifier's command when its producer needed
 * identical behaviour. Delete alongside the historic-import commands once their
 * artifacts have moved to long-term custody (G9/WP10).
 */
final class PrivateEvidenceFile
{
    /**
     * Resolve an operator-supplied path, refusing anything outside private
     * storage.
     *
     * A relative path is taken as relative to `storage/app/private`, so the
     * ordinary case cannot escape by construction and the absolute case is
     * checked against the resolved parent rather than the string.
     */
    public static function resolve(mixed $option, string $requirement): string
    {
        if (! is_string($option) || trim($option) === '') {
            throw new RuntimeException("{$requirement} requires an explicit private artifact path.");
        }

        $root = realpath(storage_path('app/private'));
        $path = str_starts_with($option, '/') ? $option : storage_path('app/private/'.trim($option));
        $parent = realpath(dirname($path));

        if (! is_string($root) || ! is_string($parent) || ! str_starts_with($parent.'/', $root.'/')) {
            throw new RuntimeException("{$requirement} must stay below storage/app/private.");
        }

        return $path;
    }

    /**
     * @throws RuntimeException when the path already exists or cannot be written whole
     */
    public static function writeOnce(string $path, string $contents, string $requirement): void
    {
        $handle = fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException("{$requirement} must be created once at a new private path.");
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException("{$requirement} could not be written durably.");
            }

            $remaining = $contents;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException("{$requirement} could not be written durably.");
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException("{$requirement} could not be written durably.");
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);
    }
}
