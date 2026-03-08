<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ChurchServiceItemSource: string
{
    use HasValues;

    case EMAIL = 'email';
    case OPENLP = 'openlp';
    case MANUAL = 'manual';

    public function isHumanProvided(): bool
    {
        return match ($this) {
            self::EMAIL, self::MANUAL => true,
            self::OPENLP => false,
        };
    }
}
