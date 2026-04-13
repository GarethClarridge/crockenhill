<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SermonService: string
{
    use HasValues;

    case Morning = 'morning';
    case Evening = 'evening';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morning',
            self::Evening => 'Evening',
            self::Other => 'Other',
        };
    }
}
