<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads and writes the offsets an operator types into a correction: `h:mm:ss`, `m:ss` or plain
 * seconds, each with optional tenths. Tenths are kept so a boundary shown and re-submitted
 * unchanged lands where it was, not a rounding away.
 */
final class ServiceTimestamp
{
    public static function format(float $seconds): string
    {
        $tenths = (int) round(max(0.0, $seconds) * 10);
        $whole = intdiv($tenths, 10);
        $fraction = $tenths % 10 === 0 ? '' : '.'.($tenths % 10);
        $hours = intdiv($whole, 3600);
        $minutes = intdiv($whole % 3600, 60);
        $secs = $whole % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d%s', $hours, $minutes, $secs, $fraction)
            : sprintf('%d:%02d%s', $minutes, $secs, $fraction);
    }

    public static function parse(string $value): ?float
    {
        $parts = explode(':', trim($value));
        $secondsPart = array_pop($parts);

        if (count($parts) > 2 || preg_match('/\A\d+(?:\.\d+)?\z/', $secondsPart) !== 1) {
            return null;
        }

        foreach ($parts as $part) {
            if (preg_match('/\A\d+\z/', $part) !== 1) {
                return null;
            }
        }

        $seconds = (float) $secondsPart;

        if ($parts !== [] && $seconds >= 60) {
            return null;
        }

        [$hours, $minutes] = match (count($parts)) {
            2 => [(int) $parts[0], (int) $parts[1]],
            1 => [0, (int) $parts[0]],
            default => [0, 0],
        };

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
