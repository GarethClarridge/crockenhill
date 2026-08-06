<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchServiceSourceRecord;
use Illuminate\Support\Collection;
use RuntimeException;

class ChurchServiceSourceRevisionLineageInspector
{
    /**
     * The current revision of a lineage is the unique leaf of its supersession
     * chain. Authority comes from the explicit relationship, never from a
     * timestamp a source chose for itself.
     *
     * The lineage must be complete: an edge pointing at a revision that was not
     * loaded means the caller assembled the lineage wrongly.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $revisions  every revision of one source + source_key lineage
     */
    public function leaf(Collection $revisions): ?ChurchServiceSourceRecord
    {
        $revisionsById = $revisions->keyBy('id');

        foreach ($revisions as $revision) {
            if ($revision->supersedes_id !== null && ! $revisionsById->has($revision->supersedes_id)) {
                throw new RuntimeException('A source revision supersedes a record outside its source lineage.');
            }
        }

        return $this->activeLeaf($revisions, $revisions);
    }

    /**
     * Resolve a lineage's leaf inside a set that may already have been pruned to
     * active leaves, so that pruning twice is the same as pruning once. An edge
     * whose predecessor is absent from the whole loaded set is a pruned
     * ancestor; an edge whose predecessor is loaded under a different lineage is
     * still a corruption and is refused.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $revisions  the loaded revisions of one lineage
     * @param  Collection<int, ChurchServiceSourceRecord>  $loaded  every revision loaded alongside them
     */
    public function activeLeaf(Collection $revisions, Collection $loaded): ?ChurchServiceSourceRecord
    {
        if ($revisions->isEmpty()) {
            return null;
        }

        $revisionsById = $revisions->keyBy('id');
        $loadedById = $loaded->keyBy('id');
        $supersededIds = [];

        foreach ($revisions as $revision) {
            if ($revision->supersedes_id === null) {
                continue;
            }

            if ($revisionsById->has($revision->supersedes_id)) {
                $supersededIds[$revision->supersedes_id] = true;

                continue;
            }

            if ($loadedById->has($revision->supersedes_id)) {
                throw new RuntimeException('A source revision supersedes a record outside its source lineage.');
            }
        }

        $leaves = $revisions->reject(
            fn (ChurchServiceSourceRecord $record): bool => isset($supersededIds[$record->id]),
        );

        if ($leaves->count() !== 1) {
            throw new RuntimeException('A source revision lineage must have exactly one active leaf.');
        }

        return $leaves->first();
    }

    /**
     * A supersession edge is only meaningful inside one source + source_key
     * lineage. An edge that crosses lineages would let one source's correction
     * retire another source's evidence, so it is rejected before any leaf is
     * resolved — the leaf walk itself cannot see the difference between a
     * cross-lineage edge and a record it simply has not loaded.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     */
    public function assertNoCrossLineageSupersession(Collection $records): void
    {
        $recordsById = $records->keyBy('id');

        foreach ($records as $record) {
            if ($record->supersedes_id === null) {
                continue;
            }

            $predecessor = $recordsById->get($record->supersedes_id);

            if (! $predecessor instanceof ChurchServiceSourceRecord) {
                continue;
            }

            if ($this->lineageKey($predecessor) !== $this->lineageKey($record)) {
                throw new RuntimeException('A source revision supersedes a record from a different source lineage.');
            }
        }
    }

    /**
     * Repair-oriented descriptions of every lineage defect, sorted for stable
     * output. Each one names revision ids and, for a multi-leaf lineage, the
     * lineage key — which embeds `source_key`, an email message id or an archive
     * filename. That is what makes this identifying rather than aggregate, so
     * callers whose output leaves the server must use issueCounts() instead.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        return array_map(
            static fn (array $finding): string => $finding['message'],
            $this->findings(),
        );
    }

    /**
     * The same defects as counts per kind, carrying no ids, source keys or
     * lineage keys. Safe for a public log; this is what the production audit
     * workflow runs.
     *
     * @return array<string, int>
     */
    public function issueCounts(): array
    {
        $counts = [
            'missing_predecessor' => 0,
            'cross_lineage_supersession' => 0,
            'multiple_successors' => 0,
            'multiple_active_leaves' => 0,
        ];

        foreach ($this->findings() as $finding) {
            $counts[$finding['kind']]++;
        }

        return $counts;
    }

    /**
     * @return list<array{kind: string, message: string}>
     */
    private function findings(): array
    {
        $records = ChurchServiceSourceRecord::query()
            ->orderBy('id')
            ->get(['id', 'church_service_id', 'source', 'source_key', 'supersedes_id']);
        $recordsById = $records->keyBy('id');
        $lineageRecords = [];
        $successorsByPredecessor = [];
        $issues = [];

        foreach ($records as $record) {
            $lineageKey = $this->lineageKey($record);
            $lineageRecords[$lineageKey][$record->id] = $record;

            if ($record->supersedes_id === null) {
                continue;
            }

            $predecessor = $recordsById->get($record->supersedes_id);

            if (! $predecessor instanceof ChurchServiceSourceRecord) {
                $issues[] = [
                    'kind' => 'missing_predecessor',
                    'message' => "Revision {$record->id} supersedes missing revision {$record->supersedes_id}. "
                        ."Repair with: UPDATE church_service_source_records SET supersedes_id = <predecessor id> WHERE id = {$record->id};",
                ];

                continue;
            }

            if ($this->lineageKey($predecessor) !== $lineageKey) {
                $issues[] = [
                    'kind' => 'cross_lineage_supersession',
                    'message' => "Revision {$record->id} supersedes revision {$predecessor->id} from another source lineage.",
                ];

                continue;
            }

            $successorsByPredecessor[$predecessor->id][] = $record->id;
        }

        foreach ($successorsByPredecessor as $predecessorId => $successors) {
            if (count($successors) > 1) {
                $issues[] = [
                    'kind' => 'multiple_successors',
                    'message' => "Revision {$predecessorId} has multiple successors: ".implode(', ', $successors).'.',
                ];
            }
        }

        foreach ($lineageRecords as $lineageKey => $recordsByLineage) {
            $leafIds = array_keys(array_filter(
                $recordsByLineage,
                fn (ChurchServiceSourceRecord $record): bool => ! isset($successorsByPredecessor[$record->id]),
            ));

            if (count($leafIds) !== 1) {
                $issues[] = [
                    'kind' => 'multiple_active_leaves',
                    'message' => "Source revision lineage {$lineageKey} has ".count($leafIds).' active leaves ('
                        .implode(', ', $leafIds).'). Chain them by setting supersedes_id on each revision except the current one.',
                ];
            }
        }

        usort($issues, static fn (array $a, array $b): int => strcmp($a['message'], $b['message']));

        return $issues;
    }

    private function lineageKey(ChurchServiceSourceRecord $record): string
    {
        return "{$record->church_service_id}|{$record->source->value}|{$record->source_key}";
    }
}
