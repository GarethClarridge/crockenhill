<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ServiceSection;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Retires merge proposals a livestream projection raised against its own items.
 *
 * Until the projection was reordered it synchronised canonical items before
 * ingesting the source revision that explains them, so the ingest read its own
 * brand-new items as legacy content and staged an `unnormalized_legacy_items`
 * proposal. Every one of the 24 proposals the historic-video pilot produced was
 * this and nothing else.
 *
 * Reprojecting cannot clear them: the revision is already on record, so a second
 * ingest is an idempotent no-op that never reaches the code retiring stale
 * proposals. They are settled here instead, from the sections and source
 * revisions already stored and with no model spend.
 *
 * A proposal is only retired when every unevidenced item on its service is one
 * the triggering run's own sections link to. One genuine legacy item and the
 * proposal stands, because that is the case the guard exists for.
 *
 * Delete once the pilot and canary proposals are settled.
 */
class ReconcileSelfProjectedMergeProposalsCommand extends Command
{
    protected $signature = 'service:reconcile-self-projected-proposals
                            {--apply : Retire the proposals this run proves self-inflicted}';

    protected $description = 'Retire unnormalized_legacy_items proposals a livestream projection raised against its own items';

    public function handle(): int
    {
        $proposals = ChurchServiceMergeProposal::query()
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->with(['churchService', 'triggerSourceRecord'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ChurchServiceMergeProposal $proposal): bool => $this->isLegacyItemsOnly($proposal));

        if ($proposals->isEmpty()) {
            $this->info('No pending unnormalized_legacy_items proposals to reconcile.');

            return self::SUCCESS;
        }

        $rows = [];
        $retirable = [];

        foreach ($proposals as $proposal) {
            $unexplained = $this->unexplainedItems($proposal);
            $selfInflicted = $unexplained->isEmpty();

            if ($selfInflicted) {
                $retirable[] = $proposal;
            }

            $rows[] = [
                $proposal->id,
                $proposal->church_service_id,
                $this->identity($proposal),
                $selfInflicted ? 'self-inflicted' : 'genuine legacy',
                $selfInflicted ? '' : $unexplained->pluck('title')->implode('; '),
            ];
        }

        $this->table(['Proposal', 'Service', 'Identity', 'Verdict', 'Unexplained items'], $rows);

        if (! $this->option('apply')) {
            $this->info(sprintf(
                '%d of %d proposals are self-inflicted. Re-run with --apply to retire them.',
                count($retirable),
                $proposals->count(),
            ));

            return self::SUCCESS;
        }

        foreach ($retirable as $proposal) {
            $proposal->forceFill(['status' => ChurchServiceProposalStatus::Stale->value])->save();
        }

        $this->info(sprintf('Retired %d self-inflicted proposals.', count($retirable)));

        return self::SUCCESS;
    }

    private function identity(ChurchServiceMergeProposal $proposal): string
    {
        $churchService = $proposal->churchService;

        if ($churchService === null) {
            return '';
        }

        return $churchService->date->toDateString().' '.$churchService->service->value;
    }

    /**
     * Whether the proposal's only complaint is the one this command settles.
     *
     * A proposal carrying any other conflict is a real disagreement and is left
     * alone whatever else is true of its items.
     */
    private function isLegacyItemsOnly(ChurchServiceMergeProposal $proposal): bool
    {
        $conflicts = $proposal->conflicts;

        if ($conflicts === []) {
            return false;
        }

        foreach ($conflicts as $conflict) {
            if (($conflict['kind'] ?? null) !== 'unnormalized_legacy_items') {
                return false;
            }
        }

        return true;
    }

    /**
     * Unevidenced items on the service that the triggering run did not write.
     *
     * @return Collection<int, ChurchServiceItem>
     */
    private function unexplainedItems(ChurchServiceMergeProposal $proposal): Collection
    {
        $items = $proposal->churchService?->items()->get(['id', 'title', 'metadata'])
            ?? collect();
        $unevidenced = $items->reject(
            fn (ChurchServiceItem $item): bool => $item->hasNormalizedEvidence(),
        );
        $record = $proposal->triggerSourceRecord;

        if ($unevidenced->isEmpty() || $record?->source !== ChurchServiceSource::Livestream) {
            return $unevidenced->values();
        }

        $processingId = explode('|', (string) $record->source_key)[0];
        $projected = ServiceSection::query()
            ->whereHas(
                'processingLog',
                fn ($query) => $query->where('processing_id', $processingId),
            )
            ->whereIn('church_service_item_id', $unevidenced->pluck('id'))
            ->pluck('church_service_item_id')
            ->all();

        return $unevidenced
            ->reject(fn (ChurchServiceItem $item): bool => in_array($item->id, $projected, true))
            ->values();
    }
}
