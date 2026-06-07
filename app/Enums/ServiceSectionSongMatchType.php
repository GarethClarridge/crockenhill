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
            self::Confirmed => 'This section is safe to count as a confirmed livestream match.',
            self::Inferred => 'This label came from OoS ordering and still needs review before it is trusted.',
            self::Unmatched => 'No reliable OoS song match was found for this detected section.',
        };
    }
}
