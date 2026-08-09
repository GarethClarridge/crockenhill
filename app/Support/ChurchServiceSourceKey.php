<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Normalizer;
use RuntimeException;

class ChurchServiceSourceKey
{
    public static function canonical(string $value): string
    {
        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_KC);

        if (! is_string($normalized)) {
            throw new RuntimeException('Church service source key cannot be normalized.');
        }

        $canonical = mb_strtolower(Str::ascii($normalized));

        if ($canonical === '') {
            throw new RuntimeException('Church service source key cannot be empty.');
        }

        return $canonical;
    }

    public static function identity(string $value): string
    {
        return hash('sha256', self::canonical($value));
    }
}
