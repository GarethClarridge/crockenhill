<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ServiceSectionSongMatchType: string
{
    use HasValues;

    case Confirmed = 'confirmed';
    case Inferred = 'inferred';
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed song match',
            self::Inferred => 'Inferred song label',
            self::Unmatched => 'Unmatched detected song',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Confirmed => 'The transcript-verified match met the confidence required to use the catalogue title and count this livestream usage.',
            self::Inferred => 'The transcript suggested this catalogue match below the confidence required to trust it without review.',
            self::Unmatched => 'No reliable OoS song match was found for this detected section.',
        };
    }
}
