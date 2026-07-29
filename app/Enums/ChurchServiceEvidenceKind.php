<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceEvidenceKind: string
{
    case Planned = 'planned';
    case Observed = 'observed';
    case Manual = 'manual';
}
