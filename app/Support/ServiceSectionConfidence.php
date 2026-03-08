<?php

declare(strict_types=1);

namespace App\Support;

final class ServiceSectionConfidence
{
    public const HIGH_THRESHOLD = 0.85;

    public const LOW_THRESHOLD = 0.50;

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function resolve(?float $confidence = null, ?array $metadata = null): float
    {
        if (is_numeric($confidence)) {
            return self::clamp((float) $confidence);
        }

        $metadata = is_array($metadata) ? $metadata : [];
        $score = $metadata['confidence_score'] ?? null;

        if (is_numeric($score)) {
            return self::clamp((float) $score);
        }

        $level = $metadata['confidence_level'] ?? null;

        return self::scoreForLevel(is_string($level) ? $level : null);
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
