<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceCanonicalConflictState: string
{
    case None = 'none';
    case Detected = 'detected';
    case Reopened = 'reopened';
}
