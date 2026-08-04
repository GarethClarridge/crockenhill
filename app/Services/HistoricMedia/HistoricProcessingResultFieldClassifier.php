<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

final class HistoricProcessingResultFieldClassifier
{
    public static function isPathKey(string $key): bool
    {
        return str_contains(mb_strtolower($key), 'path');
    }

    public static function isIdentityKey(string $key): bool
    {
        return preg_match('/(^|_)(id|ids)$/i', $key) === 1;
    }
}
