<?php

namespace App\Enums;

enum SermonService: string
{
    case MORNING = 'morning';
    case EVENING = 'evening';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            static::MORNING => 'Morning',
            static::EVENING => 'Evening',
            static::OTHER => 'Other',
        };
    }
}
