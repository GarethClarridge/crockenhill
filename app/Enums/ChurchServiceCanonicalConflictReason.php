<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceCanonicalConflictReason: string
{
    case Unspecified = 'unspecified';
    case ConflictsOnly = 'conflicts_only';
    case CanonicalChanged = 'canonical_changed';
    case CanonicalChangedWithConflicts = 'canonical_changed_with_conflicts';
}
