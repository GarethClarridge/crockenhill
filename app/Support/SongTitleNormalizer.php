<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class SongTitleNormalizer
{
    public static function normalize(?string $title): string
    {
        if (! is_string($title)) {
            return '';
        }

        return (string) Str::of(Str::ascii($title))
            ->lower()
            ->replaceMatches('/\s*#\d+\s*$/', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}
