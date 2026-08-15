<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ConvergeHistoricChurchService;

/**
 * IC2's re-scoped batch admission: every applicable service is applied, and
 * everything else is reported rather than aborting the round. `held` is
 * corpus-completeness residue (a service not yet ready to converge), never a
 * processing error — those still throw out of {@see ConvergeHistoricChurchService::executeBatch()}.
 *
 * @see docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §6 IC2
 */
readonly class HistoricConvergenceBatchResult
{
    /**
     * @param  list<array{church_service: ChurchService, processing_log: MediaProcessingLog, created_assets: list<string>}>  $applied
     * @param  list<array{identity: string, reason: string}>  $held
     */
    public function __construct(
        public array $applied,
        public array $held,
    ) {}
}
