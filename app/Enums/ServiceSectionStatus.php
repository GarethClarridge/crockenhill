<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ServiceSectionStatus: string
{
    use HasValues;

    case IDENTIFIED = 'identified';
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::IDENTIFIED => 'Identified',
            self::SKIPPED => 'Skipped',
        };
    }
}
