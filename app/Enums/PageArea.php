<?php

namespace App\Enums;

enum PageArea: string
{
    case CHRIST = 'christ';
    case CHURCH = 'church';
    case COMMUNITY = 'community';

    public function label(): string
    {
        return match ($this) {
            static::CHRIST => 'Christ',
            static::CHURCH => 'Church',
            static::COMMUNITY => 'Community',
        };
    }
}
