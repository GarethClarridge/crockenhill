<?php

declare(strict_types=1);

namespace App\Traits;

trait HandlesSafePaths
{
    /**
     * Determine if a given path is unsafe (traversal, absolute, or URI scheme).
     *
     * Security: Blocking directory traversal (..), absolute paths (/, \),
     * and URI schemes (://) provides Defense in Depth against unauthorized
     * file access, potential server-side request forgery (SSRF), and
     * remote file inclusion vulnerabilities.
     */
    protected function isUnsafePath(string $path): bool
    {
        return str_contains($path, '..')
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || str_contains($path, '://');
    }
}
