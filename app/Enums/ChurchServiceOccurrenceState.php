<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceOccurrenceState: string
{
    case PlannedOnly = 'planned_only';
    case ObservedOnly = 'observed_only';
    case PlannedAndObserved = 'planned_and_observed';
    case ManuallyConfirmed = 'manually_confirmed';
}
