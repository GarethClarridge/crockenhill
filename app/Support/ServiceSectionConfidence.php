<?php

declare(strict_types=1);

namespace App\Support;

final class ServiceSectionConfidence
{
    public const HIGH_THRESHOLD = 0.85;

    public const LOW_THRESHOLD = 0.50;

    /**
     * Resolve a section's runtime confidence from the canonical numeric column,
     * falling back to the historical `confidence_score` metadata only when the
     * column is null (legacy rows that predate the column promotion).
     *
     * `confidence_level` is intentionally not consulted here — it is derived
     * display metadata, not a runtime decision input.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function resolve(?float $confidence = null, ?array $metadata = null): float
    {
        if (is_numeric($confidence)) {
            return self::clamp((float) $confidence);
        }

        $score = is_array($metadata) ? ($metadata['confidence_score'] ?? null) : null;

        if (is_numeric($score)) {
            return self::clamp((float) $score);
        }

        return self::scoreForLevel(null);
    }

    public static function clamp(float $confidence): float
    {
        return round(max(0.0, min(1.0, $confidence)), 3);
    }

    public static function increase(float $confidence, float $delta): float
    {
        return self::clamp($confidence + $delta);
    }

    public static function decrease(float $confidence, float $delta): float
    {
        return self::clamp($confidence - $delta);
    }

    public static function levelFor(float $confidence): string
    {
        $confidence = self::clamp($confidence);

        return match (true) {
            $confidence >= self::HIGH_THRESHOLD => 'high',
            $confidence >= self::LOW_THRESHOLD => 'low',
            default => 'none',
        };
    }

    public static function scoreForLevel(?string $level): float
    {
        return match ($level) {
            'high' => 0.90,
            'low' => 0.50,
            'none' => 0.10,
            default => 0.10,
        };
    }
}
