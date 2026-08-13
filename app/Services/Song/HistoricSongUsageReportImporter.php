<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\SongTitleMatch;
use App\Enums\HistoricSongUsageRowOutcome;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\SongUsageReport;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the date-only historic hymn workbook as source-backed evidence.
 *
 * The run is planned in full against stored state before anything is written, because F62's
 * failure was a rerun that counted a freshly computed catalogue match as a persisted one. Every
 * count this returns describes what the database holds, never what the resolver just calculated.
 *
 * @phpstan-type HistoricSongUsageRecord array{
 *     used_on: string,
 *     reported_number: string|null,
 *     reported_title: string,
 *     catalog_title: string|null,
 *     workbook_match_method: string|null,
 *     source_workbook: string,
 *     source_sheet: string,
 *     source_row: int
 * }
 * @phpstan-type HistoricSongUsageRowPlan array{
 *     record: HistoricSongUsageRecord,
 *     fingerprint: string,
 *     existing: SongUsageReport|null,
 *     song_id: int|null,
 *     match_method: string|null,
 *     link_item_id: int|null,
 *     applies_resolution: bool,
 *     applies_link: bool,
 *     outcome: HistoricSongUsageRowOutcome,
 *     detail: string|null
 * }
 */
class HistoricSongUsageReportImporter
{
    public function __construct(
        private HistoricSongUsageWorkbookReader $reader,
    ) {}

    /**
     * @param  array{rows:int,resolved:int,unresolved:int}|null  $expectedCounts  the approved dry-run contract
     * @return array{
     *     rows_read:int,
     *     resolved:int,
     *     unresolved:int,
     *     linked:int,
     *     outcomes:array<string,int>,
     *     workbook_sha256:string,
     *     membership:list<array{fingerprint:string,outcome:string}>,
     *     membership_sha256:string,
     *     dry_run:bool
     * }
     */
    public function import(
        string $path,
        bool $persist,
        bool $resolveCatalogueMatches = false,
        bool $linkCanonicalOccurrences = false,
        ?HistoricImportOperation $operation = null,
        ?string $approvedWorkbookSha256 = null,
        ?array $expectedCounts = null,
    ): array {
        $workbookSha256 = $this->workbookDigest($path, $approvedWorkbookSha256);
        $records = $this->reader->read($path);
        $plans = $this->plan($records, $resolveCatalogueMatches, $linkCanonicalOccurrences);

        $this->assertNothingBlocks($plans);
        $this->assertContract($plans, $expectedCounts);

        if ($persist) {
            DB::transaction(function () use ($plans, $path, $approvedWorkbookSha256, $workbookSha256, $operation): void {
                /**
                 * Read once, committed against once. A workbook edited between the planning pass
                 * and the write would otherwise be imported as though the plan had described it.
                 */
                $atCommit = $this->workbookDigest($path, $approvedWorkbookSha256);

                if (! hash_equals($workbookSha256, $atCommit)) {
                    throw new RuntimeException('The historic hymn workbook changed between planning and commit.');
                }

                foreach ($plans as $plan) {
                    $this->apply($plan, $operation);
                }
            });
        }

        $membership = $this->membership($plans);

        return [
            'rows_read' => count($records),
            ...$this->persistedTotals($plans, $persist),
            'outcomes' => $this->outcomeCounts($plans),
            'workbook_sha256' => $workbookSha256,
            'membership' => $membership,
            'membership_sha256' => CanonicalJson::hash($membership),
            'dry_run' => ! $persist,
        ];
    }

    /**
     * The approved artifact, verified as bytes rather than as a path.
     *
     * An operation-bound run names its workbook digest in the immutable operation, so pointing
     * `--path` at a different file is refused here rather than discovered in the outcomes.
     */
    private function workbookDigest(string $path, ?string $approvedWorkbookSha256): string
    {
        $observed = hash_file('sha256', $path);

        if (! is_string($observed)) {
            throw new RuntimeException("Historic song usage workbook cannot be hashed: {$path}");
        }

        if ($approvedWorkbookSha256 !== null && ! hash_equals(strtolower($approvedWorkbookSha256), $observed)) {
            throw new RuntimeException(
                'The historic hymn workbook does not match the digest the operation approved.',
            );
        }

        return $observed;
    }

    /**
     * The recorded dry-run contract, checked against the planned end state while the run is
     * still a no-op. F61 asks for a refusal before writes on count or match drift, so this
     * compares what the database *will* hold, not what the workbook says.
     *
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @param  array{rows:int,resolved:int,unresolved:int}|null  $expectedCounts
     */
    private function assertContract(array $plans, ?array $expectedCounts): void
    {
        if ($expectedCounts === null) {
            return;
        }

        $resolved = 0;

        foreach ($plans as $plan) {
            if ($plan['song_id'] !== null) {
                $resolved++;
            }
        }

        $observed = [
            'rows' => count($plans),
            'resolved' => $resolved,
            'unresolved' => count($plans) - $resolved,
        ];

        if ($observed !== $expectedCounts) {
            throw new RuntimeException(sprintf(
                'The historic hymn run does not match its approved contract; no row was written. '
                .'Approved %d rows / %d resolved / %d unresolved, planned %d / %d / %d.',
                $expectedCounts['rows'],
                $expectedCounts['resolved'],
                $expectedCounts['unresolved'],
                $observed['rows'],
                $observed['resolved'],
                $observed['unresolved'],
            ));
        }
    }

    /**
     * Exact per-row membership, in workbook order, for the import report artifact. Closeout
     * later re-derives this from the persisted rows, so a row lost after the import cannot pass.
     *
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @return list<array{fingerprint:string,outcome:string}>
     */
    private function membership(array $plans): array
    {
        $membership = array_map(static fn (array $plan): array => [
            'fingerprint' => $plan['fingerprint'],
            'outcome' => $plan['outcome']->value,
        ], $plans);

        usort($membership, static fn (array $a, array $b): int => $a['fingerprint'] <=> $b['fingerprint']);

        return $membership;
    }

    /**
     * The read-only pass. Each row is decided against the row the database already holds, so a
     * blocking outcome can refuse the run while it is still a no-op.
     *
     * @param  list<HistoricSongUsageRecord>  $records
     * @return list<HistoricSongUsageRowPlan>
     */
    private function plan(array $records, bool $resolveCatalogueMatches, bool $linkCanonicalOccurrences): array
    {
        $resolver = SongTitleResolver::fromDatabase();
        $fingerprints = array_map(fn (array $record): string => $this->fingerprint($record), $records);
        $existingReports = SongUsageReport::query()
            ->whereIn('source_fingerprint', $fingerprints)
            ->get()
            ->keyBy('source_fingerprint');

        $plans = [];

        foreach ($records as $index => $record) {
            $fingerprint = $fingerprints[$index];
            $existing = $existingReports->get($fingerprint);
            $match = $record['catalog_title'] === null ? null : $resolver->resolve($record['catalog_title']);

            $plans[] = $existing instanceof SongUsageReport
                ? $this->planExisting($record, $fingerprint, $existing, $match, $resolveCatalogueMatches)
                : [
                    'record' => $record,
                    'fingerprint' => $fingerprint,
                    'existing' => null,
                    'song_id' => $match?->songId,
                    'match_method' => $match?->matchType,
                    'link_item_id' => null,
                    'applies_resolution' => false,
                    'applies_link' => false,
                    'outcome' => HistoricSongUsageRowOutcome::Created,
                    'detail' => null,
                ];
        }

        return $this->planCanonicalLinks($plans, $linkCanonicalOccurrences);
    }

    /**
     * @param  HistoricSongUsageRecord  $record
     * @return HistoricSongUsageRowPlan
     */
    private function planExisting(
        array $record,
        string $fingerprint,
        SongUsageReport $existing,
        ?SongTitleMatch $match,
        bool $resolveCatalogueMatches,
    ): array {
        $plan = [
            'record' => $record,
            'fingerprint' => $fingerprint,
            'existing' => $existing,
            'song_id' => $existing->song_id,
            'match_method' => $existing->match_method,
            'link_item_id' => null,
            'applies_resolution' => false,
            'applies_link' => false,
            'outcome' => HistoricSongUsageRowOutcome::Unchanged,
            'detail' => null,
        ];

        $drift = $this->sourceDrift($record, $existing);

        if ($drift !== null) {
            return [...$plan, 'outcome' => HistoricSongUsageRowOutcome::SourceDrift, 'detail' => $drift];
        }

        $stored = $existing->song_id;
        $fresh = $match?->songId;

        /**
         * A catalogue that no longer resolves a title does not un-resolve an occurrence that was
         * already accepted; the stored linkage stands until someone withdraws it deliberately.
         */
        if ($fresh === null || $stored === $fresh) {
            return $plan;
        }

        if ($stored !== null) {
            return [
                ...$plan,
                'outcome' => HistoricSongUsageRowOutcome::ResolutionConflict,
                'detail' => "stored song {$stored}, catalogue now resolves song {$fresh}",
            ];
        }

        return $resolveCatalogueMatches
            ? [
                ...$plan,
                'song_id' => $fresh,
                'match_method' => $match->matchType,
                'applies_resolution' => true,
                'outcome' => HistoricSongUsageRowOutcome::ResolutionApplied,
            ]
            : [...$plan, 'outcome' => HistoricSongUsageRowOutcome::ResolutionAvailable];
    }

    /**
     * Later-resolution's other half: an occurrence a canonical service item now proves.
     *
     * Linking is what removes the report from the public occurrence union, so an ambiguous date
     * — two services holding the same song — is held rather than guessed at.
     *
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @return list<HistoricSongUsageRowPlan>
     */
    private function planCanonicalLinks(array $plans, bool $linkCanonicalOccurrences): array
    {
        $candidates = $this->canonicalCandidates($plans);

        foreach ($plans as $index => $plan) {
            if ($plan['outcome']->blocksRun()
                || ! $plan['existing'] instanceof SongUsageReport
                || $plan['existing']->resolved_church_service_item_id !== null
                || $plan['song_id'] === null) {
                continue;
            }

            $itemIds = $candidates[$this->candidateKey($plan['song_id'], (string) $plan['record']['used_on'])] ?? [];

            if ($itemIds === []) {
                continue;
            }

            /**
             * A link outcome only takes the label when nothing larger already holds it: an
             * applied resolution is the more consequential claim about the row, and a run that
             * did both should not report only the link.
             */
            $relabel = $plan['outcome'] === HistoricSongUsageRowOutcome::Unchanged;

            if (count($itemIds) > 1) {
                if ($relabel) {
                    $plans[$index]['outcome'] = HistoricSongUsageRowOutcome::CanonicalLinkAmbiguous;
                    $plans[$index]['detail'] = count($itemIds).' canonical items share the song and date';
                }

                continue;
            }

            if (! $linkCanonicalOccurrences) {
                if ($relabel) {
                    $plans[$index]['outcome'] = HistoricSongUsageRowOutcome::CanonicalLinkAvailable;
                }

                continue;
            }

            $plans[$index]['link_item_id'] = $itemIds[0];
            $plans[$index]['applies_link'] = true;

            if ($relabel) {
                $plans[$index]['outcome'] = HistoricSongUsageRowOutcome::CanonicalLinkApplied;
            }
        }

        return $plans;
    }

    /**
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @return array<string, list<int>>
     */
    private function canonicalCandidates(array $plans): array
    {
        $songIds = [];
        $dates = [];

        foreach ($plans as $plan) {
            if ($plan['song_id'] === null || ! $plan['existing'] instanceof SongUsageReport) {
                continue;
            }

            $songIds[] = $plan['song_id'];
            $dates[] = (string) $plan['record']['used_on'];
        }

        if ($songIds === []) {
            return [];
        }

        $rows = DB::table('church_service_items')
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->whereIn('church_service_items.song_id', array_values(array_unique($songIds)))
            ->whereIn('church_services.date', array_values(array_unique($dates)))
            ->select([
                'church_service_items.id',
                'church_service_items.song_id',
                'church_services.date',
            ])
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $key = $this->candidateKey((int) $row->song_id, substr((string) $row->date, 0, 10));
            $candidates[$key][] = (int) $row->id;
        }

        return $candidates;
    }

    private function candidateKey(int $songId, string $usedOn): string
    {
        return $songId.'|'.$usedOn;
    }

    /**
     * The fields the fingerprint does not cover, which can therefore change under a stable
     * identity. A changed date, title, workbook, sheet or row produces a different fingerprint
     * and so arrives as a new row rather than as drift.
     *
     * @param  HistoricSongUsageRecord  $record
     */
    private function sourceDrift(array $record, SongUsageReport $existing): ?string
    {
        $metadata = $existing->metadata;
        $observed = [
            'reported_number' => $existing->reported_number,
            'catalog_title' => $existing->catalog_title,
            'workbook_match_method' => is_array($metadata) ? ($metadata['workbook_match_method'] ?? null) : null,
        ];
        $incoming = [
            'reported_number' => $record['reported_number'],
            'catalog_title' => $record['catalog_title'],
            'workbook_match_method' => $record['workbook_match_method'],
        ];
        $differences = [];

        foreach ($incoming as $field => $value) {
            if ($observed[$field] !== $value) {
                $differences[] = sprintf(
                    '%s stored %s, workbook now %s',
                    $field,
                    $this->describe($observed[$field]),
                    $this->describe($value),
                );
            }
        }

        return $differences === [] ? null : implode('; ', $differences);
    }

    private function describe(mixed $value): string
    {
        return is_string($value) ? '"'.$value.'"' : 'null';
    }

    /**
     * @param  list<HistoricSongUsageRowPlan>  $plans
     */
    private function assertNothingBlocks(array $plans): void
    {
        $blocking = [];

        foreach ($plans as $plan) {
            if (! $plan['outcome']->blocksRun()) {
                continue;
            }

            $blocking[] = sprintf(
                '%s row %s: %s (%s)',
                $plan['record']['source_sheet'],
                $plan['record']['source_row'],
                strtolower($plan['outcome']->label()),
                $plan['detail'] ?? 'no detail',
            );
        }

        if ($blocking !== []) {
            throw new RuntimeException(
                'The historic hymn workbook disagrees with stored evidence; no row was written.'
                .PHP_EOL.implode(PHP_EOL, $blocking),
            );
        }
    }

    /** @param HistoricSongUsageRowPlan $plan */
    private function apply(array $plan, ?HistoricImportOperation $operation): void
    {
        $record = $plan['record'];
        $existing = $plan['existing'];

        if (! $existing instanceof SongUsageReport) {
            if ($plan['outcome'] !== HistoricSongUsageRowOutcome::Created) {
                return;
            }

            SongUsageReport::query()->create([
                'song_id' => $plan['song_id'],
                'used_on' => $record['used_on'],
                'reported_service' => null,
                'reported_title' => $record['reported_title'],
                'reported_number' => $record['reported_number'],
                'catalog_title' => $record['catalog_title'],
                'match_method' => $plan['match_method'],
                'source_workbook' => $record['source_workbook'],
                'source_sheet' => $record['source_sheet'],
                'source_row' => $record['source_row'],
                'source_fingerprint' => $plan['fingerprint'],
                'metadata' => ['workbook_match_method' => $record['workbook_match_method']],
                /**
                 * Import stages evidence; it never publishes. The row reaches public song
                 * history only through the signed release batch.
                 */
                'publication_state' => SermonPublicationState::Quarantined,
                'historic_import_operation_id' => $operation?->id,
            ]);

            return;
        }

        /**
         * Only the fields an authorisation flag opened. Writing the whole plan back would let a
         * resolution ride along with a link update, which is the class of silent change F62
         * exists to stop.
         */
        $changes = [];

        if ($plan['applies_resolution']) {
            $changes['song_id'] = $plan['song_id'];
            $changes['match_method'] = $plan['match_method'];
        }

        if ($plan['applies_link']) {
            $changes['resolved_church_service_item_id'] = $plan['link_item_id'];
        }

        if ($changes === []) {
            return;
        }

        $existing->forceFill($changes)->save();
    }

    /**
     * Counts read back from the database, so "resolved" can never again mean "the resolver would
     * have matched this row" while the stored row remains unmatched.
     *
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @return array{resolved:int,unresolved:int,linked:int}
     */
    private function persistedTotals(array $plans, bool $persist): array
    {
        if (! $persist) {
            return ['resolved' => 0, 'unresolved' => 0, 'linked' => 0];
        }

        $fingerprints = array_map(static fn (array $plan): string => $plan['fingerprint'], $plans);
        $reports = SongUsageReport::query()
            ->whereIn('source_fingerprint', $fingerprints)
            ->get(['song_id', 'resolved_church_service_item_id']);

        return [
            'resolved' => $reports->whereNotNull('song_id')->count(),
            'unresolved' => $reports->whereNull('song_id')->count(),
            'linked' => $reports->whereNotNull('resolved_church_service_item_id')->count(),
        ];
    }

    /**
     * @param  list<HistoricSongUsageRowPlan>  $plans
     * @return array<string,int>
     */
    private function outcomeCounts(array $plans): array
    {
        $counts = Collection::make($plans)
            ->countBy(static fn (array $plan): string => $plan['outcome']->value)
            ->all();
        $ordered = [];

        foreach (HistoricSongUsageRowOutcome::cases() as $case) {
            if (isset($counts[$case->value])) {
                $ordered[$case->value] = $counts[$case->value];
            }
        }

        return $ordered;
    }

    /** @param HistoricSongUsageRecord $record */
    private function fingerprint(array $record): string
    {
        return hash('sha256', implode('|', [
            $record['source_workbook'],
            $record['source_sheet'],
            (string) $record['source_row'],
            $record['used_on'],
            $record['reported_title'],
        ]));
    }
}
