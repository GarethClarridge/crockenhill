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
            self::CHRIST => 'Christ',
            self::CHURCH => 'Church',
            self::COMMUNITY => 'Community',
            self::MEMBERS => 'Members',
            self::SERMONS => 'Sermons',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
