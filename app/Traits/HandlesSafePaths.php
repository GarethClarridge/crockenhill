<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Trait HandlesSafePaths
 *
 * Provides centralized path validation to prevent path traversal, absolute path exposure,
 * and URI scheme injection in file-serving and storage-related components.
 */
trait HandlesSafePaths
{
    /**
     * Determine if a path is unsafe.
     *
     * Blocks:
     * 1. Relative traversal (..)
     * 2. Absolute paths (/ or \)
     * 3. URI schemes (://)
     */
    public function isUnsafePath(string $path): bool
    {
        return str_contains($path, '..')
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || str_contains($path, '://');
    }

    /**
     * Abort the request with a 404 if the path is unsafe.
     *
     * @param  string  $path  The path to validate
     * @param  string  $type  The type of asset for the error message
     */
    public function abortIfUnsafe(string $path, string $type = 'file'): void
    {
        if ($this->isUnsafePath($path)) {
            abort(404, "Invalid {$type} file path.");
        }
    }
}
