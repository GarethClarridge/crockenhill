<?php

namespace App\Enums;

enum PageArea: string
{
    case CHRIST = 'christ';
    case CHURCH = 'church';
    case COMMUNITY = 'community';
    case MEMBERS = 'members';
    case SERMONS = 'sermons';

    public function label(): string
    {
        return match ($this) {
            static::CHRIST => 'Christ',
            static::CHURCH => 'Church',
            static::COMMUNITY => 'Community',
            static::MEMBERS => 'Members',
            static::SERMONS => 'Sermons',
        };
    }

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
