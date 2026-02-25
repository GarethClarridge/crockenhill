<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PageArea: string
{
    use HasValues;

    case CHRIST = 'christ';
    case CHURCH = 'church';
    case COMMUNITY = 'community';
    case MEMBERS = 'members';
    case SERMONS = 'sermons';

    public function label(): string
    {
        return match ($this) {
            self::CHRIST => 'Christ',
            self::CHURCH => 'Church',
            self::COMMUNITY => 'Community',
            self::MEMBERS => 'Members',
            self::SERMONS => 'Sermons',
        };
    }
}
