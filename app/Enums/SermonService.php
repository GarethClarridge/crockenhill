<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SermonService: string
{
    use HasValues;

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
}
