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
