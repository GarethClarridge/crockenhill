<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceProjection;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceSource;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use Illuminate\Support\Facades\DB;

class ChurchServiceProjectionPersister
{
    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
    ) {}

    public function apply(
        ChurchService $churchService,
        ChurchServiceProjection $projection,
        ?string $incomingSource = null,
        bool $dispatchEvents = true,
    ): ChurchService {
        return DB::transaction(function () use ($churchService, $projection, $incomingSource, $dispatchEvents): ChurchService {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $finalization = $projection->sourceSummary === ChurchServiceSource::Manual->value
                ? ChurchServiceCanonicalFinalization::Manual
                : ChurchServiceCanonicalFinalization::Automatic;
            $policyVersion = $projection->policyFingerprint['version'];

            $state = [
                'canonical_finalization' => $finalization,
                'projection_policy_version' => (int) $policyVersion,
            ];

            if ($lockedService->canonical_hash === $projection->hash) {
                if (
                    $lockedService->canonical_finalization !== $finalization
                    || $lockedService->projection_policy_version !== (int) $policyVersion
                ) {
                    $lockedService->forceFill($state)->saveQuietly();
                }

                return $lockedService;
            }

            $beforeSnapshot = $this->canonicalStateService->snapshot($lockedService);
            $items = $lockedService->items()->get();
            $existingItems = $items->keyBy('canonical_identity');
            $unassignedItems = $items->keyBy('id');
            $retainedIds = [];

            foreach ($projection->items as $item) {
                $identity = (string) $item['canonical_identity'];
                // Only an unclaimed row is a candidate. A row already rebuilt for an
                // earlier projected item must not be claimed again, or the second
                // write silently overwrites the first and the service loses an item.
                $existing = $unassignedItems->get($existingItems->get($identity)?->getKey());
                $existing ??= $unassignedItems->first(function ($candidate) use ($item): bool {
                    // A shared catalogue song is identity on its own; every weaker
                    // signal is a title, and titles only mean the same item when the
                    // items are the same kind of thing.
                    if ($item['song_id'] !== null && $candidate->song_id === $item['song_id']) {
                        return true;
                    }

                    if ($candidate->type !== $item['type']) {
                        return false;
                    }

                    if (
                        is_string($item['openlp_search_title'])
                        && $item['openlp_search_title'] !== ''
                        && $candidate->openlp_search_title === $item['openlp_search_title']
                    ) {
                        return true;
                    }

                    $candidateTitles = array_filter([$candidate->title, $candidate->source_title]);
                    $projectedTitles = array_filter([$item['title'], $item['source_title']]);

                    return array_intersect($candidateTitles, $projectedTitles) !== [];
                });
                $values = [
                    ...$item,
                    'position' => 100000 + (int) $item['position'],
                ];

                if ($existing === null) {
                    $existing = $lockedService->items()->create($values);
                } else {
                    $existing->forceFill($values)->saveQuietly();
                }

                $retainedIds[] = $existing->getKey();
                $unassignedItems->forget($existing->getKey());
            }

            $lockedService->items()
                ->whereNotIn('id', $retainedIds)
                ->delete();

            foreach ($projection->items as $item) {
                $lockedService->items()
                    ->where('canonical_identity', $item['canonical_identity'])
                    ->update(['position' => $item['position']]);
            }

            $lockedService->forceFill([
                'summary' => $projection->serviceContent['summary'],
                'notices' => $projection->serviceContent['notices'],
                'chapter_markers' => $projection->serviceContent['chapter_markers'],
                'source_summary' => $projection->sourceSummary,
                'source' => $projection->sourceSummary,
                'canonical_revision' => $lockedService->canonical_revision + 1,
                'canonical_hash' => $projection->hash,
                ...$state,
            ])->saveQuietly();

            $freshService = $lockedService->fresh(['items']) ?? $lockedService;
            $changes = $this->canonicalListChanges($this->canonicalStateService->diff(
                $beforeSnapshot,
                $this->canonicalStateService->snapshot($freshService),
            ));

            if ($changes !== [] && $dispatchEvents) {
                event(new ChurchServiceCanonicalListChanged(
                    $freshService->id,
                    $incomingSource ?? $projection->sourceSummary,
                    $changes,
                ));
            }

            return $freshService;
        });
    }

    /**
     * Provenance and evidence metadata can change without changing the public
     * canonical list. Consumers of this event reconcile list content, so avoid
     * scheduling them for source-only/manual-audit changes.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function canonicalListChanges(array $changes): array
    {
        $listChanges = [];

        foreach ($changes as $change) {
            if (($change['type'] ?? null) !== 'updated_item') {
                $listChanges[] = $change;

                continue;
            }

            $fields = array_values(array_filter(
                $change['fields'] ?? [],
                static fn (mixed $field): bool => is_array($field)
                    && ! in_array($field['field'] ?? null, ['source', 'metadata'], true),
            ));

            if ($fields === []) {
                continue;
            }

            $listChanges[] = [...$change, 'fields' => $fields];
        }

        return $listChanges;
    }
}
