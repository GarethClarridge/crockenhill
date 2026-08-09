<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportCheckpointState: string
{
    case Planned = 'planned';
    case Admitted = 'admitted';
    case Running = 'running';
    case ReconciliationRequired = 'reconciliation_required';
    case Complete = 'complete';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Planned => in_array($next, [self::Admitted, self::ReconciliationRequired], true),
            self::Admitted => in_array($next, [self::Running, self::ReconciliationRequired], true),
            self::Running => in_array($next, [self::Complete, self::ReconciliationRequired], true),
            self::ReconciliationRequired => $next === self::Running,
            self::Complete => false,
        };
    }
}
