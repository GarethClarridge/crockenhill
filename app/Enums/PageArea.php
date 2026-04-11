<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PageArea: string
{
    use HasValues;

    case Christ = 'christ';
    case Church = 'church';
    case Community = 'community';
    case Members = 'members';
    case Sermons = 'sermons';

    public function label(): string
    {
        return match ($this) {
            self::Christ => 'Christ',
            self::Church => 'Church',
            self::Community => 'Community',
            self::Members => 'Members',
            self::Sermons => 'Sermons',
        };
    }
}
