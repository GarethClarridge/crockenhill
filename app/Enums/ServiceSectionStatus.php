<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ServiceSectionStatus: string
{
    use HasValues;

    case Identified = 'identified';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Identified => 'Identified',
            self::Skipped => 'Skipped',
        };
    }
}
