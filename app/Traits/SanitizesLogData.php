<?php

declare(strict_types=1);

namespace App\Traits;

trait SanitizesLogData
{
    /**
     * Sanitize user-controlled strings before writing to logs.
     * Prevents log injection by removing or replacing control characters.
     */
    protected function sanitizeForLog(string $value): string
    {
        $withoutControlChars = str_replace(["\r", "\n", "\t"], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $withoutControlChars));
    }
}
