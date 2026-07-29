<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceSource: string
{
    case Email = 'email';
    case OpenLp = 'openlp';
    case Livestream = 'livestream';
    case Manual = 'manual';
}
