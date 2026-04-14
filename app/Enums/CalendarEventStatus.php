<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum CalendarEventStatus: string
{
    use HasValues;

    case Confirmed = 'confirmed';
    case Pending = 'pending';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::Pending => 'Pending',
            self::Tentative => 'Tentative',
            self::Cancelled => 'Cancelled',
        };
    }
}
