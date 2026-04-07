<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SermonVideoQualityStatus: string
{
    use HasValues;

    case Unassessed = 'unassessed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Unassessed => 'Unassessed',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NeedsReview => 'Needs review',
        };
    }
}
