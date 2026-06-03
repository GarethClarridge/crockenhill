<?php

declare(strict_types=1);

namespace App\Support;

final class Path
{
    /**
     * Determine if a given path is unsafe (traversal, absolute, or URI scheme).
     *
     * Security: Blocking directory traversal (..), absolute paths (/, \),
     * and URI schemes (://) provides Defense in Depth against unauthorized
     * file access, potential server-side request forgery (SSRF), and
     * remote file inclusion vulnerabilities.
     */
    public static function isUnsafe(string $path): bool
    {
        return str_contains($path, '..')
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || str_contains($path, '://');
    }

    /**
     * Determine if a path is already a resolvable URL that should be returned
     * as-is rather than passed through the storage URL builder.
     *
     * Matches external URLs (`http...`), protocol-relative URLs (`//host/...`),
     * and root-absolute paths (`/path`).
     */
    public static function isAlreadyResolvableUrl(string $path): bool
    {
        return str_starts_with($path, 'http')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/');
    }
}
