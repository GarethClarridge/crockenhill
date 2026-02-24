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
            self::MORNING => 'Morning',
            self::EVENING => 'Evening',
            self::OTHER => 'Other',
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
