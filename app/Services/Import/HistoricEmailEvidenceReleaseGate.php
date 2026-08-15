<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ChurchServiceReviewState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Models\Sermon;
use App\Services\Email\InboundEmailImportService;
use Illuminate\Database\Eloquent\Collection;

/**
 * REV-D2's third tier: **unattended publication stays zero.**
 *
 * "Existence widens" — a parsed email plan whose identity is corroborated imports as source
 * evidence regardless of content confidence — but the same decision is explicit that "a service
 * carrying unreviewed, unfinalised email evidence is not release-eligible". Widening what enters
 * the service graph without also narrowing what may leave it would have made the evidence tier a
 * route to publication, which is the one thing REV-D2 rules out.
 *
 * The check is deliberately *not* built on a per-service `finalised` flag. A service accumulates
 * evidence from several emails, and one scalar records only whichever import wrote last; the
 * question here is whether *any* evidence the service carries is still unfinalised. So the gate
 * reads the per-source-key map `import_metadata.email_evidence` that
 * {@see InboundEmailImportService::planImportMetadata()} writes, one entry per
 * source revision.
 *
 * Only *active* evidence counts. A source revision another has superseded is explicit lineage
 * (invariant 4), and a correction that cleared the auto-import bar outright is exactly how an
 * unfinalised predecessor is meant to be resolved — holding the service back for evidence its
 * own successor replaced would make the correction chain unreleasable by design.
 *
 * A completed human review of the service finalises whatever it covers: an operator who has
 * looked at the service and cleared it has done the reviewing this gate exists to require. A
 * review that machine evidence has since *reopened* does not count — that is the state where new,
 * unreviewed evidence arrived after the human's decision.
 *
 * @see docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §2.2 REV-D2, §6 IC1
 */
final class HistoricEmailEvidenceReleaseGate
{
    /**
     * The services backing these sermons that still carry unreviewed, unfinalised email evidence,
     * as `"2015-03-08 morning"` labels an operator can act on.
     *
     * Identity is the (date, service) pair, which is what a sermon and a church service share —
     * there is no foreign key between them.
     *
     * @param  list<int>  $sermonIds
     * @return list<string>
     */
    public function ineligibleServiceLabels(array $sermonIds): array
    {
        if ($sermonIds === []) {
            return [];
        }

        $sermons = Sermon::query()
            ->whereIn('id', $sermonIds)
            ->get(['id', 'date', 'service']);

        if ($sermons->isEmpty()) {
            return [];
        }

        $services = $this->servicesFor($sermons);
        $activeSourceKeys = $this->activeEmailSourceKeys(array_values($services->modelKeys()));

        $labels = $services
            ->filter(fn (ChurchService $service): bool => $this->carriesUnfinalisedEvidence(
                $service,
                $activeSourceKeys[$service->id] ?? [],
            ))
            ->map(static fn (ChurchService $service): string => $service->date->toDateString().' '.$service->service->value)
            ->unique()
            ->sort()
            ->all();

        return array_values($labels);
    }

    /**
     * The email source keys each service still asserts, superseded revisions excluded.
     *
     * @param  list<int|string>  $serviceIds  as `Collection::modelKeys()` types them
     * @return array<int, list<string>>
     */
    private function activeEmailSourceKeys(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $keys = [];

        $records = ChurchServiceSourceRecord::query()
            ->whereIn('church_service_id', $serviceIds)
            ->where('source', ChurchServiceSource::Email)
            ->whereDoesntHave('supersededBy')
            ->get(['id', 'church_service_id', 'source_key']);

        foreach ($records as $record) {
            $keys[$record->church_service_id][] = $record->source_key;
        }

        return $keys;
    }

    /**
     * @param  Collection<int, Sermon>  $sermons
     * @return Collection<int, ChurchService>
     */
    private function servicesFor(Collection $sermons): Collection
    {
        $query = ChurchService::query()->select([
            'id', 'date', 'service', 'review_state', 'import_metadata',
        ]);

        $query->where(function ($outer) use ($sermons): void {
            foreach ($sermons as $sermon) {
                $service = $sermon->service;

                // A sermon with no service slot has no church service to carry evidence for it.
                if ($service === null) {
                    continue;
                }

                $outer->orWhere(function ($inner) use ($sermon, $service): void {
                    $inner->whereDate('date', $sermon->date->toDateString())
                        ->where('service', $service->value);
                });
            }
        });

        return $query->get();
    }

    /**
     * @param  list<string>  $activeSourceKeys
     */
    private function carriesUnfinalisedEvidence(ChurchService $service, array $activeSourceKeys): bool
    {
        if ($service->review_state === ChurchServiceReviewState::Reviewed) {
            return false;
        }

        $evidence = $service->import_metadata?->raw['email_evidence'] ?? null;

        if (! is_array($evidence)) {
            return false;
        }

        foreach ($activeSourceKeys as $sourceKey) {
            $entry = $evidence[$sourceKey] ?? null;

            if (is_array($entry) && ($entry['finalised'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
