<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceSectionPublicationStatus: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PUBLISHED = 'published';

    public function label(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'Not Applicable',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::PUBLISHED => 'Published',
        };
    }
}
