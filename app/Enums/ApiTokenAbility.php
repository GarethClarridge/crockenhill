<?php

namespace App\Enums;

enum ApiTokenAbility: string
{
    case MEDIA_PROCESS = 'media:process';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $ability): string => $ability->value,
            self::cases()
        );
    }
}
