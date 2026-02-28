<?php

namespace App\Enums;

enum ServiceSectionStatus: string
{
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
