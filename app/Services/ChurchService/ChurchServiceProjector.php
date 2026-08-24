<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceProjection;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ChurchServiceProjector
{
    /**
     * 4 (2026-08-21): planned evidence resolves song identity against the catalogue at
     * normalisation, so tier-1 `song_identity` matching applies to historic sources.
     * Projections and bundles produced under version 3 compared raw titles and must be
     * reprojected rather than re-interpreted. See §2.5 and §3.2 of
     * `docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md`.
     *
     * 5 (2026-08-22): a NIP ("not in Praise!") line resolves to a song the hymnbook does not
     * number ahead of an identically titled numbered song, so keys move again on those lines.
     */
    public const int PROJECTION_POLICY_VERSION = 5;

    public const string PROJECTION_POLICY_FORMAT = 'church-service-projection';

    /**
     * The longest base identity {@see self::boundedIdentity()} will emit.
     *
     * `church_service_items.canonical_identity` is a `varchar(255)`; the rest of
     * the budget is the `#{occurrence}` suffix appended when the same base
     * identity occurs more than once in one service.
     */
    private const MaxBaseIdentityLength = 200;

    public function __construct(
        private readonly ChurchServiceSourceRevisionLineageInspector $lineageInspector,
        private readonly int $policyVersion = self::PROJECTION_POLICY_VERSION,
    ) {}

    /** @return array{format: string, version: int} */
    public function policyFingerprint(): array
    {
        return [
            'format' => self::PROJECTION_POLICY_FORMAT,
            'version' => $this->policyVersion,
        ];
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    public function activeSourceRecords(Collection $sourceRecords): Collection
    {
        return $this->activeRecords($sourceRecords);
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function activeManualSourceRecord(Collection $sourceRecords): ?ChurchServiceSourceRecord
    {
        return $this->manualRecord($this->activeRecords($sourceRecords));
    }

    /**
     * The audit is complete only when the portable explanation manifest accounts
     * for the whole projection in both directions: every active assertion that
     * contributed carries a field decision, every decision names an item that
     * survived into the projection, every projected item is explained by at
     * least one decision, and no unresolved conflict remains.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function hasCompleteAudit(
        Collection $sourceRecords,
        ChurchServiceProjection $projection,
    ): bool {
        if ($projection->conflicts !== []) {
            return false;
        }

        return $this->hasCompleteFieldDecisionAudit($sourceRecords, $projection);
    }

    /**
     * The explanation half of {@see hasCompleteAudit()} on its own: does the field-
     * decision manifest account for the projection in both directions?
     *
     * Split out because a caller that reports conflicts separately must not also
     * report them as a missing audit. {@see IngestChurchServiceSourceRevision}
     * appends `$projection->conflicts` to its staging reasons, so asking the
     * combined question there put `incomplete_projection_audit` on every conflicted
     * proposal — a reason that named nothing an operator could act on and masked the
     * class each proposal genuinely belonged to.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function hasCompleteFieldDecisionAudit(
        Collection $sourceRecords,
        ChurchServiceProjection $projection,
    ): bool {
        $activeRecords = $this->activeRecords($sourceRecords);
        $expected = $this->projectionRecords($activeRecords, $this->manualRecord($activeRecords))
            ->flatMap(fn (ChurchServiceSourceRecord $record): Collection => $record->assertions
                ->map(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldDecisionKey($assertion)))
            ->sort()
            ->values()
            ->all();
        $actual = array_keys($projection->fieldDecisions);
        sort($actual, SORT_STRING);

        if ($expected !== $actual) {
            return false;
        }

        $projectedIdentities = array_column($projection->items, 'canonical_identity');
        $explainedIdentities = array_values(array_unique(array_map(
            static fn (array $decision): mixed => $decision['canonical_identity'] ?? null,
            $projection->fieldDecisions,
        )));
        sort($projectedIdentities, SORT_STRING);
        sort($explainedIdentities, SORT_STRING);

        return $projectedIdentities === $explainedIdentities;
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function project(Collection $sourceRecords): ChurchServiceProjection
    {
        $activeRecords = $this->activeRecords($sourceRecords);
        $manualRecord = $this->manualRecord($activeRecords);
        $projectionRecords = $this->projectionRecords($activeRecords, $manualRecord);
        $matching = $this->matchedAssertionGroups($projectionRecords);
        $groups = $matching['groups'];
        $serviceContent = $this->projectServiceContent($activeRecords, $manualRecord);
        $items = [];

        foreach ($this->orderedGroupIdentities($groups) as $index => $identity) {
            $item = $this->projectItem($groups[$identity], $identity);
            $item['position'] = $index + 1;
            unset($item['_order']);
            $items[] = $item;
        }

        $conflicts = [
            ...$this->repeatConflicts($groups),
            ...$this->orderConflicts($groups),
            ...$matching['conflicts'],
            ...$this->fieldConflicts($groups),
            ...$this->contentCorroborationConflicts($projectionRecords),
            ...$this->serviceContentConflicts($activeRecords, $manualRecord),
        ];
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
            conflicts: $conflicts,
            policyFingerprint: $this->policyFingerprint(),
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
     * The records a projection is actually built from: one Manual revision if a
     * person has spoken, otherwise every active machine revision. Both the
     * projection and its audit must agree on this set, so they share it.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $activeRecords
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function projectionRecords(
        Collection $activeRecords,
        ?ChurchServiceSourceRecord $manualRecord,
    ): Collection {
        if ($manualRecord instanceof ChurchServiceSourceRecord) {
            return collect([$manualRecord]);
        }

        return $activeRecords
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source !== ChurchServiceSource::Manual
                && $record->payload_complete)
            ->values();
    }

    /**
     * Keep only the unique leaf for each stable source lineage. Source revision
     * timestamps and primary keys are deliberately absent from this decision.
     *
     * Pruning is idempotent: callers may hand back a collection this method has
     * already reduced, so a supersession edge to an ancestor that is no longer
     * loaded means "already pruned", not "corrupt".
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function activeRecords(Collection $records): Collection
    {
        $this->lineageInspector->assertNoCrossLineageSupersession($records);

        $supersededIds = $records
            ->pluck('supersedes_id')
            ->filter()
            ->flip();
        $active = $records
            ->reject(fn (ChurchServiceSourceRecord $record): bool => $supersededIds->has($record->id))
            ->values();

        return $active
            ->groupBy(fn (ChurchServiceSourceRecord $record): string => $this->sourceRecordKey($record))
            ->map(fn (Collection $revisions): ChurchServiceSourceRecord => $this->lineageInspector->activeLeaf($revisions, $active)
                ?? throw new RuntimeException('A source revision lineage must have exactly one active leaf.'))
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => $this->sourceRecordKey($record))
            ->values();
    }

    /** @param Collection<int, ChurchServiceSourceRecord> $activeRecords */
    private function manualRecord(Collection $activeRecords): ?ChurchServiceSourceRecord
    {
        $manualRecords = $activeRecords
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Manual)
            ->values();

        if ($manualRecords->isEmpty()) {
            return null;
        }

        return $manualRecords
            ->sort(function (ChurchServiceSourceRecord $left, ChurchServiceSourceRecord $right): int {
                $capturedComparison = ($left->captured_at?->getTimestamp() ?? 0)
                    <=> ($right->captured_at?->getTimestamp() ?? 0);

                if ($capturedComparison !== 0) {
                    return $capturedComparison;
                }

                return strcmp($this->sourceRecordKey($left)."\0{$left->revision_hash}", $this->sourceRecordKey($right)."\0{$right->revision_hash}");
            })
            ->last();
    }

    private function sourceRecordKey(ChurchServiceSourceRecord $record): string
    {
        return "{$record->source->value}\0{$record->source_key}";
    }

    /**
     * Match assertions in deterministic source order. A group contains at most one
     * assertion from a source revision, so repeated assertions remain separate while
     * compatible incomplete assertions can still converge on one occurrence.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return array{
     *     groups: array<string, array{
     *         assertions: Collection<int, ChurchServiceItemAssertion>,
     *         base_identity: string,
     *         match_tier: int,
     *         match_method: string,
     *         created_index: int,
     *     }>,
     *     conflicts: list<array<string, mixed>>,
     * }
     */
    private function matchedAssertionGroups(Collection $records): array
    {
        /** @var list<array{assertion: ChurchServiceItemAssertion, record_key: string}> $nodes */
        $nodes = [];

        foreach ($records->sortBy(fn (ChurchServiceSourceRecord $record): string => $this->sourceRecordKey($record)) as $record) {
            foreach ($this->orderedAssertions($record) as $assertion) {
                $nodes[] = [
                    'assertion' => $assertion,
                    'record_key' => $this->sourceRecordKey($record),
                ];
            }
        }

        /** @var list<array{assertions: list<ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}> $workingGroups */
        $workingGroups = [];
        $conflicts = [];

        foreach ($nodes as $node) {
            $candidates = [];

            foreach ($workingGroups as $groupIndex => $group) {
                $match = $this->matchGroup($node['assertion'], $node['record_key'], $group);

                if ($match === null) {
                    continue;
                }

                $candidates[] = [
                    'group_index' => $groupIndex,
                    'tier' => $match['tier'],
                    'method' => $match['method'],
                ];
            }

            if ($candidates === []) {
                $workingGroups[] = [
                    'assertions' => [$node['assertion']],
                    'base_identity' => $this->baseIdentity($node['assertion']),
                    'match_tier' => 5,
                    'match_method' => 'sole_source',
                    'created_index' => count($workingGroups),
                ];

                continue;
            }

            $bestTier = min(array_column($candidates, 'tier'));
            $bestCandidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate['tier'] === $bestTier,
            ));
            $baseIdentities = array_values(array_unique(array_map(
                fn (array $candidate): string => $workingGroups[$candidate['group_index']]['base_identity'],
                $bestCandidates,
            )));

            if (count($bestCandidates) > 1 && count($baseIdentities) > 1) {
                $conflicts[] = [
                    'kind' => 'ambiguous_match',
                    'assertion_key' => $node['assertion']->assertion_key,
                    'candidate_identities' => $baseIdentities,
                    'reason' => 'More than one compatible canonical occurrence survived the same matching tier.',
                ];
                $workingGroups[] = [
                    'assertions' => [$node['assertion']],
                    'base_identity' => $this->baseIdentity($node['assertion']),
                    'match_tier' => 5,
                    'match_method' => 'sole_source',
                    'created_index' => count($workingGroups),
                ];

                continue;
            }

            usort($bestCandidates, static fn (array $left, array $right): int => $left['group_index'] <=> $right['group_index']);
            $selected = $bestCandidates[0];
            $group = &$workingGroups[$selected['group_index']];
            $group['assertions'][] = $node['assertion'];
            $group['match_tier'] = min($group['match_tier'], $selected['tier']);
            $group['match_method'] = $this->strongerMatchMethod(
                $group['match_method'],
                $selected['method'],
            );
            unset($group);
        }

        /** @var array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}> $groups */
        $groups = [];
        $occurrenceNumbers = [];

        foreach ($workingGroups as $group) {
            $baseIdentity = $this->groupBaseIdentity($group['assertions']);
            $occurrenceNumbers[$baseIdentity] = ($occurrenceNumbers[$baseIdentity] ?? 0) + 1;
            $identity = "{$baseIdentity}#{$occurrenceNumbers[$baseIdentity]}";
            $groups[$identity] = [
                'assertions' => collect($group['assertions']),
                'base_identity' => $baseIdentity,
                'match_tier' => $group['match_tier'],
                'match_method' => $group['match_method'],
                'created_index' => $group['created_index'],
            ];
        }

        ksort($groups, SORT_STRING);

        return compact('groups', 'conflicts');
    }

    /** @return Collection<int, ChurchServiceItemAssertion> */
    private function orderedAssertions(ChurchServiceSourceRecord $record): Collection
    {
        return $record->assertions
            ->sort(function (ChurchServiceItemAssertion $left, ChurchServiceItemAssertion $right): int {
                $positionComparison = ((int) $left->source_position) <=> ((int) $right->source_position);

                if ($positionComparison !== 0) {
                    return $positionComparison;
                }

                return strcmp((string) $left->assertion_key, (string) $right->assertion_key);
            })
            ->values();
    }

    /**
     * @param  array{assertions: list<ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $group
     * @return array{tier: int, method: string}|null
     */
    private function matchGroup(
        ChurchServiceItemAssertion $assertion,
        string $recordKey,
        array $group,
    ): ?array {
        foreach ($group['assertions'] as $existing) {
            if ($this->sourceRecordKey($existing->sourceRecord) === $recordKey) {
                return null;
            }
        }

        $best = null;

        foreach ($group['assertions'] as $existing) {
            $match = $this->matchPair($assertion, $existing);

            if ($match === null || ($best !== null && $match['tier'] >= $best['tier'])) {
                continue;
            }

            $best = $match;
        }

        return $best;
    }

    /** @return array{tier: int, method: string}|null */
    private function matchPair(
        ChurchServiceItemAssertion $left,
        ChurchServiceItemAssertion $right,
    ): ?array {
        if ($this->semanticType($left) !== $this->semanticType($right)) {
            return null;
        }

        $leftIdentity = $this->strongIdentity($left);
        $rightIdentity = $this->strongIdentity($right);

        if ($leftIdentity !== null && $rightIdentity !== null) {
            if ($leftIdentity !== $rightIdentity) {
                return null;
            }

            return [
                'tier' => 1,
                'method' => str_starts_with($leftIdentity, 'song:') ? 'song_identity' : 'scripture_reference',
            ];
        }

        if ($this->sameNormalizedTitle($left, $right)) {
            return ['tier' => 2, 'method' => 'normalized_title'];
        }

        if ($this->anchoredTitleCompatibility($left, $right)) {
            return ['tier' => 3, 'method' => 'anchored_title'];
        }

        if ($this->anchoredPositionCompatibility($left, $right)) {
            return ['tier' => 4, 'method' => 'anchored_position'];
        }

        return null;
    }

    private function sameNormalizedTitle(
        ChurchServiceItemAssertion $left,
        ChurchServiceItemAssertion $right,
    ): bool {
        $leftTitle = $this->normalizedTitle($left);
        $rightTitle = $this->normalizedTitle($right);

        return $leftTitle !== '' && $leftTitle === $rightTitle;
    }

    private function anchoredTitleCompatibility(
        ChurchServiceItemAssertion $left,
        ChurchServiceItemAssertion $right,
    ): bool {
        if ($left->evidence_kind !== ChurchServiceEvidenceKind::Planned
            || $right->evidence_kind !== ChurchServiceEvidenceKind::Planned
            || $left->sourceRecord->source === $right->sourceRecord->source
        ) {
            return false;
        }

        $sources = collect([$left->sourceRecord->source, $right->sourceRecord->source])
            ->map(fn (ChurchServiceSource $source): string => $source->value)
            ->sort()
            ->values()
            ->all();

        if ($sources !== [ChurchServiceSource::Email->value, ChurchServiceSource::OpenLp->value]) {
            return false;
        }

        $leftTokens = $this->titleTokens($left);
        $rightTokens = $this->titleTokens($right);
        $overlap = array_intersect($leftTokens, $rightTokens);

        return $overlap !== []
            && $left->source_position === $right->source_position;
    }

    private function anchoredPositionCompatibility(
        ChurchServiceItemAssertion $left,
        ChurchServiceItemAssertion $right,
    ): bool {
        if ($left->source_position !== $right->source_position) {
            return false;
        }

        if (filled($left->normalized_title) && filled($right->normalized_title)) {
            return false;
        }

        $leftWindow = $left->metadata['anchor_window'] ?? null;
        $rightWindow = $right->metadata['anchor_window'] ?? null;

        return is_string($leftWindow) && $leftWindow !== '' && $leftWindow === $rightWindow;
    }

    /** @return list<string> */
    private function titleTokens(ChurchServiceItemAssertion $assertion): array
    {
        $tokens = preg_split('/\s+/u', $this->normalizedTitle($assertion), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? $tokens : [];
    }

    private function normalizedTitle(ChurchServiceItemAssertion $assertion): string
    {
        return Str::of((string) ($assertion->normalized_title ?? $assertion->title))
            ->lower()
            ->squish()
            ->value();
    }

    private function semanticType(ChurchServiceItemAssertion $assertion): string
    {
        if ($this->strongIdentity($assertion) !== null && str_starts_with((string) $this->strongIdentity($assertion), 'song:')) {
            return 'song';
        }

        if ($this->strongIdentity($assertion) !== null) {
            return 'scripture';
        }

        $sectionType = $assertion->section_type?->value;
        if ($sectionType === 'song') {
            return 'song';
        }

        if ($sectionType === 'bible_reading') {
            return 'scripture';
        }

        $type = Str::of((string) $assertion->type)->lower()->squish()->value();

        return match ($type) {
            'song', 'songs' => 'song',
            'bible', 'bibles', 'scripture', 'scriptures' => 'scripture',
            default => $sectionType !== null && $sectionType !== 'other'
                ? "section:{$sectionType}"
                : "type:{$type}",
        };
    }

    private function strongIdentity(ChurchServiceItemAssertion $assertion): ?string
    {
        if (filled($assertion->song_canonical_key)) {
            return 'song:'.Str::of((string) $assertion->song_canonical_key)->lower()->squish()->value();
        }

        if (filled($assertion->normalized_scripture_key)) {
            return 'scripture:'.Str::of((string) $assertion->normalized_scripture_key)->lower()->squish()->value();
        }

        if (filled($assertion->scripture_reference)) {
            return 'scripture:'.Str::of((string) $assertion->scripture_reference)->lower()->squish()->value();
        }

        $stableKey = $assertion->metadata['source_stable_key'] ?? null;

        return is_string($stableKey) && $stableKey !== '' ? 'stable:'.mb_strtolower($stableKey) : null;
    }

    private function baseIdentity(ChurchServiceItemAssertion $assertion): string
    {
        $strongIdentity = $this->strongIdentity($assertion);

        if ($strongIdentity !== null) {
            return $this->boundedIdentity($strongIdentity);
        }

        return $this->boundedIdentity(mb_strtolower("{$assertion->type}:{$this->normalizedTitle($assertion)}"));
    }

    /** @param list<ChurchServiceItemAssertion> $assertions */
    private function groupBaseIdentity(array $assertions): string
    {
        foreach ($assertions as $assertion) {
            $identity = $this->strongIdentity($assertion);

            if ($identity !== null) {
                return $this->boundedIdentity($identity);
            }
        }

        return $this->baseIdentity($assertions[0]);
    }

    /**
     * Bound an identity to the column that has to store it.
     *
     * A base identity is composed from a title, song key or scripture reference,
     * none of which is length-limited, and the result is written to
     * `church_service_items.canonical_identity` — a `varchar(255)`. The 2026-08-11
     * Email staging run lost a whole service to that mismatch: a conversational
     * note was parsed as an order of service, one 265-character line became an
     * item title, and the insert failed after the service's run had begun.
     *
     * Truncating alone would trade a crash for something worse, because two long
     * titles sharing a prefix would collapse into one identity and project as the
     * same item. The digest keeps them distinct while the retained prefix keeps
     * the identity readable in a census or conflict report, and hashing the whole
     * input keeps it stable across runs — identities are matched between
     * revisions, so a value that changed per run would break idempotency.
     */
    private function boundedIdentity(string $identity): string
    {
        if (mb_strlen($identity) <= self::MaxBaseIdentityLength) {
            return $identity;
        }

        return mb_substr($identity, 0, self::MaxBaseIdentityLength - 33)
            .'~'.substr(hash('sha256', $identity), 0, 32);
    }

    private function strongerMatchMethod(string $current, string $incoming): string
    {
        $methods = [
            'song_identity' => 1,
            'scripture_reference' => 1,
            'normalized_title' => 2,
            'anchored_title' => 3,
            'anchored_position' => 4,
            'sole_source' => 5,
        ];

        return ($methods[$incoming] ?? 5) < ($methods[$current] ?? 5) ? $incoming : $current;
    }

    /**
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}>  $groups
     * @return list<string>
     */
    private function orderedGroupIdentities(array $groups): array
    {
        $observed = array_keys(array_filter(
            $groups,
            fn (array $group): bool => $this->hasEvidenceKind($group['assertions'], ChurchServiceEvidenceKind::Observed),
        ));
        $timedObserved = array_values(array_filter(
            $observed,
            fn (string $identity): bool => $this->observedStart($groups[$identity]['assertions']) !== null,
        ));
        $untimedObserved = array_values(array_diff($observed, $timedObserved));
        usort($timedObserved, fn (string $left, string $right): int => $this->compareObservedGroups($groups[$left], $groups[$right], $left, $right));
        usort($untimedObserved, fn (string $left, string $right): int => $this->comparePlanGroups($groups[$left], $groups[$right], $left, $right));
        $observedOrder = [...$timedObserved, ...$untimedObserved];

        $plannedOrder = array_keys(array_filter(
            $groups,
            fn (array $group): bool => $this->hasEvidenceKind($group['assertions'], ChurchServiceEvidenceKind::Planned),
        ));
        usort($plannedOrder, fn (string $left, string $right): int => $this->comparePlanGroups($groups[$left], $groups[$right], $left, $right));

        if ($observedOrder === []) {
            return $plannedOrder === [] ? $this->createdGroupOrder($groups) : $plannedOrder;
        }

        $observedIndexes = array_flip($observedOrder);
        $buckets = array_fill(0, count($observedOrder) + 1, []);

        foreach ($plannedOrder as $index => $identity) {
            if (isset($observedIndexes[$identity])) {
                continue;
            }

            $previousObservedIndex = null;
            $nextObservedIndex = null;

            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $candidate = $plannedOrder[$cursor];
                if (isset($observedIndexes[$candidate])) {
                    $previousObservedIndex = $observedIndexes[$candidate];
                    break;
                }
            }

            for ($cursor = $index + 1, $count = count($plannedOrder); $cursor < $count; $cursor++) {
                $candidate = $plannedOrder[$cursor];
                if (isset($observedIndexes[$candidate])) {
                    $nextObservedIndex = $observedIndexes[$candidate];
                    break;
                }
            }

            $bucket = $nextObservedIndex
                ?? ($previousObservedIndex === null ? 0 : $previousObservedIndex + 1);
            $buckets[$bucket][] = $identity;
        }

        $ordered = [];

        foreach ($observedOrder as $index => $identity) {
            $ordered = [...$ordered, ...$buckets[$index], $identity];
        }

        return [...$ordered, ...$buckets[count($observedOrder)]];
    }

    /**
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}>  $groups
     * @return list<string>
     */
    private function createdGroupOrder(array $groups): array
    {
        uasort($groups, static fn (array $left, array $right): int => $left['created_index'] <=> $right['created_index']);

        return array_keys($groups);
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function hasEvidenceKind(Collection $assertions, ChurchServiceEvidenceKind $kind): bool
    {
        return $assertions->contains(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === $kind);
    }

    /**
     * @param  array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $left
     * @param  array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $right
     */
    private function compareObservedGroups(array $left, array $right, string $leftIdentity, string $rightIdentity): int
    {
        $leftStart = $this->observedStart($left['assertions']);
        $rightStart = $this->observedStart($right['assertions']);

        if ($leftStart !== $rightStart) {
            return $leftStart <=> $rightStart;
        }

        return strcmp($leftIdentity, $rightIdentity);
    }

    /**
     * @param  array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $left
     * @param  array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $right
     */
    private function comparePlanGroups(array $left, array $right, string $leftIdentity, string $rightIdentity): int
    {
        foreach ([ChurchServiceSource::OpenLp, ChurchServiceSource::Email] as $source) {
            $leftPosition = $this->planPosition($left['assertions'], $source);
            $rightPosition = $this->planPosition($right['assertions'], $source);

            if ($leftPosition === $rightPosition) {
                continue;
            }

            if ($leftPosition === null) {
                return 1;
            }

            if ($rightPosition === null) {
                return -1;
            }

            return $leftPosition <=> $rightPosition;
        }

        return strcmp($leftIdentity, $rightIdentity);
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function planPosition(Collection $assertions, ChurchServiceSource $source): ?int
    {
        $positions = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Planned
                && $assertion->sourceRecord->source === $source)
            ->pluck('source_position')
            ->map(fn (mixed $position): int => (int) $position)
            ->sort()
            ->values();

        return $positions->first();
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function observedStart(Collection $assertions): ?float
    {
        $starts = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->pluck('start_seconds')
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): float => (float) $value)
            ->sort()
            ->values();

        return $starts->first();
    }

    /**
     * @param  array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string, match_tier: int, match_method: string, created_index: int}  $group
     * @return array<string, mixed>
     */
    private function projectItem(array $group, string $canonicalIdentity): array
    {
        $assertions = $group['assertions'];
        $selected = $this->fieldWinner($assertions, 'title')
            ?? $assertions->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))->firstOrFail();
        $typeWinner = $this->fieldWinner($assertions, 'type');
        $type = $typeWinner instanceof ChurchServiceItemAssertion ? $typeWinner->type : $selected->type;
        $sectionType = $this->fieldWinner($assertions, 'section_type')?->section_type?->value;
        $sourceTitle = $this->fieldWinner($assertions, 'source_title')?->source_title;
        $planned = $this->hasEvidenceKind($assertions, ChurchServiceEvidenceKind::Planned);
        $observed = $this->hasEvidenceKind($assertions, ChurchServiceEvidenceKind::Observed);
        $manual = $this->hasEvidenceKind($assertions, ChurchServiceEvidenceKind::Manual);
        $observedAssertion = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->sort(function (ChurchServiceItemAssertion $left, ChurchServiceItemAssertion $right): int {
                $leftStart = $left->start_seconds;
                $rightStart = $right->start_seconds;

                if ($leftStart !== $rightStart) {
                    if ($leftStart === null) {
                        return 1;
                    }

                    if ($rightStart === null) {
                        return -1;
                    }

                    return $leftStart <=> $rightStart;
                }

                return strcmp($this->assertionOrder($left), $this->assertionOrder($right));
            })
            ->first();
        $selectedMetadata = $selected->metadata ?? [];
        $observedMetadata = $observedAssertion instanceof ChurchServiceItemAssertion
            ? ($observedAssertion->metadata ?? [])
            : [];
        $metadata = [
            ...$selectedMetadata,
            ...$observedMetadata,
            'source_assertion_hashes' => $assertions
                ->map(fn (ChurchServiceItemAssertion $assertion): string => $assertion->sourceRecord->revision_hash.':'.$assertion->assertion_key)
                ->sort()
                ->values()
                ->all(),
            'source_assertion_sources' => $assertions
                ->map(fn (ChurchServiceItemAssertion $assertion): string => $assertion->sourceRecord->source->value)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];

        return [
            'canonical_identity' => $canonicalIdentity,
            'type' => $type,
            'section_type' => $sectionType,
            'source' => $selected->sourceRecord->source->value,
            'title' => $selected->title,
            'source_title' => $sourceTitle,
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
            'metadata' => $metadata,
            '_order' => $group['created_index'],
        ];
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function authoritativeSongId(Collection $assertions): ?int
    {
        return $this->fieldWinner($assertions, 'song_id')?->song_id;
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function openLpSearchTitle(Collection $assertions): ?string
    {
        $openLp = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === ChurchServiceSource::OpenLp)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
            ->first();

        if (! $openLp instanceof ChurchServiceItemAssertion) {
            return null;
        }

        return $openLp->metadata['openlp_search_title']
            ?? $openLp->source_title
            ?? $openLp->title;
    }

    /** @param Collection<int, ChurchServiceItemAssertion> $assertions */
    private function fieldWinner(Collection $assertions, string $field): ?ChurchServiceItemAssertion
    {
        $candidates = $assertions->filter(fn (ChurchServiceItemAssertion $assertion): bool => $this->hasFieldValue($assertion, $field));

        return $candidates
            ->sort(function (ChurchServiceItemAssertion $left, ChurchServiceItemAssertion $right) use ($field): int {
                $rankComparison = $this->fieldAuthorityRank($left, $field) <=> $this->fieldAuthorityRank($right, $field);

                if ($rankComparison !== 0) {
                    return $rankComparison;
                }

                return strcmp($this->assertionOrder($left), $this->assertionOrder($right));
            })
            ->first();
    }

    private function hasFieldValue(ChurchServiceItemAssertion $assertion, string $field): bool
    {
        return match ($field) {
            'title' => filled($assertion->title),
            'source_title' => filled($assertion->source_title),
            'type' => filled($assertion->type),
            'section_type' => $assertion->section_type !== null,
            'song_id' => $assertion->song_id !== null,
            'song_canonical_key' => filled($assertion->song_canonical_key),
            'scripture_reference' => filled($assertion->scripture_reference),
            default => false,
        };
    }

    private function fieldAuthorityRank(ChurchServiceItemAssertion $assertion, string $field): int
    {
        if ($assertion->sourceRecord->source === ChurchServiceSource::Manual) {
            return 0;
        }

        if (in_array($field, ['song_id', 'song_canonical_key', 'scripture_reference'], true)) {
            return match ($assertion->sourceRecord->source) {
                ChurchServiceSource::OpenLp => 10,
                ChurchServiceSource::Email => 20,
                ChurchServiceSource::Livestream => 30,
            };
        }

        if ($field === 'title' && $this->semanticType($assertion) === 'song') {
            return match ($assertion->sourceRecord->source) {
                ChurchServiceSource::OpenLp => 10,
                ChurchServiceSource::Email => 20,
                ChurchServiceSource::Livestream => 30,
            };
        }

        return match ($assertion->sourceRecord->source) {
            ChurchServiceSource::Email => 10,
            ChurchServiceSource::OpenLp => 20,
            ChurchServiceSource::Livestream => 30,
        };
    }

    private function assertionOrder(ChurchServiceItemAssertion $assertion): string
    {
        $record = $assertion->sourceRecord;

        return $this->sourceRecordKey($record)."\0".
            str_pad((string) $assertion->source_position, 10, '0', STR_PAD_LEFT).
            "\0{$assertion->assertion_key}";
    }

    private function fieldDecisionKey(ChurchServiceItemAssertion $assertion): string
    {
        $record = $assertion->sourceRecord;

        return implode(':', [
            $record->source->value,
            $record->source_key,
            $record->revision_hash,
            $assertion->assertion_key,
        ]);
    }

    /**
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>, match_method: string, match_tier: int}>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function fieldDecisions(array $groups): array
    {
        $decisions = [];

        foreach ($groups as $identity => $group) {
            $assertions = $group['assertions'];
            $selected = $this->fieldWinner($assertions, 'title')
                ?? $assertions->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))->firstOrFail();
            $songWinner = $this->fieldWinner($assertions, 'song_id');
            $openLpWinner = $assertions
                ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === ChurchServiceSource::OpenLp)
                ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
                ->first();
            $method = $assertions->count() === 1 ? 'sole_source' : $group['match_method'];

            foreach ($assertions as $assertion) {
                $fields = [];

                foreach (['type', 'section_type', 'title', 'source_title'] as $field) {
                    if ($this->fieldWinner($assertions, $field)?->is($assertion)) {
                        $fields[] = $field;
                    }
                }

                if ($songWinner?->is($assertion)) {
                    $fields[] = 'song_id';
                }

                if ($openLpWinner?->is($assertion)) {
                    $fields[] = 'openlp_search_title';
                }

                if ($assertion->evidence_kind === ChurchServiceEvidenceKind::Observed) {
                    $fields[] = 'occurrence';
                }

                $record = $assertion->sourceRecord;
                $decisions[$this->fieldDecisionKey($assertion)] = [
                    'assertion_key' => $assertion->assertion_key,
                    'source' => $record->source->value,
                    'source_key' => $record->source_key,
                    'canonical_identity' => $identity,
                    'match_method' => $method,
                    'match_tier' => $group['match_tier'],
                    'selected_fields' => array_values(array_unique($fields)),
                    'explanation' => $this->explain($assertions, $assertion, $method, $fields),
                ];
            }
        }

        ksort($decisions, SORT_STRING);

        return $decisions;
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
            'anchored_title' => "Matched across {$others} other source assertion(s) by a compatible title inside the same plan anchor.",
            'anchored_position' => "Matched across {$others} other source assertion(s) by compatible position inside the same anchor window.",
            default => "Matched across {$others} other source assertion(s) by normalised title and occurrence order.",
        };

        if ($fields === []) {
            return "{$sentence} {$source} supplied no canonical field; a higher-authority source won every field.";
        }

        return "{$sentence} {$source} supplied ".implode(', ', $fields).'.';
    }

    /**
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>, base_identity: string}>  $groups
     * @return list<array<string, mixed>>
     */
    private function repeatConflicts(array $groups): array
    {
        $counts = [];

        foreach ($groups as $group) {
            $stronglyIdentified = $group['assertions']->contains(
                fn (ChurchServiceItemAssertion $assertion): bool => $this->strongIdentity($assertion) !== null,
            );

            if ($stronglyIdentified) {
                continue;
            }

            foreach ($group['assertions'] as $assertion) {
                $sourceKey = $this->sourceRecordKey($assertion->sourceRecord);
                $counts[$group['base_identity']][$sourceKey] = ($counts[$group['base_identity']][$sourceKey] ?? 0) + 1;
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
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>}>  $groups
     * @return list<array<string, mixed>>
     */
    private function orderConflicts(array $groups): array
    {
        $sequences = [];

        foreach ($groups as $identity => $group) {
            foreach ($group['assertions'] as $assertion) {
                if ($assertion->evidence_kind !== ChurchServiceEvidenceKind::Planned) {
                    continue;
                }

                $record = $assertion->sourceRecord;
                $sequences[$this->sourceRecordKey($record)][] = [
                    'order' => $this->assertionOrder($assertion),
                    'identity' => $identity,
                ];
            }
        }

        foreach ($sequences as $key => $entries) {
            usort($entries, static fn (array $left, array $right): int => strcmp($left['order'], $right['order']));
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
     * @param  array<string, array{assertions: Collection<int, ChurchServiceItemAssertion>}>  $groups
     * @return list<array<string, mixed>>
     */
    private function fieldConflicts(array $groups): array
    {
        $conflicts = [];

        foreach ($groups as $identity => $group) {
            foreach (['title', 'type', 'section_type', 'song_canonical_key', 'scripture_reference'] as $field) {
                $valuesByRank = [];

                foreach ($group['assertions'] as $assertion) {
                    if (! $this->hasFieldValue($assertion, $field)) {
                        continue;
                    }

                    $rank = $this->fieldAuthorityRank($assertion, $field);
                    $value = $this->fieldValue($assertion, $field);
                    $valuesByRank[$rank][(string) $value] = $value;
                }

                if ($valuesByRank === []) {
                    continue;
                }

                ksort($valuesByRank, SORT_NUMERIC);
                $rank = (int) array_key_first($valuesByRank);

                if (count($valuesByRank[$rank]) < 2) {
                    continue;
                }

                $conflicts[] = [
                    'kind' => 'field_conflict',
                    'canonical_identity' => $identity,
                    'field' => $field,
                    'authority_rank' => $rank,
                    'values' => array_values($valuesByRank[$rank]),
                    'reason' => "Equal-authority source assertions disagree about {$field}.",
                ];
            }
        }

        return $conflicts;
    }

    private function fieldValue(ChurchServiceItemAssertion $assertion, string $field): mixed
    {
        return match ($field) {
            'section_type' => $assertion->section_type?->value,
            default => $assertion->{$field},
        };
    }

    /**
     * An email plan admitted as evidence did not clear the existing
     * confidence-and-consensus route. It can nevertheless settle unattended when
     * an independent source proves the same content dimension. Absence of a
     * corroborating source is deliberately not a disagreement; it leaves the
     * dimension unfinalised and therefore stages a proposal. A contradictory
     * independent source is an explicit mismatch.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function contentCorroborationConflicts(Collection $records): array
    {
        $conflicts = [];

        foreach ($records->filter(fn (ChurchServiceSourceRecord $record): bool => $this->requiresContentCorroboration($record)) as $email) {
            foreach (['song_membership', 'song_count', 'song_order'] as $dimension) {
                $emailValue = $this->songDimensionValue($email, $dimension);
                $candidates = $records
                    ->filter(fn (ChurchServiceSourceRecord $record): bool => $this->sourceProvesDimension($record, $dimension))
                    ->values();

                if ($candidates->isEmpty()) {
                    $conflicts[] = $this->unfinalisedDimension($email, $dimension);

                    continue;
                }

                $mismatches = $candidates
                    ->filter(fn (ChurchServiceSourceRecord $record): bool => $this->songDimensionValue($record, $dimension) !== $emailValue)
                    ->values();

                if ($mismatches->isNotEmpty()) {
                    $conflicts[] = [
                        'kind' => 'corroboration_mismatch',
                        'dimension' => $dimension,
                        'email_source_key' => $email->source_key,
                        'sources' => $mismatches
                            ->map(fn (ChurchServiceSourceRecord $record): string => $record->source->value.':'.$record->source_key)
                            ->sort()
                            ->values()
                            ->all(),
                        'reason' => "An independent source disagrees with the Email plan about {$dimension}.",
                    ];

                    continue;
                }
            }
        }

        return $conflicts;
    }

    private function requiresContentCorroboration(ChurchServiceSourceRecord $record): bool
    {
        if ($record->source !== ChurchServiceSource::Email) {
            return false;
        }

        if (($record->processing_fingerprint['format'] ?? null) !== 'email-plan') {
            return false;
        }

        return ($record->processing_fingerprint['unattended_content_finalization'] ?? false) !== true;
    }

    /**
     * Which sources may corroborate a song dimension, per HIR-D8 (§2.5): a source
     * proves only the dimension it actually witnesses.
     *
     * Livestream was previously restricted to `song_order` alone. That was unsound,
     * and no rationale for it was ever recorded — in the plan or here. All three
     * dimensions are views of one list ({@see self::songDimensionValue()}):
     * `song_order` *is* the list, `song_membership` is that list deduplicated and
     * sorted, and `song_count` is its length. Both are pure functions of the order,
     * so a source trusted to prove the sequence has by construction proved the set
     * and its size; granting authority over the value and withholding it from the
     * derivations cannot be expressed coherently.
     *
     * The maintainer ruled on 2026-08-24 that a livestream corroborates all three.
     * Note this makes the recording the stronger witness of what was *actually*
     * sung: OpenLP is a plan, and a planned song may be dropped on the day.
     */
    private function sourceProvesDimension(ChurchServiceSourceRecord $record, string $dimension): bool
    {
        return match ($record->source) {
            ChurchServiceSource::OpenLp, ChurchServiceSource::Livestream => true,
            default => false,
        };
    }

    /** @return list<string>|int */
    private function songDimensionValue(ChurchServiceSourceRecord $record, string $dimension): array|int
    {
        $songs = $record->assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $this->semanticType($assertion) === 'song')
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): int => $assertion->source_position)
            ->map(fn (ChurchServiceItemAssertion $assertion): string => $assertion->song_canonical_key ?? $assertion->normalized_title)
            ->values()
            ->all();
        $songs = array_values($songs);

        return match ($dimension) {
            'song_membership' => $this->sortedUnique($songs),
            'song_count' => count($songs),
            'song_order' => $songs,
            default => throw new RuntimeException("Unknown corroboration dimension {$dimension}."),
        };
    }

    /** @return array<string, mixed> */
    private function unfinalisedDimension(ChurchServiceSourceRecord $email, string $dimension): array
    {
        return [
            'kind' => 'uncorroborated_content_dimension',
            'dimension' => $dimension,
            'email_source_key' => $email->source_key,
            'reason' => "No independent source is available to corroborate the Email plan's {$dimension}.",
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
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
                ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Livestream
                    && is_array($record->service_content))
                ->sortBy(fn (ChurchServiceSourceRecord $record): string => $this->sourceRecordKey($record))
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
     * @return list<array<string, mixed>>
     */
    private function serviceContentConflicts(
        Collection $activeRecords,
        ?ChurchServiceSourceRecord $manualRecord,
    ): array {
        if ($manualRecord instanceof ChurchServiceSourceRecord) {
            return [];
        }

        $records = $activeRecords
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Livestream
                && is_array($record->service_content))
            ->values();
        $conflicts = [];

        foreach (['summary', 'notices', 'chapter_markers'] as $field) {
            $values = $records
                ->map(fn (ChurchServiceSourceRecord $record): mixed => $record->service_content[$field] ?? null)
                ->filter(fn (mixed $value): bool => $value !== null)
                ->map(fn (mixed $value): string => CanonicalJson::encode($value))
                ->unique()
                ->values();

            if ($values->count() < 2) {
                continue;
            }

            $conflicts[] = [
                'kind' => 'field_conflict',
                'field' => "service_content.{$field}",
                'reason' => "Equal-authority Livestream assertions disagree about {$field}.",
            ];
        }

        return $conflicts;
    }

    /** @param Collection<int, ChurchServiceSourceRecord> $activeRecords */
    private function machineSourceSummary(Collection $activeRecords): string
    {
        $sources = $activeRecords
            ->pluck('source')
            ->filter(fn (ChurchServiceSource $source): bool => $source !== ChurchServiceSource::Manual)
            ->unique(fn (ChurchServiceSource $source): string => $source->value)
            ->sortBy(fn (ChurchServiceSource $source): string => $source->value)
            ->values();

        return $sources->count() === 1 ? $sources->first()->value : 'mixed';
    }
}
