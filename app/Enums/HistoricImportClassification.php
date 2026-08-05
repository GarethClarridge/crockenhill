<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportClassification: string
{
    case AlreadyPresent = 'already_present';
    case Create = 'create';
    case SafeEnrichment = 'safe_enrichment';
    case BlockedDifference = 'blocked_difference';
    case Conflict = 'conflict';
}
