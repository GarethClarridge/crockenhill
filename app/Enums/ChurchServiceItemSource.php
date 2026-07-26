<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ChurchServiceItemSource: string
{
    use HasValues;

    case Email = 'email';
    case OpenLp = 'openlp';
    case Manual = 'manual';
    case Livestream = 'livestream';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::OpenLp => 'OpenLP',
            self::Manual => 'Entered manually',
            self::Livestream => 'Recording',
        };
    }

    public function isHumanProvided(): bool
    {
        return match ($this) {
            self::Email, self::Manual => true,
            self::OpenLp, self::Livestream => false,
        };
    }

    public function isDetected(): bool
    {
        return $this === self::Livestream;
    }
}
