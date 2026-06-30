<?php

declare(strict_types=1);

namespace App\Traits;

trait SanitizesLogData
{
    /**
     * Sanitize user-controlled strings before writing to logs.
     * Prevents log injection by removing or replacing control characters.
     */
    protected static function sanitizeForLog(string $value): string
    {
        $withoutControlChars = str_replace(["\r", "\n", "\t"], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $withoutControlChars));
    }

    /**
     * Recursively sanitize all string values in an array for logging.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected static function sanitizeArrayForLog(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeArrayForLog($value);
            } elseif (is_string($value)) {
                // Security: Avoid double-sanitization of already-sanitized stack traces
                // which would flatten them and reduce readability.
                if (str_contains(strtolower((string) $key), 'trace')) {
                    continue;
                }

                $data[$key] = self::sanitizeForLog($value);
            }
        }

        return $data;
    }

    /**
     * Sanitise a stack trace before writing to logs.
     * Strips server base paths and redacts credential-like patterns.
     */
    protected static function sanitizeStackTrace(string $trace): string
    {
        // Strip absolute server paths to avoid leaking server structure
        $trace = str_replace(base_path().'/', '', $trace);

        // Redact values that look like credentials (password=, secret=, key=, token=)
        $trace = preg_replace('/\b(password|secret|token|api_key|apikey|auth)[=:]\S+/i', '$1=[REDACTED]', $trace) ?? $trace;

        // Truncate to a safe length
        return mb_substr($trace, 0, 3000);
    }
}
