<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Services\HistoricMedia\HistoricPromotionBudget;
use App\Services\HistoricMedia\HistoricPromotionMeasurements;
use DateTimeImmutable;

/**
 * Decides whether one more service may enter an accepted convergence window.
 *
 * The reserve is deliberately derived from observed ledger timings: the p95
 * apply duration plus the p95 failed-apply duration, which includes cleanup.
 * A configured reserve is only a bootstrap for the first service, before the
 * operation has produced observations of its own.
 */
final class HistoricConvergenceAdmission
{
    public function __construct(
        private readonly HistoricConvergenceLedger $ledger,
        private readonly HistoricPromotionMeasurements $measurements,
        private readonly HistoricPromotionBudget $budget,
    ) {}

    /** @return array{admitted: bool, reserve_seconds: float, remaining_seconds: float, reason: string|null} */
    public function decide(string $operationId, DateTimeImmutable $deadline, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $remainingSeconds = max(0.0, (float) ($deadline->format('U.u') - $now->format('U.u')));
        $reserveSeconds = $this->reserveSeconds($operationId);
        $admitted = $remainingSeconds >= $reserveSeconds;

        return [
            'admitted' => $admitted,
            'reserve_seconds' => $reserveSeconds,
            'remaining_seconds' => round($remainingSeconds, 6),
            'reason' => $admitted ? null : 'accepted_deadline_reserve_exhausted',
        ];
    }

    public function reserveSeconds(string $operationId): float
    {
        $samples = $this->measurements->fromLedgerEntries($this->ledger->entries($operationId));
        $apply = $this->budget->percentiles($samples['apply_seconds'])['p95'];
        $rollback = $this->budget->percentiles($samples['rollback_seconds'])['p95'];

        $apply ??= $this->configuredSeconds('apply_p95_seconds');
        $rollback ??= $this->configuredSeconds('rollback_p95_seconds');

        $admissionFloor = $this->configuredSeconds('admission_floor_seconds') ?? 0.0;

        return round(max(0.0, $admissionFloor + ($apply ?? 0.0) + ($rollback ?? 0.0)), 6);
    }

    private function configuredSeconds(string $key): ?float
    {
        $value = config("media-processing.historic_import.convergence.{$key}");

        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            ? (float) $value
            : null;
    }
}
