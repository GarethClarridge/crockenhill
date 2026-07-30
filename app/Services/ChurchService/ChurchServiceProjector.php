<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceProjection;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChurchServiceProjector
{
    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function project(Collection $sourceRecords): ChurchServiceProjection
    {
        $activeRecords = $this->activeRecords($sourceRecords);
        $manualRecord = $activeRecords
            ->where('source', ChurchServiceSource::Manual)
            ->sortByDesc(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
            ->first();

        $projectionRecords = $manualRecord instanceof ChurchServiceSourceRecord
            ? collect([$manualRecord])
            : $activeRecords->where('source', '!=', ChurchServiceSource::Manual)->values();

        $groups = $this->matchedAssertionGroups($projectionRecords);
        $items = $groups
            ->map(fn (Collection $assertions, string $identity): array => $this->projectItem($assertions, $identity))
            ->sort($this->compareItems(...))
            ->values()
            ->map(function (array $item, int $index): array {
                $item['position'] = $index + 1;
                unset($item['_order']);

                return $item;
            })
            ->all();
        $items = array_values($items);

        $serviceContent = $this->projectServiceContent($activeRecords, $manualRecord);
        $sourceSummary = $manualRecord instanceof ChurchServiceSourceRecord
            ? ChurchServiceSource::Manual->value
            : $this->machineSourceSummary($activeRecords);
        $hash = CanonicalJson::hash([
            'items' => $this->portableHashItems($items),
            'service_content' => $serviceContent,
        ]);

        return new ChurchServiceProjection(
            items: $items,
            serviceContent: $serviceContent,
            sourceSummary: $sourceSummary,
            hash: $hash,
            fieldDecisions: $this->fieldDecisions($groups),
            conflicts: $this->conflicts($groups),
        );
    }

    /**
     * Database identifiers remain on projected items for relationship updates,
     * but canonical identity must survive export into another database.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function portableHashItems(array $items): array
    {
        return array_map(function (array $item): array {
            unset($item['song_id'], $item['livestream_service_section_id']);

            if (is_array($item['metadata'] ?? null)) {
                unset(
                    $item['metadata']['song_id'],
                    $item['metadata']['oos_item_id'],
                    $item['metadata']['livestream_service_section_id'],
                );
            }

            return $item;
        }, $items);
    }

    /**
     * Keep only the newest revision for each stable source key. Revision identity,
     * rather than arrival order, determines the active set.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function activeRecords(Collection $records): Collection
    {
        return $records
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
            ->groupBy(fn (ChurchServiceSourceRecord $record): string => "{$record->source->value}\0{$record->source_key}")
            ->map(fn (Collection $revisions): ChurchServiceSourceRecord => $revisions->reverse()->firstOrFail())
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => "{$record->source->value}\0{$record->source_key}")
            ->values();
    }

    private function recordOrder(ChurchServiceSourceRecord $record): string
    {
        return ($record->captured_at?->format('Y-m-d\TH:i:s.u') ?? '')."\0{$record->revision_hash}";
    }

    /**
     * Cardinality is evidence, not noise: a source asserting the same item twice is
     * asserting two occurrences. Each source revision numbers its own repeats, and the
     * n-th occurrence of an identity in one source pairs with the n-th in every other.
     * That keeps repeats distinct, pairs them monotonically, and leaves an unmatched
     * extra occurrence — an over-sung song — visible as its own observed-only item.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return Collection<string, Collection<int, ChurchServiceItemAssertion>>
     */
    private function matchedAssertionGroups(Collection $records): Collection
    {
        /** @var array<string, list<ChurchServiceItemAssertion>> $groups */
        $groups = [];

        foreach ($records as $record) {
            $occurrences = [];

            foreach ($this->orderedAssertions($record) as $assertion) {
                $identity = $this->baseIdentity($assertion);
                $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
                $groups[$identity.'#'.$occurrences[$identity]][] = $assertion;
            }
        }

        ksort($groups, SORT_STRING);

        return collect($groups)->map(
            fn (array $assertions): Collection => collect($assertions),
        );
    }

    /**
     * @return Collection<int, ChurchServiceItemAssertion>
     */
    private function orderedAssertions(ChurchServiceSourceRecord $record): Collection
    {
        return $record->assertions
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
            ->values();
    }

    private function baseIdentity(ChurchServiceItemAssertion $assertion): string
    {
        if (filled($assertion->song_canonical_key)) {
            return 'song:'.mb_strtolower((string) $assertion->song_canonical_key);
        }

        if ($assertion->song_id !== null) {
            return "song-id:{$assertion->song_id}";
        }

        if (filled($assertion->normalized_scripture_key)) {
            return 'scripture:'.mb_strtolower((string) $assertion->normalized_scripture_key);
        }

        if (filled($assertion->scripture_reference)) {
            return 'scripture:'.mb_strtolower((string) $assertion->scripture_reference);
        }

        return mb_strtolower("{$assertion->type}:{$assertion->normalized_title}");
    }

    private function assertionOrder(ChurchServiceItemAssertion $assertion): string
    {
        $record = $assertion->sourceRecord;

        return "{$record->source->value}\0{$record->source_key}\0".
            str_pad((string) $assertion->source_position, 10, '0', STR_PAD_LEFT).
            "\0{$assertion->assertion_key}";
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     * @return array<string, mixed>
     */
    private function projectItem(Collection $assertions, string $canonicalIdentity): array
    {
        $selected = $this->selectedAssertion($assertions);
        $planned = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Planned,
        );
        $observed = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed,
        );
        $manual = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Manual,
        );
        $observedAssertion = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
            ->first();
        $selectedMetadata = $selected->metadata ?? [];
        $observedMetadata = $observedAssertion instanceof ChurchServiceItemAssertion
            ? ($observedAssertion->metadata ?? [])
            : [];

        return [
            'canonical_identity' => $canonicalIdentity,
            'type' => $selected->type,
            'section_type' => $selected->section_type?->value,
            'source' => $selected->sourceRecord->source->value,
            'title' => $selected->title,
            'source_title' => $selected->source_title,
            'openlp_search_title' => $this->openLpSearchTitle($assertions),
            'song_id' => $this->authoritativeSongId($assertions),
            'occurrence_state' => match (true) {
                $manual => ChurchServiceOccurrenceState::ManuallyConfirmed->value,
                $planned && $observed => ChurchServiceOccurrenceState::PlannedAndObserved->value,
                $observed => ChurchServiceOccurrenceState::ObservedOnly->value,
                default => ChurchServiceOccurrenceState::PlannedOnly->value,
            },
            'manual_occurrence_decision' => $manual ? true : null,
            'livestream_processing_id' => $observedMetadata['livestream_processing_id'] ?? null,
            'livestream_service_section_id' => $observedMetadata['livestream_service_section_id'] ?? null,
            'metadata' => [
                ...$selectedMetadata,
                ...$observedMetadata,
                'source_assertion_hashes' => $assertions
                    ->map(fn (ChurchServiceItemAssertion $assertion): string => $assertion->sourceRecord->revision_hash.':'.$assertion->assertion_key)
                    ->sort()
                    ->values()
                    ->all(),
            ],
            '_order' => $this->itemOrder($assertions, $canonicalIdentity),
        ];
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     */
    private function selectedAssertion(Collection $assertions): ChurchServiceItemAssertion
    {
        return $assertions
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldAuthority($assertion))
            ->firstOrFail();
    }

    private function fieldAuthority(ChurchServiceItemAssertion $assertion): string
    {
        $isSong = $assertion->song_id !== null
            || filled($assertion->song_canonical_key)
            || mb_strtolower($assertion->type) === 'songs';
        $rank = match ($assertion->sourceRecord->source) {
            ChurchServiceSource::Manual => 0,
            ChurchServiceSource::OpenLp => $isSong ? 1 : 2,
            ChurchServiceSource::Email => $isSong ? 2 : 1,
            ChurchServiceSource::Livestream => 3,
        };

        return "{$rank}\0".$this->assertionOrder($assertion);
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     */
    private function authoritativeSongId(Collection $assertions): ?int
    {
        return $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->song_id !== null)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldAuthority($assertion))
            ->first()?->song_id;
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     */
    private function openLpSearchTitle(Collection $assertions): ?string
    {
        $openLp = $assertions->first(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === ChurchServiceSource::OpenLp,
        );

        if (! $openLp instanceof ChurchServiceItemAssertion) {
            return null;
        }

        return $openLp->metadata['openlp_search_title']
            ?? $openLp->source_title
            ?? $openLp->title;
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     * @return array{manual: int, observed: float, openlp: int, email: int, livestream: int, identity: string}
     */
    private function itemOrder(Collection $assertions, string $canonicalIdentity): array
    {
        $position = function (ChurchServiceSource $source) use ($assertions): int {
            $positions = $assertions
                ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === $source)
                ->pluck('source_position');

            return $positions->isEmpty() ? PHP_INT_MAX : (int) $positions->min();
        };
        $observedStarts = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->pluck('start_seconds')
            ->filter(fn (mixed $value): bool => $value !== null);

        return [
            'manual' => $position(ChurchServiceSource::Manual),
            'observed' => $observedStarts->isEmpty() ? INF : (float) $observedStarts->min(),
            'openlp' => $position(ChurchServiceSource::OpenLp),
            'email' => $position(ChurchServiceSource::Email),
            'livestream' => $position(ChurchServiceSource::Livestream),
            'identity' => $canonicalIdentity,
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareItems(array $left, array $right): int
    {
        foreach (['manual', 'observed', 'openlp', 'email', 'livestream', 'identity'] as $field) {
            $comparison = $left['_order'][$field] <=> $right['_order'][$field];

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * Record, for every contributing assertion, how it reached the canonical list and
     * which canonical fields it won. The review screen shows this verbatim, so an
     * unexplained match must read as unexplained rather than as a confident one.
     *
     * @param  Collection<string, Collection<int, ChurchServiceItemAssertion>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function fieldDecisions(Collection $groups): array
    {
        $decisions = [];

        foreach ($groups as $identity => $assertions) {
            $selected = $this->selectedAssertion($assertions);
            $songWinner = $assertions
                ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->song_id !== null)
                ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldAuthority($assertion))
                ->first();
            $openLpWinner = $assertions->first(
                fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === ChurchServiceSource::OpenLp,
            );
            $method = $assertions->count() === 1
                ? 'sole_source'
                : $this->matchMethod($selected);

            foreach ($assertions as $assertion) {
                $fields = [];

                if ($assertion->is($selected)) {
                    $fields = ['type', 'section_type', 'title', 'source_title'];
                }

                if ($songWinner instanceof ChurchServiceItemAssertion && $assertion->is($songWinner)) {
                    $fields[] = 'song_id';
                }

                if ($openLpWinner instanceof ChurchServiceItemAssertion && $assertion->is($openLpWinner)) {
                    $fields[] = 'openlp_search_title';
                }

                if ($assertion->evidence_kind === ChurchServiceEvidenceKind::Observed) {
                    $fields[] = 'occurrence';
                }

                $record = $assertion->sourceRecord;
                $decisions["{$record->revision_hash}:{$assertion->assertion_key}"] = [
                    'assertion_key' => $assertion->assertion_key,
                    'source' => $record->source->value,
                    'source_key' => $record->source_key,
                    'canonical_identity' => $identity,
                    'match_method' => $method,
                    'selected_fields' => array_values(array_unique($fields)),
                    'explanation' => $this->explain($assertions, $assertion, $method, $fields),
                ];
            }
        }

        ksort($decisions, SORT_STRING);

        return $decisions;
    }

    private function matchMethod(ChurchServiceItemAssertion $assertion): string
    {
        return match (true) {
            filled($assertion->song_canonical_key), $assertion->song_id !== null => 'song_identity',
            filled($assertion->normalized_scripture_key), filled($assertion->scripture_reference) => 'scripture_reference',
            default => 'normalized_title',
        };
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     * @param  list<string>  $fields
     */
    private function explain(
        Collection $assertions,
        ChurchServiceItemAssertion $assertion,
        string $method,
        array $fields,
    ): string {
        $source = str_replace('openlp', 'OpenLP', ucfirst($assertion->sourceRecord->source->value));
        $others = $assertions->count() - 1;
        $sentence = match ($method) {
            'sole_source' => "Only {$source} asserted this item; no other active source corroborates it.",
            'song_identity' => "Matched across {$others} other source assertion(s) by song identity.",
            'scripture_reference' => "Matched across {$others} other source assertion(s) by normalised scripture reference.",
            default => "Matched across {$others} other source assertion(s) by normalised title and occurrence order only — no song or scripture identity was available.",
        };

        if ($fields === []) {
            return "{$sentence} {$source} supplied no canonical field; a higher-authority source won every field.";
        }

        return "{$sentence} {$source} supplied ".implode(', ', $fields).'.';
    }

    /**
     * Ambiguities the projector refuses to resolve on its own. These force the
     * ingestion action to stage a proposal instead of writing canonical rows.
     *
     * @param  Collection<string, Collection<int, ChurchServiceItemAssertion>>  $groups
     * @return list<array<string, mixed>>
     */
    private function conflicts(Collection $groups): array
    {
        return [
            ...$this->repeatConflicts($groups),
            ...$this->orderConflicts($groups),
        ];
    }

    /**
     * A weakly identified item asserted a different number of times by different
     * sources was paired by occurrence order alone. Which occurrence the shorter
     * source meant is a judgement, not a derivation.
     *
     * @param  Collection<string, Collection<int, ChurchServiceItemAssertion>>  $groups
     * @return list<array<string, mixed>>
     */
    private function repeatConflicts(Collection $groups): array
    {
        $counts = [];

        foreach ($groups as $identity => $assertions) {
            $stronglyIdentified = $assertions->contains(
                fn (ChurchServiceItemAssertion $assertion): bool => $this->matchMethod($assertion) !== 'normalized_title',
            );

            if ($stronglyIdentified) {
                continue;
            }

            $base = Str::beforeLast((string) $identity, '#');

            foreach ($assertions as $assertion) {
                $record = $assertion->sourceRecord;
                $sourceKey = "{$record->source->value}\0{$record->source_key}";
                $counts[$base][$sourceKey] = ($counts[$base][$sourceKey] ?? 0) + 1;
            }
        }

        $conflicts = [];

        foreach ($counts as $base => $bySource) {
            if (count($bySource) < 2 || min($bySource) === max($bySource)) {
                continue;
            }

            $conflicts[] = [
                'kind' => 'ambiguous_repeat_match',
                'canonical_identity' => $base,
                'reason' => 'Sources disagree about how many times this item occurred and it carries no song or scripture identity, so occurrences were paired in order.',
                'occurrences_by_source' => $this->readableCounts($bySource),
            ];
        }

        return $conflicts;
    }

    /**
     * @param  array<string, int>  $bySource
     * @return array<string, int>
     */
    private function readableCounts(array $bySource): array
    {
        $readable = [];

        foreach ($bySource as $key => $count) {
            $readable[str_replace("\0", ':', $key)] = $count;
        }

        ksort($readable, SORT_STRING);

        return $readable;
    }

    /**
     * Two plan sources that place the same pair of items in opposite orders leave the
     * planned order underdetermined, and §5.3 gives the projector no rule to break the
     * tie. Observed evidence is deliberately excluded: without qualified timings it
     * carries no order authority, so a recording running the plan out of sequence is an
     * ordinary occurrence rather than a contradiction.
     *
     * @param  Collection<string, Collection<int, ChurchServiceItemAssertion>>  $groups
     * @return list<array<string, mixed>>
     */
    private function orderConflicts(Collection $groups): array
    {
        $sequences = [];

        foreach ($groups as $identity => $assertions) {
            foreach ($assertions as $assertion) {
                if ($assertion->evidence_kind !== ChurchServiceEvidenceKind::Planned) {
                    continue;
                }

                $record = $assertion->sourceRecord;
                $sequences["{$record->source->value}\0{$record->source_key}"][] = [
                    'order' => $this->assertionOrder($assertion),
                    'identity' => (string) $identity,
                ];
            }
        }

        foreach ($sequences as $key => $entries) {
            usort($entries, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
            $sequences[$key] = array_flip(array_column($entries, 'identity'));
        }

        ksort($sequences, SORT_STRING);
        $keys = array_keys($sequences);
        $conflicts = [];

        for ($left = 0; $left < count($keys); $left++) {
            for ($right = $left + 1; $right < count($keys); $right++) {
                $inversion = $this->firstInversion($sequences[$keys[$left]], $sequences[$keys[$right]]);

                if ($inversion === null) {
                    continue;
                }

                $conflicts[] = [
                    'kind' => 'order_inversion',
                    'sources' => [
                        str_replace("\0", ':', (string) $keys[$left]),
                        str_replace("\0", ':', (string) $keys[$right]),
                    ],
                    'reason' => 'These plan sources place the same two items in opposite orders, so the planned order cannot be derived.',
                    'canonical_identities' => $inversion,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     * @return list<string>|null
     */
    private function firstInversion(array $left, array $right): ?array
    {
        $shared = array_keys(array_intersect_key($left, $right));
        usort($shared, static fn (string $one, string $two): int => $left[$one] <=> $left[$two]);

        for ($index = 1; $index < count($shared); $index++) {
            if ($right[$shared[$index]] < $right[$shared[$index - 1]]) {
                return [$shared[$index - 1], $shared[$index]];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $activeRecords
     * @return array{summary: mixed, notices: mixed, chapter_markers: mixed}
     */
    private function projectServiceContent(
        Collection $activeRecords,
        ?ChurchServiceSourceRecord $manualRecord,
    ): array {
        $contentRecord = $manualRecord;

        if (! $contentRecord instanceof ChurchServiceSourceRecord) {
            $contentRecord = $activeRecords
                ->where('source', ChurchServiceSource::Livestream)
                ->filter(fn (ChurchServiceSourceRecord $record): bool => is_array($record->service_content))
                ->sortByDesc(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
                ->first();
        }

        $content = $contentRecord instanceof ChurchServiceSourceRecord
            ? ($contentRecord->service_content ?? [])
            : [];

        return [
            'summary' => $content['summary'] ?? null,
            'notices' => $content['notices'] ?? null,
            'chapter_markers' => $content['chapter_markers'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $activeRecords
     */
    private function machineSourceSummary(Collection $activeRecords): string
    {
        $sources = $activeRecords
            ->pluck('source')
            ->reject(fn (ChurchServiceSource $source): bool => $source === ChurchServiceSource::Manual)
            ->unique(fn (ChurchServiceSource $source): string => $source->value)
            ->values();

        return $sources->count() === 1
            ? $sources->first()->value
            : 'mixed';
    }
}
