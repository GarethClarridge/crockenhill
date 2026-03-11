<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceSectionSongMatchType: string
{
    case CONFIRMED = 'confirmed';
    case INFERRED = 'inferred';
    case UNMATCHED = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Confirmed song match',
            self::INFERRED => 'Inferred song label',
            self::UNMATCHED => 'Unmatched detected song',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CONFIRMED => 'This section is safe to count as a confirmed livestream match.',
            self::INFERRED => 'This label came from OoS ordering and still needs review before it is trusted.',
            self::UNMATCHED => 'No reliable OoS song match was found for this detected section.',
        };
    }
}
