<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum CalendarEventStatus: string
{
    use HasValues;

    case CONFIRMED = 'confirmed';
    case PENDING = 'pending';
    case TENTATIVE = 'tentative';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Confirmed',
            self::PENDING => 'Pending',
            self::TENTATIVE => 'Tentative',
            self::CANCELLED => 'Cancelled',
        };
    }
}
