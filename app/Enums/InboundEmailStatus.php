<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum InboundEmailStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    /**
     * A synthetic order-of-service archive entry whose own markdown contradicts itself — a
     * weekday/date mismatch or two dates in one entry. Nobody can act on it until the archive
     * text is corrected, so it is deliberately kept out of the review inbox. Every other archive
     * entry is released to {@see self::Pending} and reviewed like any other inbound email.
     */
    case ArchiveEval = 'archive_eval';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processed => 'Processed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
            self::ArchiveEval => 'Archive evaluation',
        };
    }
}
