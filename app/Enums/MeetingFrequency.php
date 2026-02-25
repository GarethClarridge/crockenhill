<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum MeetingFrequency: string
{
    use HasValues;

    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case ANNUALLY = 'annually';

    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::ANNUALLY => 'Annually',
        };
    }
}
