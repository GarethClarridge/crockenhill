<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;

/**
 * Refuses to stage a historic corpus over canonical items no source evidence explains.
 *
 * F2 of the 2026-08-07 readiness audit. `IngestChurchServiceSourceRevision` stages an
 * `unnormalized_legacy_items` proposal rather than projecting when a service already
 * holds items with no `source_assertion_hashes`, because projecting would delete items
 * no source can account for. That rule is right, and it is not what this guard is
 * arguing with.
 *
 * The problem is what happens when the rule meets a database that already holds an
 * evidence-free import of the same corpus. Measured on 2026-08-07, 219 of the approved
 * OoS manifest's 391 identities existed locally from the 2026-07-20 OpenLP archive run
 * and every one of them held items — so 56% of the corpus would have raised a proposal,
 * and the §9.4 census's largest class would have described that import rather than the
 * projector. §9.4.1 spends its first and most expensive iteration on the largest class,
 * so the cost of getting this wrong is paid immediately.
 *
 * §13.5 step 3 therefore says "clean rehearsal database". This class is that sentence
 * made answerable, on the principle `HistoricImportProductionGuard` already applies:
 * an unenforced precondition has to be interpreted, an enforced one simply answers.
 *
 * Deliberately scoped to the identities being staged. An unrelated legacy service is
 * §12.4's population, not this run's, and refusing on it would make the guard
 * unsatisfiable against any real database.
 */
class UnevidencedCanonicalItemGuard
{
    /**
     * The operator-facing reason this staging run must not proceed, or null when it may.
     *
     * Returns a message rather than throwing, so each command reports it the way it
     * already reports every other refusal.
     *
     * @param  string  $operation  The invocation being guarded, as an operator would type it.
     * @param  list<array{0:string,1:string}>  $identities  Date/service pairs about to be staged.
     */
    public function refusalFor(string $operation, array $identities): ?string
    {
        if ($identities === []) {
            return null;
        }

        $affected = $this->affectedIdentities($identities);

        if ($affected === 0) {
            return null;
        }

        return sprintf(
            '%s would stage evidence over canonical items that no source explains: %d of %d curated '
            ."identities already hold items with no normalized evidence.\n"
            ."Those services will each raise an `unnormalized_legacy_items` proposal instead of projecting, so the\n"
            ."proposal census would measure the previous import rather than the projector (readiness plan §13.5, F2).\n"
            .'Stage into a clean rehearsal database, or pass --accept-unevidenced-items if that is genuinely what you want.',
            $operation,
            $affected,
            count($identities),
        );
    }

    /**
     * Curated identities that already hold at least one item without normalized evidence.
     *
     * Counted per identity rather than per item, because one service raises one proposal
     * however many legacy items it holds — the identity is the unit of review cost.
     *
     * @param  list<array{0:string,1:string}>  $identities
     */
    private function affectedIdentities(array $identities): int
    {
        $services = ChurchService::query()
            ->select(['id', 'date', 'service'])
            ->whereIn('date', array_values(array_unique(array_column($identities, 0))))
            ->get();

        if ($services->isEmpty()) {
            return 0;
        }

        $curated = [];

        foreach ($identities as [$date, $service]) {
            $curated["{$date}|{$service}"] = true;
        }

        $candidateIds = $services
            ->filter(static fn (ChurchService $service): bool => isset(
                $curated[$service->date->format('Y-m-d').'|'.$service->service->value]
            ))
            ->pluck('id')
            ->all();

        if ($candidateIds === []) {
            return 0;
        }

        return ChurchServiceItem::query()
            ->whereIn('church_service_id', $candidateIds)
            ->get(['church_service_id', 'metadata'])
            ->reject(static fn (ChurchServiceItem $item): bool => $item->hasNormalizedEvidence())
            ->unique('church_service_id')
            ->count();
    }
}
