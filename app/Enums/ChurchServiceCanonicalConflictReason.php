<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceCanonicalConflictReason: string
{
    case UNSPECIFIED = 'unspecified';
    case CONFLICTS_ONLY = 'conflicts_only';
    case CANONICAL_CHANGED = 'canonical_changed';
    case CANONICAL_CHANGED_WITH_CONFLICTS = 'canonical_changed_with_conflicts';
}
