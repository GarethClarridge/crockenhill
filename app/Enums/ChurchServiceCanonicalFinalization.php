<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceCanonicalFinalization: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
