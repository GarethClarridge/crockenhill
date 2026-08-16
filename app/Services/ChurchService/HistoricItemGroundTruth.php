<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SongTitleHygieneVerdict;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\Song\CatalogueTitleMatcher;
use App\Services\Song\OpenLpServiceParser;
use App\Services\Song\SongTitleHygiene;
use App\Services\Song\SongTitleResolver;
use App\Support\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The item-level ground truth for the historic import — IC3, item 0(2) of the queued parsing
 * plan in `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md`.
 *
 * Every accuracy figure quoted about the Email extraction so far is `identity_correct` — named
 * `exact_correct` until item 0(3) renamed it for exactly this reason — which
 * checks date, service slot and non-emptiness and never inspects an item. It is a fair measure
 * of identity resolution and an unsound one for extraction quality. This joins the two
 * independent sources that *can* see inside a service against the staged plans, so extraction
 * quality becomes measurable without hand-building a truth set.
 *
 * The two sources prove different things and are never merged into one verdict:
 *
 * - **Hymn workbook** — song *membership*. Its source is a crosstab (hymns down the rows, dates
 *   across the columns), so it carries no order and cannot be asked about sequence.
 * - **OpenLP** — item *count* and item *sequence*, from the `.osz` service file itself.
 *
 * Two invariants this builder exists to hold:
 *
 * 1. **No foreign song ids.** The hymn artifact resolved its `song_id`s against whichever
 *    catalogue answered when it was generated. Catalogues differ between databases — the working
 *    and rehearsal catalogues disagree on nine ids at the time of writing — so a workbook
 *    statement is re-resolved here through the measured corpus's own resolver, and the artifact
 *    records both catalogue fingerprints.
 * 2. **No circular corroboration.** A staged service imported *from* OpenLP cannot be
 *    corroborated *by* OpenLP. Item provenance is recorded per identity and an identity whose
 *    items came from a source is never scored against that same source.
 */
class HistoricItemGroundTruth
{
    public const Format = 'historic-item-ground-truth';

    public const Version = 1;

    /** No corroborating source covers this identity at all. */
    public const VerdictNotCorroborated = 'not_corroborated';

    /** A source covers it, but nothing on one side survived resolution, so there is nothing to compare. */
    public const VerdictIndeterminate = 'indeterminate';

    /** The source's own evidence produced this identity, so agreement would prove nothing. */
    public const VerdictCircular = 'circular';

    public const VerdictMatch = 'match';

    public const VerdictMismatch = 'mismatch';

    public function __construct(
        private readonly OpenLpCurationManifest $openLpManifest,
        private readonly OpenLpServiceParser $parser,
        private readonly CatalogueTitleMatcher $titleMatcher = new CatalogueTitleMatcher,
        private readonly SongTitleHygiene $titleHygiene = new SongTitleHygiene,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(string $hymnReconciliationPath, string $openLpManifestPath, string $openLpRoot): array
    {
        $resolver = SongTitleResolver::fromDatabase();
        $staged = $this->stagedIdentities($resolver);
        $hymn = $this->hymnMembership($hymnReconciliationPath, $resolver);
        $openLp = $this->openLpEvidence($openLpManifestPath, $openLpRoot, $resolver);

        $identities = [];

        foreach ($staged as $key => $service) {
            $identities[] = $this->identityRecord(
                $service,
                $hymn['membership'][$key] ?? null,
                $openLp['evidence'][$key] ?? null,
            );
        }

        $unresolvedTitles = $this->unresolvedStagedTitles($identities);

        return [
            'format' => self::Format,
            'version' => self::Version,
            'generated_at' => Carbon::now()->toIso8601String(),
            'policy' => [
                'decision_rule_version' => 'historic-item-ground-truth-v1',
                'identity' => 'Exact date plus the morning/evening slot, as the staged corpus records it.',
                'song_identity' => 'Workbook and staged titles are both resolved through the measured '
                    .'corpus\'s own catalogue. Song ids carried by the hymn artifact are never trusted, '
                    .'because they were resolved against whichever catalogue answered when it was generated.',
                'hymn_workbook_proves' => 'Song membership as a set. The source is a crosstab and asserts no order.',
                'openlp_proves' => 'Song count and song order. An OpenLP archive is a projection deck: it '
                    .'carries presentations, images and media no order of service lists, and omits the spoken '
                    .'items an order does list. Whole-order counts and type sequences are therefore recorded '
                    .'under order_shape and carry no verdict — scored across this corpus they agree on 1 '
                    .'identity in 222, which measures deck-versus-order, not extraction quality.',
                'circularity' => 'An identity whose staged items came from a source is never scored against '
                    .'that source; its verdict is "circular".',
                'title_hygiene' => 'An unresolved song title is classified by shape into who can act on '
                    .'it, because a single unresolved count reads as an extraction error rate and is not '
                    .'one: only the "defective" verdict is extraction quality. "decorated" titles are '
                    .'correct extractions the resolver\'s own cleaning does not reach, "not_a_title" items '
                    .'never carried a title to extract, and "clean" ones are catalogue gaps. '
                    .'recovered_by_normalisation re-probes this corpus\'s catalogue with the decoration '
                    .'removed and sizes a SongTitleResolver fix; it is not a second hit rate.',
            ],
            'corpus' => $this->corpusBinding($resolver, $staged),
            'sources' => [
                'hymn_reconciliation' => $hymn['binding'],
                'openlp' => $openLp['binding'],
            ],
            'identities' => $identities,
            'counts' => $this->counts($identities),
            'unresolved_staged_song_titles' => $unresolvedTitles,
            'title_hygiene' => $this->titleHygieneCensus($unresolvedTitles, $resolver),
        ];
    }

    /**
     * Every staged identity with its items, song titles resolved through the corpus catalogue.
     *
     * Identities with no items are kept. A staged service that produced no order at all is a
     * real extraction outcome, and dropping it here would quietly improve every rate below it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stagedIdentities(SongTitleResolver $resolver): array
    {
        $staged = [];

        ChurchService::query()
            ->with(['items' => static fn ($query) => $query->orderBy('position')])
            ->orderBy('date')
            ->orderBy('service')
            ->chunk(200, function ($services) use (&$staged, $resolver): void {
                foreach ($services as $service) {
                    $key = $this->identityKey($service->date->format('Y-m-d'), $service->service->value);
                    $staged[$key] = $this->stagedRecord($service, $resolver);
                }
            });

        return $staged;
    }

    /**
     * @return array<string, mixed>
     */
    private function stagedRecord(ChurchService $service, SongTitleResolver $resolver): array
    {
        $typeSequence = [];
        $songIds = [];
        $songSequence = [];
        $unresolvedTitles = [];
        $itemSources = [];

        foreach ($service->items as $item) {
            $typeSequence[] = (string) $item->type;
            /**
             * The column is nullable, whatever the cast's inferred type says, and a staged item
             * with no recorded source must not be silently treated as one that has a source —
             * the circularity check below reads exactly this list.
             */
            $itemSource = $item->source;
            $source = $itemSource instanceof ChurchServiceItemSource ? $itemSource->value : 'unrecorded';

            if (! in_array($source, $itemSources, true)) {
                $itemSources[] = $source;
            }

            if ((string) $item->type !== 'songs') {
                continue;
            }

            $title = $this->stagedSearchTitle($item);
            $match = $title === '' ? null : $resolver->resolve($title);

            if ($match === null) {
                $unresolvedTitles[] = $title === '' ? '(blank title)' : $title;
                $songSequence[] = null;

                continue;
            }

            $songSequence[] = $match->songId;

            if (! in_array($match->songId, $songIds, true)) {
                $songIds[] = $match->songId;
            }
        }

        sort($songIds);
        sort($itemSources);

        return [
            'date' => $service->date->format('Y-m-d'),
            'service' => $service->service->value,
            'service_source' => (string) $service->source,
            'item_sources' => $itemSources,
            'item_count' => count($typeSequence),
            'type_sequence' => $typeSequence,
            'song_item_count' => count($songSequence),
            'song_ids' => $songIds,
            'song_sequence' => $songSequence,
            'unresolved_song_titles' => $unresolvedTitles,
        ];
    }

    /**
     * The title a staged song item is resolved on.
     *
     * This is ChurchServiceSongLinker's field cascade, so the measure follows what the live
     * linker would actually match on — minus its `linked_song_canonical_key` override. That
     * override is a human correction, and crediting the parser for one would report an operator's
     * repair as an extraction success.
     */
    private function stagedSearchTitle(ChurchServiceItem $item): string
    {
        foreach ([$item->openlp_search_title, $item->source_title, $item->title] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * Song membership per identity, re-resolved against this corpus's catalogue.
     *
     * @return array{membership: array<string, array<string, mixed>>, binding: array<string, mixed>}
     */
    private function hymnMembership(string $path, SongTitleResolver $resolver): array
    {
        $artifact = $this->readJsonArtifact($path, 'hymn reconciliation');
        $statements = $artifact['statements'] ?? null;

        if (! is_array($statements)) {
            throw new RuntimeException('The hymn reconciliation artifact carries no statements.');
        }

        $membership = [];
        $serviceKnown = 0;
        $resolvedHere = 0;
        $cache = [];

        foreach ($statements as $statement) {
            if (! is_array($statement)) {
                continue;
            }

            $service = $statement['reported_service'] ?? null;
            $usedOn = $statement['used_on'] ?? null;
            $title = $statement['reported_title'] ?? null;

            if (! is_string($service) || $service === '' || ! is_string($usedOn) || ! is_string($title)) {
                continue;
            }

            $serviceKnown++;
            $key = $this->identityKey($usedOn, $service);
            $number = $statement['reported_number'] ?? null;
            $cacheKey = $title."\0".(is_string($number) ? $number : '');

            if (! array_key_exists($cacheKey, $cache)) {
                $cache[$cacheKey] = $this->titleMatcher->match($resolver, $title, is_string($number) ? $number : null);
            }

            $match = $cache[$cacheKey];

            $membership[$key] ??= ['statements' => 0, 'song_ids' => [], 'unresolved_titles' => []];
            $membership[$key]['statements']++;

            if ($match === null) {
                $membership[$key]['unresolved_titles'][] = $title;

                continue;
            }

            $resolvedHere++;

            if (! in_array($match->songId, $membership[$key]['song_ids'], true)) {
                $membership[$key]['song_ids'][] = $match->songId;
            }
        }

        foreach ($membership as &$identity) {
            sort($identity['song_ids']);
        }
        unset($identity);

        $catalogue = $artifact['catalogue'] ?? [];

        return [
            'membership' => $membership,
            'binding' => [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
                'statements_sha256' => $artifact['statements_sha256'] ?? null,
                'generated_at' => $artifact['generated_at'] ?? null,
                'generated_against_catalogue_fingerprint' => is_array($catalogue) ? ($catalogue['fingerprint'] ?? null) : null,
                'service_known_statements' => $serviceKnown,
                'identities' => count($membership),
                'statements_resolved_against_this_corpus' => $resolvedHere,
            ],
        ];
    }

    /**
     * Item count and sequence per identity, from the approved OpenLP corpus.
     *
     * The manifest plan is taken rather than the manifest file: it re-hashes every archive
     * against its approved entry, so a parse here is bound to the file the curation approved
     * rather than to whatever now sits at that path.
     *
     * @return array{evidence: array<string, array<string, mixed>>, binding: array<string, mixed>}
     */
    private function openLpEvidence(string $manifestPath, string $rawDirectory, SongTitleResolver $resolver): array
    {
        $plan = $this->openLpManifest->plan($rawDirectory, $manifestPath);
        $evidence = [];
        $unreadable = [];

        foreach ($plan->includes as $entry) {
            $key = $this->identityKey($entry['resolved_date'], $entry['resolved_service']);

            try {
                $path = $this->openLpManifest->verifyInclude($rawDirectory, $entry);
                $parsed = $this->parser->parse(new UploadedFile(
                    path: $path,
                    originalName: $entry['logical_upload_filename'],
                    mimeType: 'application/zip',
                    test: true,
                ));
            } catch (Throwable $throwable) {
                $unreadable[] = [
                    'item_key' => $entry['item_key'],
                    'reason' => $throwable->getMessage(),
                ];

                continue;
            }

            [$songTitles, $songSequence] = $this->openLpSongSequence($parsed->items, $resolver);

            $evidence[$key] = [
                'item_key' => $entry['item_key'],
                'sha256' => $entry['sha256'],
                'expected_item_count' => $entry['expected_item_count'],
                'parsed_item_count' => count($parsed->items),
                'type_sequence' => array_map(static fn (array $item): string => (string) $item['type'], $parsed->items),
                'song_titles' => $songTitles,
                'song_sequence' => $songSequence,
            ];
        }

        return [
            'evidence' => $evidence,
            'binding' => [
                'manifest_path' => $manifestPath,
                'manifest_hash' => $plan->manifestHash,
                'plan_hash' => $plan->planHash,
                'batch_key' => $plan->batchKey,
                'raw_directory' => $rawDirectory,
                'included_entries' => count($plan->includes),
                'identities' => count($evidence),
                'unreadable' => $unreadable,
            ],
        ];
    }

    /**
     * The archive's songs in service order, as recorded titles and as catalogue ids.
     *
     * Both are kept. Ids are what the staged side can be compared against; the titles are what
     * a reader needs when the comparison comes back `indeterminate` because one side would not
     * resolve, which is a fact about the catalogue rather than about the order.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: list<string>, 1: list<int|null>}
     */
    private function openLpSongSequence(array $items, SongTitleResolver $resolver): array
    {
        $titles = [];
        $songIds = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'songs') {
                continue;
            }

            $title = trim((string) ($item['openlp_search_title'] ?? $item['title'] ?? ''));
            $titles[] = $title;
            $songIds[] = $title === '' ? null : $resolver->resolve($title)?->songId;
        }

        return [$titles, $songIds];
    }

    /**
     * @param  array<string, mixed>  $staged
     * @param  array<string, mixed>|null  $hymn
     * @param  array<string, mixed>|null  $openLp
     * @return array<string, mixed>
     */
    private function identityRecord(array $staged, ?array $hymn, ?array $openLp): array
    {
        $membership = $this->membershipComparison($staged, $hymn);
        $songCount = $this->songCountComparison($staged, $openLp);
        $songOrder = $this->songOrderComparison($staged, $openLp);

        $corroboratedBy = [];

        if ($hymn !== null) {
            $corroboratedBy[] = 'hymn_workbook';
        }

        if ($openLp !== null) {
            $corroboratedBy[] = 'openlp';
        }

        return [
            'date' => $staged['date'],
            'service' => $staged['service'],
            'staged' => $staged,
            'corroborated_by' => $corroboratedBy,
            'hymn_workbook' => $hymn === null ? null : [
                'statements' => $hymn['statements'],
                'song_ids' => $hymn['song_ids'],
                'unresolved_titles' => $hymn['unresolved_titles'],
                'membership' => $membership['detail'],
            ],
            'openlp' => $openLp === null ? null : [
                'item_key' => $openLp['item_key'],
                'expected_item_count' => $openLp['expected_item_count'],
                'parsed_item_count' => $openLp['parsed_item_count'],
                'song_count' => $songCount['detail'],
                'song_order' => $songOrder['detail'],
                'order_shape' => $this->orderShape($staged, $openLp),
            ],
            'verdicts' => [
                'song_membership' => $membership['verdict'],
                'song_count' => $songCount['verdict'],
                'song_order' => $songOrder['verdict'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $staged
     * @param  array<string, mixed>|null  $hymn
     * @return array{verdict: string, detail: array<string, mixed>|null}
     */
    private function membershipComparison(array $staged, ?array $hymn): array
    {
        if ($hymn === null) {
            return ['verdict' => self::VerdictNotCorroborated, 'detail' => null];
        }

        /** @var list<int> $stagedIds */
        $stagedIds = $staged['song_ids'];
        /** @var list<int> $workbookIds */
        $workbookIds = $hymn['song_ids'];

        $matched = array_values(array_intersect($workbookIds, $stagedIds));
        $missing = array_values(array_diff($workbookIds, $stagedIds));
        $extra = array_values(array_diff($stagedIds, $workbookIds));

        $detail = [
            'matched' => count($matched),
            'missing_from_staged' => $missing,
            'extra_in_staged' => $extra,
            /**
             * A title neither side could resolve is not a disagreement about the service; it is
             * a gap in the catalogue or a mangled title. Recorded so a mismatch below can be
             * read against how much of the identity was even comparable.
             */
            'unresolved_either_side' => count($hymn['unresolved_titles']) + count($staged['unresolved_song_titles']),
        ];

        if ($workbookIds === [] || ($stagedIds === [] && $staged['song_item_count'] === 0)) {
            return ['verdict' => self::VerdictIndeterminate, 'detail' => $detail];
        }

        return [
            'verdict' => $missing === [] && $extra === [] ? self::VerdictMatch : self::VerdictMismatch,
            'detail' => $detail,
        ];
    }

    /**
     * How many songs each source records.
     *
     * Restricted to songs on purpose. The whole-order counts are *not* comparable: an OpenLP
     * archive is a projection deck and carries `presentations`, `images` and `media` items that
     * no order of service lists, while an email order lists spoken items — notices, prayers, the
     * welcome — that never reach a slide. Measured over this corpus the whole-order comparison
     * agrees on 1 identity out of 222, which measures the difference between a deck and an order,
     * not the quality of an extraction. Songs are the one item class both sources genuinely carry,
     * because a song has to be projected to be sung. The whole-order figures are still recorded,
     * under `order_shape`, as context that carries no verdict.
     *
     * @param  array<string, mixed>  $staged
     * @param  array<string, mixed>|null  $openLp
     * @return array{verdict: string, detail: array<string, mixed>|null}
     */
    private function songCountComparison(array $staged, ?array $openLp): array
    {
        if ($openLp === null) {
            return ['verdict' => self::VerdictNotCorroborated, 'detail' => null];
        }

        if ($this->stagedCameFrom($staged, 'openlp')) {
            return ['verdict' => self::VerdictCircular, 'detail' => null];
        }

        $stagedSongs = count($staged['song_sequence']);
        $openLpSongs = count($openLp['song_sequence']);

        $detail = [
            'staged' => $stagedSongs,
            'openlp' => $openLpSongs,
            'difference' => $stagedSongs - $openLpSongs,
        ];

        if ($stagedSongs === 0 || $openLpSongs === 0) {
            return ['verdict' => self::VerdictIndeterminate, 'detail' => $detail];
        }

        return [
            'verdict' => $detail['difference'] === 0 ? self::VerdictMatch : self::VerdictMismatch,
            'detail' => $detail,
        ];
    }

    /**
     * The shape of each side's order, recorded and never scored.
     *
     * See {@see songCountComparison} for why a verdict here would be meaningless. This block
     * exists so the asymmetry stays visible in the artifact rather than being rediscovered — and
     * so a later reader can see exactly which item classes each source contributes.
     *
     * @param  array<string, mixed>  $staged
     * @param  array<string, mixed>|null  $openLp
     * @return array<string, mixed>|null
     */
    private function orderShape(array $staged, ?array $openLp): ?array
    {
        if ($openLp === null) {
            return null;
        }

        /** @var list<string> $stagedTypes */
        $stagedTypes = $staged['type_sequence'];
        /** @var list<string> $openLpTypes */
        $openLpTypes = $openLp['type_sequence'];

        return [
            'staged_item_count' => count($stagedTypes),
            'openlp_item_count' => $openLp['expected_item_count'],
            'staged_type_sequence' => $stagedTypes,
            'openlp_type_sequence' => $openLpTypes,
            'type_sequences_identical' => $stagedTypes === $openLpTypes,
            'song_positions' => [
                'staged' => $this->songPositions($stagedTypes),
                'openlp' => $this->songPositions($openLpTypes),
            ],
        ];
    }

    /**
     * Song order — the one thing only OpenLP can prove, and the reason `item_type_or_order` being
     * the largest attempt-disagreement category was until now unresolvable into "type" and "order".
     *
     * A position either side could not resolve makes the comparison indeterminate rather than a
     * mismatch. Scoring an unresolved title as disagreement would report a catalogue gap as an
     * ordering defect.
     *
     * @param  array<string, mixed>  $staged
     * @param  array<string, mixed>|null  $openLp
     * @return array{verdict: string, detail: array<string, mixed>|null}
     */
    private function songOrderComparison(array $staged, ?array $openLp): array
    {
        if ($openLp === null) {
            return ['verdict' => self::VerdictNotCorroborated, 'detail' => null];
        }

        if ($this->stagedCameFrom($staged, 'openlp')) {
            return ['verdict' => self::VerdictCircular, 'detail' => null];
        }

        /** @var list<int|null> $stagedSongs */
        $stagedSongs = $staged['song_sequence'];
        /** @var list<int|null> $openLpSongs */
        $openLpSongs = $openLp['song_sequence'];

        $detail = [
            'staged' => $stagedSongs,
            'openlp' => $openLpSongs,
            'openlp_titles' => $openLp['song_titles'],
        ];

        $unresolved = in_array(null, $stagedSongs, true) || in_array(null, $openLpSongs, true);

        if ($stagedSongs === [] || $openLpSongs === [] || $unresolved) {
            return ['verdict' => self::VerdictIndeterminate, 'detail' => $detail];
        }

        return [
            'verdict' => $stagedSongs === $openLpSongs ? self::VerdictMatch : self::VerdictMismatch,
            'detail' => $detail,
        ];
    }

    /**
     * Where the songs sit in an order, which is the part of sequence the extraction most often
     * gets wrong and the part a reader of a mismatch first wants to see.
     *
     * @param  list<string>  $types
     * @return list<int>
     */
    private function songPositions(array $types): array
    {
        $positions = [];

        foreach ($types as $index => $type) {
            if ($type === 'songs') {
                $positions[] = $index + 1;
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $staged
     */
    private function stagedCameFrom(array $staged, string $source): bool
    {
        /** @var list<string> $sources */
        $sources = $staged['item_sources'];

        return in_array($source, $sources, true);
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return array<string, mixed>
     */
    private function corpusBinding(SongTitleResolver $resolver, array $staged): array
    {
        $songIds = DB::table('songs')->orderBy('id')->pluck('id')->all();
        $itemCount = array_sum(array_map(static fn (array $service): int => $service['item_count'], $staged));

        return [
            'connection' => DB::getDefaultConnection(),
            'database' => DB::connection()->getDatabaseName(),
            'service_count' => count($staged),
            'item_count' => $itemCount,
            'catalogue_song_count' => count($songIds),
            'catalogue_fingerprint' => CanonicalJson::hash(array_map(
                static fn (mixed $id): array => ['id' => (int) $id, 'title' => (string) $resolver->catalogueTitle((int) $id)],
                $songIds,
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $identities
     * @return array<string, mixed>
     */
    private function counts(array $identities): array
    {
        $bySource = ['hymn_workbook_only' => 0, 'openlp_only' => 0, 'both' => 0, 'none' => 0];
        $verdicts = ['song_membership' => [], 'song_count' => [], 'song_order' => []];
        $uncorroboratedByYear = [];
        $songItems = ['total' => 0, 'resolved' => 0, 'unresolved' => 0];
        $mismatchShape = [
            'total' => 0,
            'missing_from_staged_only' => 0,
            'extra_in_staged_only' => 0,
            'both_directions' => 0,
            'explainable_by_an_unresolved_title' => 0,
        ];

        foreach ($identities as $identity) {
            $sources = $identity['corroborated_by'];
            $key = match (true) {
                $sources === ['hymn_workbook', 'openlp'] => 'both',
                $sources === ['hymn_workbook'] => 'hymn_workbook_only',
                $sources === ['openlp'] => 'openlp_only',
                default => 'none',
            };
            $bySource[$key]++;

            if ($key === 'none') {
                $year = substr((string) $identity['date'], 0, 4);
                $uncorroboratedByYear[$year] = ($uncorroboratedByYear[$year] ?? 0) + 1;
            }

            foreach ($identity['verdicts'] as $dimension => $verdict) {
                $verdicts[$dimension][$verdict] = ($verdicts[$dimension][$verdict] ?? 0) + 1;
            }

            $songItems['total'] += $identity['staged']['song_item_count'];
            $songItems['unresolved'] += count($identity['staged']['unresolved_song_titles']);

            if ($identity['verdicts']['song_membership'] === self::VerdictMismatch) {
                $this->tallyMismatchShape($mismatchShape, $identity['hymn_workbook']['membership']);
            }
        }

        $songItems['resolved'] = $songItems['total'] - $songItems['unresolved'];
        ksort($uncorroboratedByYear);

        foreach ($verdicts as &$dimension) {
            ksort($dimension);
        }
        unset($dimension);

        return [
            'staged_identities' => count($identities),
            'corroborated_identities' => count($identities) - $bySource['none'],
            'by_source' => $bySource,
            'uncorroborated_by_year' => $uncorroboratedByYear,
            'verdicts' => $verdicts,
            'staged_song_items' => $songItems,
            'song_membership_mismatches' => $mismatchShape,
        ];
    }

    /**
     * Which way a membership disagreement runs, and whether a title nobody could resolve is
     * enough to account for it.
     *
     * A bare mismatch rate would be read as an extraction error rate. It is not: a disagreement
     * where one side carried a title the catalogue could not resolve may be a catalogue gap or a
     * mangled title rather than a missing song, and that population is the one item 0(4)'s
     * title-hygiene work acts on.
     *
     * @param  array<string, int>  $shape
     * @param  array<string, mixed>  $membership
     */
    private function tallyMismatchShape(array &$shape, array $membership): void
    {
        $missing = count($membership['missing_from_staged']);
        $extra = count($membership['extra_in_staged']);

        $shape['total']++;

        $shape[match (true) {
            $extra === 0 => 'missing_from_staged_only',
            $missing === 0 => 'extra_in_staged_only',
            default => 'both_directions',
        }]++;

        if ($membership['unresolved_either_side'] > 0) {
            $shape['explainable_by_an_unresolved_title']++;
        }
    }

    /**
     * Staged song titles the catalogue could not resolve, most frequent first.
     *
     * This is the raw material for the title-hygiene check (item 0(4)): a title truncated
     * mid-word with surrounding prose bled into it fails to resolve here, and nothing else in
     * the report counts it.
     *
     * @param  list<array<string, mixed>>  $identities
     * @return array<string, int>
     */
    private function unresolvedStagedTitles(array $identities): array
    {
        $titles = [];

        foreach ($identities as $identity) {
            foreach ($identity['staged']['unresolved_song_titles'] as $title) {
                $titles[$title] = ($titles[$title] ?? 0) + 1;
            }
        }

        arsort($titles);

        return $titles;
    }

    /**
     * The unresolved population split by who can act on it, with the recovery a resolver fix would
     * win (item 0(4)).
     *
     * This is what stops `song_membership` mismatches being read as extraction errors. A mismatch
     * whose staged side carried an unresolved title is only an extraction fault when that title is
     * `defective`; when it is `decorated` the extraction was right and the resolver missed it, and
     * when it is `not_a_title` or `clean` no parser change reaches it at all.
     *
     * `recovered_by_normalisation` is measured, not asserted: each normalised title is re-probed
     * against this same corpus's catalogue, so a title only counts when it genuinely resolves.
     * Titles are counted by occurrence, matching `unresolved_staged_song_titles`' own unit.
     *
     * @param  array<string, int>  $unresolvedTitles
     * @return array<string, mixed>
     */
    private function titleHygieneCensus(array $unresolvedTitles, SongTitleResolver $resolver): array
    {
        $byVerdict = array_fill_keys(SongTitleHygieneVerdict::values(), 0);
        $byDefect = [];
        $recovered = 0;
        $recoveredExamples = [];

        foreach ($unresolvedTitles as $title => $occurrences) {
            $report = $this->titleHygiene->inspect((string) $title);

            $byVerdict[$report->verdict->value] += $occurrences;

            foreach ($report->defectValues() as $defect) {
                $byDefect[$defect] = ($byDefect[$defect] ?? 0) + $occurrences;
            }

            if (! $report->isNormalised()) {
                continue;
            }

            $match = $resolver->resolve($report->normalised);

            if ($match === null) {
                continue;
            }

            $recovered += $occurrences;
            $recoveredExamples[(string) $title] = [
                'normalised' => $report->normalised,
                'catalogue_title' => $resolver->catalogueTitle($match->songId),
                'match_type' => $match->matchType,
                'occurrences' => $occurrences,
                'defects' => $report->defectValues(),
            ];
        }

        arsort($byDefect);

        return [
            'unresolved_occurrences' => array_sum($unresolvedTitles),
            'distinct_titles' => count($unresolvedTitles),
            'by_verdict' => $byVerdict,
            'by_defect' => $byDefect,
            'recovered_by_normalisation' => $recovered,
            'recovered_examples' => array_slice($recoveredExamples, 0, 40, preserve_keys: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonArtifact(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The {$label} artifact does not exist: {$path}");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read the {$label} artifact.");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} artifact is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("The {$label} artifact must be a JSON object.");
        }

        return $decoded;
    }

    private function identityKey(string $date, string $service): string
    {
        return $date.'|'.$service;
    }
}
