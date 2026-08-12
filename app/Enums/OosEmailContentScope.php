<?php

declare(strict_types=1);

namespace App\Enums;

enum OosEmailContentScope: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Unknown = 'unknown';

    public function payloadComplete(): bool
    {
        return $this === self::Full;
    }
}
