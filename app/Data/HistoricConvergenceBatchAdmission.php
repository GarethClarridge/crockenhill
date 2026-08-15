<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\ChurchService\ConvergeHistoricChurchService;

/**
 * IC2's per-service batch classification: every service plan sorted into the
 * ones ready to apply and everything else reported as held with a reason.
 * Internal to {@see ConvergeHistoricChurchService::partitionApplicable()}
 * — the public result of an apply is {@see HistoricConvergenceBatchResult}.
 */
readonly class HistoricConvergenceBatchAdmission
{
    /**
     * @param  list<array<string, mixed>>  $applicable  raw service-plan entries from
     *                                                  HistoricConvergenceOperationPlan::$services
     * @param  list<array{identity: string, reason: string}>  $held
     */
    public function __construct(
        public array $applicable,
        public array $held,
    ) {}
}
