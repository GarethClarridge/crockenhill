<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportOperationState: string
{
    case Planned = 'planned';
    case Running = 'running';
    case CloseoutRequired = 'closeout_required';
    case Complete = 'complete';
    case ReconciliationRequired = 'reconciliation_required';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Planned => in_array($next, [self::Running, self::ReconciliationRequired], true),
            self::Running => in_array($next, [self::CloseoutRequired, self::ReconciliationRequired], true),
            self::CloseoutRequired => in_array($next, [self::Complete, self::ReconciliationRequired], true),
            self::ReconciliationRequired => $next === self::Running,
            self::Complete => false,
        };
    }
}
