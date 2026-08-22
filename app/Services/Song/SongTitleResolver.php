<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\SongTitleMatch;
use App\Models\Song;

/**
 * Resolves a free-text song reference (an OoS email line, an OpenLP search title, an admin-typed
 * title) to a catalogued song. Deterministic rungs run first over a series of progressively
 * cleaned probes; a guarded fuzzy comparison is the last resort. Every lookup map drops keys two
 * different songs share — the resolver never guesses between candidates it cannot separate.
 */
class SongTitleResolver
{
    public const DEFAULT_FUZZY_THRESHOLD = 0.90;

    /**
     * Score awarded when one side is a word-boundary prefix of the other ("how deep the
     * fathers love" ⊂ "how deep the fathers love for us"). Email titles are routinely
     * truncated this way; corpus review showed containment pairs are reliably the same song
     * while sub-threshold similar_text pairs without containment are reliably different.
     */
    private const CONTAINMENT_SCORE = 0.98;

    public const DEFAULT_FUZZY_MARGIN = 0.05;

    public const DEFAULT_FUZZY_MIN_PROBE_LENGTH = 10;

    /** @var array<string, int> */
    private array $exact = [];

    /**
     * The catalogue's own title per song, so a caller that has resolved a match can name
     * the song without a second query — the rows the lookups are built from carry it already.
     *
     * @var array<int, string>
     */
    private array $titles = [];

    /** @var array<string, int> */
    private array $number = [];

    /** @var array<string, int> */
    private array $stripped = [];

    /** @var array<string, int> */
    private array $looseTitle = [];

    /** @var array<string, int> */
    private array $looseAlternate = [];

    /** @var array<string, int> */
    private array $looseFirstLine = [];

    /**
     * Loose keys of songs the hymnbook does not number, so a NIP ("not in Praise!") line can
     * be steered to them ahead of an identically titled numbered song. Ambiguous keys are
     * dropped, as everywhere else — the resolver never guesses between candidates.
     *
     * @var array<string, int>
     */
    private array $hymnbookAbsent = [];

    /**
     * Every loose key of every song, ambiguous ones included — fuzzy scoring ranks
     * candidates and applies its own margin guard, so ambiguity-dropping is not needed here.
     *
     * @var list<array{0:int, 1:string}>
     */
    private array $fuzzyCandidates = [];

    private function __construct(
        private bool $fuzzyEnabled,
        private float $fuzzyThreshold,
        private float $fuzzyMargin,
        private int $fuzzyMinProbeLength,
    ) {}

    public static function fromDatabase(): self
    {
        $rows = Song::query()
            ->get(['id', 'canonical_key', 'title', 'praise_number', 'alternate_title', 'first_line_key'])
            ->map(fn (Song $song): array => [
                'id' => (int) $song->id,
                'canonical_key' => (string) $song->canonical_key,
                'title' => (string) $song->title,
                'praise_number' => $song->praise_number,
                'alternate_title' => $song->alternate_title,
                'first_line_key' => $song->first_line_key,
            ])
            ->all();

        return self::fromRows($rows, [
            'fuzzy_enabled' => (bool) config('service-tracking.song_linking.fuzzy_enabled', true),
            'fuzzy_threshold' => (float) config('service-tracking.song_linking.fuzzy_threshold', self::DEFAULT_FUZZY_THRESHOLD),
            'fuzzy_margin' => (float) config('service-tracking.song_linking.fuzzy_margin', self::DEFAULT_FUZZY_MARGIN),
            'fuzzy_min_probe_length' => (int) config('service-tracking.song_linking.fuzzy_min_probe_length', self::DEFAULT_FUZZY_MIN_PROBE_LENGTH),
        ]);
    }

    /**
     * Build a resolver from plain rows, without touching the database or the container —
     * the archive evaluator's pure unit tests construct it this way.
     *
     * @param  iterable<array{id: int, canonical_key: string, title?: ?string, praise_number?: ?string, alternate_title?: ?string, first_line_key?: ?string}>  $rows
     * @param  array{fuzzy_enabled?: bool, fuzzy_threshold?: float, fuzzy_margin?: float, fuzzy_min_probe_length?: int}  $options
     */
    public static function fromRows(iterable $rows, array $options = []): self
    {
        $resolver = new self(
            fuzzyEnabled: $options['fuzzy_enabled'] ?? true,
            fuzzyThreshold: $options['fuzzy_threshold'] ?? self::DEFAULT_FUZZY_THRESHOLD,
            fuzzyMargin: $options['fuzzy_margin'] ?? self::DEFAULT_FUZZY_MARGIN,
            fuzzyMinProbeLength: $options['fuzzy_min_probe_length'] ?? self::DEFAULT_FUZZY_MIN_PROBE_LENGTH,
        );

        $resolver->buildLookups($rows);

        return $resolver;
    }

    public function resolve(string $searchTitle): ?SongTitleMatch
    {
        [$probes, $fuzzyProbe] = $this->buildProbes($searchTitle);

        $hymnbookAbsent = $this->hymnbookAbsentMatch($searchTitle, $probes);
        if ($hymnbookAbsent instanceof SongTitleMatch) {
            return $hymnbookAbsent;
        }

        foreach ($probes as $probe) {
            $match = $this->deterministicMatch($probe);
            if ($match !== null) {
                return $match;
            }
        }

        return $this->fuzzyMatch($fuzzyProbe, $searchTitle);
    }

    /**
     * The catalogue's title for a song, or null when it is not in this resolver's rows.
     */
    public function catalogueTitle(int $songId): ?string
    {
        return $this->titles[$songId] ?? null;
    }

    /**
     * The cleaned text a free-text reference reduces to — leading labels ("NIP"), praise
     * numbers, quotes and trailing parentheticals removed. Callers that run their own
     * search (an admin typeahead's LIKE, say) use this so they query the same text the
     * resolver matches on rather than the raw, decorated line.
     */
    public function searchProbe(string $searchTitle): string
    {
        [, $fuzzyProbe] = $this->buildProbes($searchTitle);

        return $fuzzyProbe;
    }

    /**
     * @param  iterable<array{id: int, canonical_key: string, title?: ?string, praise_number?: ?string, alternate_title?: ?string, first_line_key?: ?string}>  $rows
     */
    private function buildLookups(iterable $rows): void
    {
        $ambiguousStripped = [];
        $ambiguousHymnbookAbsent = [];
        $ambiguousTitle = [];
        $ambiguousAlternate = [];
        $ambiguousFirstLine = [];

        foreach ($rows as $row) {
            $songId = (int) $row['id'];
            $canonicalKey = (string) $row['canonical_key'];

            $this->exact[$canonicalKey] = $songId;

            $catalogueTitle = $row['title'] ?? null;
            if (is_string($catalogueTitle) && trim($catalogueTitle) !== '') {
                $this->titles[$songId] = trim($catalogueTitle);
            }

            $praiseNumber = $row['praise_number'] ?? null;
            if (is_string($praiseNumber) && trim($praiseNumber) !== '') {
                $this->number[Song::normalisePraiseNumber($praiseNumber)] = $songId;
            }

            $strippedKey = self::stripTrailingNumber($canonicalKey);
            if ($strippedKey !== '' && $strippedKey !== $canonicalKey) {
                $this->addLookupKey($this->stripped, $ambiguousStripped, $strippedKey, $songId);
            }

            $numbered = is_string($row['praise_number'] ?? null) && trim((string) $row['praise_number']) !== '';

            foreach ($this->looseTitleKeys($row) as $key) {
                $this->addLookupKey($this->looseTitle, $ambiguousTitle, $key, $songId);
                $this->fuzzyCandidates[] = [$songId, $key];

                if (! $numbered) {
                    $this->addLookupKey($this->hymnbookAbsent, $ambiguousHymnbookAbsent, $key, $songId);
                }
            }

            foreach ($this->looseAlternateKeys($row) as $key) {
                $this->addLookupKey($this->looseAlternate, $ambiguousAlternate, $key, $songId);
                $this->fuzzyCandidates[] = [$songId, $key];
            }

            $firstLineKey = Song::matchKey((string) ($row['first_line_key'] ?? ''));
            if ($firstLineKey !== '') {
                $this->addLookupKey($this->looseFirstLine, $ambiguousFirstLine, $firstLineKey, $songId);
                $this->fuzzyCandidates[] = [$songId, $firstLineKey];
            }
        }

        $this->stripped = array_diff_key($this->stripped, $ambiguousStripped);
        $this->hymnbookAbsent = array_diff_key($this->hymnbookAbsent, $ambiguousHymnbookAbsent);
        $this->looseTitle = array_diff_key($this->looseTitle, $ambiguousTitle);
        $this->looseAlternate = array_diff_key($this->looseAlternate, $ambiguousAlternate);
        $this->looseFirstLine = array_diff_key($this->looseFirstLine, $ambiguousFirstLine);
    }

    /**
     * @param  array{canonical_key: string, title?: ?string}  $row
     * @return list<string>
     */
    private function looseTitleKeys(array $row): array
    {
        $keys = [
            Song::matchKey($row['canonical_key']),
            Song::matchKey(self::stripTrailingNumber($row['canonical_key'])),
            Song::matchKey((string) ($row['title'] ?? '')),
        ];

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    /**
     * Alternate titles are decorated with Praise! numbers on either end ("#511 Lo He Comes…",
     * "…Descending #511") — index both the raw and the number-stripped forms.
     *
     * @param  array{alternate_title?: ?string}  $row
     * @return list<string>
     */
    private function looseAlternateKeys(array $row): array
    {
        $alternate = $row['alternate_title'] ?? null;
        if (! is_string($alternate) || trim($alternate) === '') {
            return [];
        }

        $undecorated = (string) preg_replace('/^\s*#?\d{1,4}[a-z]?\s+/iu', '', $alternate);
        $undecorated = (string) preg_replace('/\s+#?\d{1,4}[a-z]?\s*$/iu', '', $undecorated);

        $keys = [Song::matchKey($alternate), Song::matchKey($undecorated)];

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<string, true>  $ambiguousKeys
     */
    private function addLookupKey(array &$map, array &$ambiguousKeys, string $key, int $songId): void
    {
        if (isset($map[$key]) && $map[$key] !== $songId) {
            $ambiguousKeys[$key] = true;
        }

        $map[$key] = $songId;
    }

    /**
     * Progressively cleaned probes, each tried against every deterministic rung before the
     * next probe. Returned alongside the fully cleaned string the fuzzy stage should score.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function buildProbes(string $searchTitle): array
    {
        $probes = [];
        $push = function (string $probe) use (&$probes): void {
            $probe = trim($probe);
            if ($probe !== '' && ! in_array($probe, $probes, true)) {
                $probes[] = $probe;
            }
        };

        // Every probe is also offered with its shorthand written out, immediately after itself,
        // so a later cleaning stage cannot be the only one that reaches the expansion.
        $add = function (string $probe) use ($push): void {
            $push($probe);
            $push(self::expandWrittenShorthand($probe));
        };

        $raw = trim($searchTitle);
        $add($raw);

        // OpenLP search titles carry alternate search text after "@" — canonicalizeKey truncates
        // it away, so probe each extra segment explicitly.
        foreach (array_slice(explode('@', $raw), 1) as $segment) {
            $add($segment);
        }

        // The parser occasionally retains order-of-service presentation syntax before an item
        // label ("[3m] - Hymn 299"). Remove it before the label rung, but keep the raw title as
        // probe zero so this enrichment cannot override a match that already resolves.
        $presentationStripped = self::stripLeadingPresentationDecoration($raw);
        $add($presentationStripped);

        $prefixStripped = self::stripLeadingLabel($presentationStripped);
        $add($prefixStripped);

        $decorationStripped = self::stripEmailDecoration($prefixStripped);
        $add($decorationStripped);

        $withoutParenthetical = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $decorationStripped));
        $add($withoutParenthetical);

        if (self::assertsHymnbookAbsence($raw)) {
            $marklessLabelled = self::stripHymnbookAbsenceMarker($presentationStripped);
            $add(self::stripLeadingLabel($marklessLabelled));

            // "Song In Christ Alone (NIP)" writes the role word with no punctuation after it, so
            // the label rung cannot see it. Removing a bare role word is only safe because the
            // marker has already narrowed the answer to a song the hymnbook does not number: a
            // title that genuinely opens with "Song" or "Hymn" has to also be unnumbered and to
            // match the remainder exactly before this can mislead.
            $add((string) preg_replace('/^\s*(?:songs?|sing|hymns?|carols?)\s+/iu', '', $marklessLabelled));
        }

        // Read the quotes off the raw line: stripEmailDecoration() trims a leading or trailing
        // quote mark, which would leave the run's other half unpaired.
        foreach (self::quotedRuns($raw) as $quoted) {
            $add($quoted);
        }

        // A trailing parenthetical is sometimes the song's alternate title ("(comfort and joy)")
        // — probe its content through the deterministic rungs only, skipping performance notes.
        if (preg_match('/\(([^)]+)\)\s*$/u', $decorationStripped, $matches) === 1) {
            $content = trim($matches[1]);
            if ($content !== '' && preg_match('/^(?:no bridge|adapted|.*version|part \d+|v(?:erses?|v)?\.? ?\d.*)$/i', $content) !== 1) {
                $add($content);
            }
        }

        // A dash-separated tail is routinely an attribution or a scheduling note rather than
        // part of the title — "Creator God - Ben Slee", "- NIP Creator God - see below". Probe
        // the head as well, last, so a title that resolves whole always wins first.
        foreach (preg_split('/\s+[\-\x{2013}\x{2014}]\s+/u', $withoutParenthetical, 2) ?: [] as $segment) {
            $add($segment);
        }

        // A parenthesised book number is a hymn reference and cannot be a list position — the
        // "#" or the book's own name says so ("Song 1 - Come thou fount of every blessing
        // (#894)"). It is probed last, so a title the line also carries still wins first, and
        // the marker must open the parenthetical: "(Crockenhill Praise 47)" names a different
        // book and its number is not a Praise! number.
        if (preg_match('/\((?:#|praise!?\s*|p)\s*(\d{1,4}[a-z]?)(?![\d,.])/iu', $raw, $bookNumber) === 1) {
            $add($bookNumber[1]);
        }

        return [$probes, $withoutParenthetical !== '' ? $withoutParenthetical : $raw];
    }

    /**
     * The one quoted run inside a line, or none. Scheduling prose wraps the title rather
     * than replacing it — "final hymn for evening – 313 'let us love and sing and wonder'",
     * "welcome sheet: ‘see what a morning’" — and the quotes are where the writer said the
     * title stops. Extracting them reaches the title without having to enumerate the prose.
     *
     * A closing quote may not be followed by a letter, so the apostrophe in "lord's my shepherd"
     * does not end the run early. Straight and curly marks are one class on both sides because
     * the corpus mixes them within a single reference ("“the only power that cleanses’").
     *
     * @return list<string>
     */
    private static function quotedRuns(string $title): array
    {
        if (preg_match_all('/[\x{2018}\x{201C}"\']\s*(.+?)\s*[\x{2019}\x{201D}"\'](?!\p{L})/u', $title, $matches) === false) {
            return [];
        }

        $runs = [];

        foreach ($matches[1] as $run) {
            $run = trim($run);
            if (mb_strlen($run) >= 4) {
                $runs[] = $run;
            }
        }

        $runs = array_values(array_unique($runs));

        // Two quoted runs mean the writer offered a choice ("either 'great is thy
        // faithfulness', 'amazing grace' or 'it is well with my soul'") or wrote two songs on
        // one line. Resolving the first would pick a winner the source never picked, so an
        // ambiguous line contributes nothing — the same refusal the lookup maps make.
        return count($runs) === 1 ? $runs : [];
    }

    /**
     * "NIP" is the projectionist's shorthand for *not in Praise!* — an assertion that the song
     * is absent from the hymnbook. It is therefore evidence about which catalogue row is meant,
     * not decoration: a NIP line naming "The Lord's my Shepherd" cannot be Whittingham's
     * Praise! 23B and must be Townend's setting, which is what an independent OpenLP archive
     * of the same service says. Without this the resolver silently manufactured that conflict.
     *
     * Only exact and word-boundary prefix matches count. Prefix carries real information —
     * an email writes "my hope is built" for the catalogue's "My hope is built on nothing
     * less" — whereas fuzzy resemblance does not: on this corpus it pairs "to God be the
     * glory" with "Thine Be The Glory" and "tell all the world of Jesus" with "Tell Me The
     * Stories Of Jesus", both different hymns whose numbered rows were already correct.
     *
     * A number written behind a NIP marker is a supplement number rather than a Praise! one
     * {@see self::leadingPraiseNumber()}, so it is deliberately not treated as a positive
     * signal that would override the marker.
     *
     * @param  list<string>  $probes
     */
    private function hymnbookAbsentMatch(string $searchTitle, array $probes): ?SongTitleMatch
    {
        if (! self::assertsHymnbookAbsence($searchTitle)) {
            return null;
        }

        foreach ($probes as $probe) {
            $strippedKey = self::stripEmailDecoration($probe);

            foreach ($this->looseKeysForProbe($probe, $strippedKey) as $key) {
                $songId = $this->hymnbookAbsent[$key] ?? null;
                if ($songId !== null) {
                    return new SongTitleMatch($songId, SongTitleMatch::TYPE_HYMNBOOK_ABSENT);
                }

                $songId = $this->hymnbookAbsentByPrefix($key);
                if ($songId !== null) {
                    return new SongTitleMatch($songId, SongTitleMatch::TYPE_HYMNBOOK_ABSENT, self::CONTAINMENT_SCORE);
                }
            }
        }

        return null;
    }

    /**
     * Whether a line carries the NIP marker at all. Operators write it wherever it reads
     * naturally — leading ("NIP 'On the cross'"), trailing ("Beneath the cross of Jesus NIP")
     * and parenthesised ("Song In Christ Alone (NIP)") all occur in the corpus — and the
     * assertion is the same in every position, so position must not decide whether it counts.
     * The word boundary keeps it a marker rather than a substring of a title.
     */
    private static function assertsHymnbookAbsence(string $title): bool
    {
        return preg_match('/(?<!\p{L})NIP(?!\p{L})/iu', $title) === 1;
    }

    /**
     * The line with a standalone NIP marker removed wherever it sits, so the title either side
     * of it becomes a probe. Leading markers are already handled by
     * {@see self::stripEmailDecoration()}; this reaches the trailing and parenthesised ones.
     */
    private static function stripHymnbookAbsenceMarker(string $title): string
    {
        $bare = (string) preg_replace('/\(\s*NIP\s*\)/iu', ' ', $title);
        $bare = (string) preg_replace('/(?<!\p{L})NIP(?!\p{L})/iu', ' ', $bare);

        return trim((string) preg_replace('/\s+/u', ' ', $bare));
    }

    /**
     * The single unnumbered song whose loose key extends this one at a word boundary, or null
     * when none does or more than one song would qualify.
     */
    private function hymnbookAbsentByPrefix(string $key): ?int
    {
        if ($key === '' || count(explode(' ', $key)) < 2) {
            return null;
        }

        $matches = [];

        foreach ($this->hymnbookAbsent as $candidate => $songId) {
            if (str_starts_with($candidate, $key.' ')) {
                $matches[$songId] = true;
            }
        }

        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    private function deterministicMatch(string $probe): ?SongTitleMatch
    {
        foreach (Song::matchKeyVariants($probe) as $canonicalKey) {
            if (isset($this->exact[$canonicalKey])) {
                return new SongTitleMatch($this->exact[$canonicalKey], SongTitleMatch::TYPE_EXACT);
            }
        }

        $leadingNumber = self::leadingPraiseNumber($probe);
        if ($leadingNumber !== null && isset($this->number[$leadingNumber])) {
            return new SongTitleMatch($this->number[$leadingNumber], SongTitleMatch::TYPE_PRAISE_NUMBER);
        }

        $strippedKey = self::stripEmailDecoration($probe);
        if ($strippedKey !== '') {
            $songId = $this->stripped[$strippedKey] ?? $this->exact[$strippedKey] ?? null;
            if ($songId !== null) {
                return new SongTitleMatch($songId, SongTitleMatch::TYPE_STRIPPED_NUMBER);
            }
        }

        $looseKeys = $this->looseKeysForProbe($probe, $strippedKey);

        foreach ([
            [$this->looseTitle, SongTitleMatch::TYPE_LOOSE_TITLE],
            [$this->looseAlternate, SongTitleMatch::TYPE_ALTERNATE_TITLE],
            [$this->looseFirstLine, SongTitleMatch::TYPE_FIRST_LINE],
        ] as [$map, $matchType]) {
            foreach ($looseKeys as $key) {
                if (isset($map[$key])) {
                    return new SongTitleMatch($map[$key], $matchType);
                }
            }
        }

        return null;
    }

    /**
     * Match keys (with O/Oh variants) for a probe and its decoration-stripped form.
     *
     * @return list<string>
     */
    private function looseKeysForProbe(string $probe, string $strippedKey): array
    {
        $keys = [];

        foreach ([Song::matchKey($probe), Song::matchKey($strippedKey)] as $matchKey) {
            foreach (Song::matchKeyVariants($matchKey) as $variant) {
                $keys[] = $variant;
            }
        }

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    private function fuzzyMatch(string $fuzzyProbe, string $searchTitle): ?SongTitleMatch
    {
        if (! $this->fuzzyEnabled) {
            return null;
        }

        $probe = Song::matchKey($fuzzyProbe);
        if ($probe === '') {
            $probe = Song::matchKey($searchTitle);
        }

        if (mb_strlen($probe) < $this->fuzzyMinProbeLength || count(explode(' ', $probe)) < 2) {
            return null;
        }

        /** @var array<int, float> $bestBySong */
        $bestBySong = [];
        foreach ($this->fuzzyCandidates as [$songId, $key]) {
            $score = $this->fuzzyScore($probe, $key);

            if ($score > ($bestBySong[$songId] ?? 0.0)) {
                $bestBySong[$songId] = $score;
            }
        }

        if ($bestBySong === []) {
            return null;
        }

        arsort($bestBySong);
        $scores = array_values($bestBySong);
        $songIds = array_keys($bestBySong);

        if ($scores[0] < $this->fuzzyThreshold) {
            return null;
        }

        if (isset($scores[1]) && ($scores[0] - $scores[1]) < $this->fuzzyMargin) {
            return null;
        }

        return new SongTitleMatch($songIds[0], SongTitleMatch::TYPE_FUZZY, round($scores[0], 4));
    }

    private function fuzzyScore(string $probe, string $key): float
    {
        if ($probe === $key) {
            return 1.0;
        }

        if (str_starts_with($key, $probe.' ') || str_starts_with($probe, $key.' ')) {
            return self::CONTAINMENT_SCORE;
        }

        similar_text($probe, $key, $percent);

        return $percent / 100;
    }

    /**
     * The Praise! number an email title leads with ("299 'How sweet…'", "Praise no 452:
     * Messiah dies"). A number running straight into more digits or punctuation
     * ("10,000 Reasons") is part of the title, and a number behind a NIP marker is a
     * supplement number — never a Praise! book number.
     */
    public static function leadingPraiseNumber(string $title): ?string
    {
        if (preg_match('/^\s*(?:Praise\b\s*(?:no\.?|number)?\s*)?(\d{1,4}[a-z]?)(?![\d,.])\b/iu', trim($title), $matches) === 1) {
            return Song::normalisePraiseNumber($matches[1]);
        }

        return null;
    }

    /**
     * Canonical key of an email title with its leading book-number decoration removed: an
     * optional NIP/Praise marker ("Praise no 548:" included), the number itself, and any
     * surrounding quotes. NIP entries are supplement songs without numbers, so the digits are
     * optional after that marker — but never after "Praise", which also opens real titles
     * ("Praise to the Lord…").
     */
    public static function stripEmailDecoration(string $title): string
    {
        $bare = (string) preg_replace(
            '/^\s*(?:NIP\b\s*(?:\d{1,4}[a-z]?(?![\d,.]))?|(?:Praise\b!?\s*(?:no\.?|number)?\s*)?\d{1,4}[a-z]?(?![\d,.]))\s*[:\-\x{2013}\x{2014}]?\s*/iu',
            '',
            $title,
        );
        $bare = trim($bare, " \t\"'\u{2018}\u{2019}\u{201C}\u{201D}");

        return Song::canonicalizeKey($bare);
    }

    /**
     * Writes out the shorthand a catalogue title spells in full. Two forms only, both of which
     * {@see Song::matchKey()} otherwise collapses to something that cannot match:
     *
     * - "&" becomes "and" — the email writes "Meekness & Majesty" for the catalogue's
     *   "Meekness and Majesty", and matchKey turns the ampersand into a space, not a word.
     * - A contraction becomes its two words — "there's a hope" for "There is a Hope".
     *
     * Only contractions that cannot also be possessives are expanded. "'s" is expanded after
     * the handful of words where it can only be a verb; expanding it generally would turn
     * "God's love" into "God is love" and hand a different catalogued song a false match.
     */
    private static function expandWrittenShorthand(string $title): string
    {
        $expanded = str_replace('&', ' and ', $title);

        $expanded = (string) preg_replace(
            [
                '/\bn[\x{2019}\']t\b/iu',
                '/(\p{L})[\x{2019}\']re\b/iu',
                '/(\p{L})[\x{2019}\']ve\b/iu',
                '/(\p{L})[\x{2019}\']ll\b/iu',
                '/(\p{L})[\x{2019}\']m\b/iu',
                '/\blet[\x{2019}\']s\b/iu',
                '/\b(there|here|that|what|it|who|he|she|god)[\x{2019}\']s\b/iu',
            ],
            [' not', '$1 are', '$1 have', '$1 will', '$1 am', 'let us', '$1 is'],
            $expanded,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $expanded));
    }

    /**
     * Canonical key of a library entry with its trailing hymn-number suffix removed
     * ("all heaven declares 477" → "all heaven declares").
     */
    public static function stripTrailingNumber(string $canonicalKey): string
    {
        return trim((string) preg_replace('/\s+\d{1,4}[a-z]?$/i', '', $canonicalKey));
    }

    /**
     * Strips a leading item label ("Communion song:", "Jade's song –", "Alternative final
     * song:", "Final hymn:", "Carol") and a leading "#" so the Praise!-number rung can see
     * through them.
     *
     * The role word is one of song/hymn/carol, and the qualifier words apply to all three. They
     * used to apply to `song` alone — `(?:(?:\p{L}+\s+){0,2}song|hymn)` groups as
     * `((?:\p{L}+\s+){0,2}song)|(hymn)` — so "Communion song –" stripped while "Communion hymn –"
     * and "Final hymn:" did not. That one grouping carried 125 of the 283 unresolved song titles
     * in the 2026-08-16 item ground truth.
     *
     * The colon/dash is still required, so a title that merely starts with the word "Song" is
     * left alone — except where the next character is a quote, a hash, a digit or the NIP
     * marker, none of which can open a title's second word, so each identifies the label
     * without risking a real one.
     *
     * A single digit after the role word is a **list position, not a hymn number**, and is
     * consumed with the label: "Song 1 - Oceans" is the service's first song, and leaving the
     * "1" to reach the Praise!-number rung linked seven such lines to *Happy The People Who
     * Refuse #1* and five more to *O Lord How Many Enemies #3*. Services number their songs in
     * single digits while the corpus writes every real Praise! reference with the book's
     * printed two- or three-digit form ("Song 187 'O God beyond all praising'"), so the digit
     * count separates the two without having to read the rest of the line. A number the writer
     * marked as a reference — "(#894)", "Praise no 618" — is read by its own rung and is
     * unaffected.
     */
    private static function stripLeadingLabel(string $title): string
    {
        $bare = (string) preg_replace(
            '/^\s*(?:\p{L}+(?:[\'\x{2019}]s)?\s+){0,2}(?:songs?|sing|hymns?|carols?)\s*(?:(?<index>\d(?![\d,.+]))\s*)?(?:[:.\-\x{2013}\x{2014}]+\s*|(?(index)\s*|(?=[#\d"\'\x{2018}\x{201C}]|NIP(?!\p{L}))))/iu',
            '',
            $title,
        );

        return ltrim(trim($bare), '#');
    }

    /**
     * Removes display-only order-of-service prefixes before the role-label rung runs.
     *
     * These are deliberately narrower than general punctuation stripping: a leading list marker
     * or a `[3m]` planning duration is not part of a song title, while a word following one can
     * be. The raw title remains the first probe, preserving an existing deterministic match.
     */
    private static function stripLeadingPresentationDecoration(string $title): string
    {
        $withoutDuration = (string) preg_replace('/^\s*\[\s*\d+\s*m\s*\]\s*/iu', '', $title);
        $withoutBullet = (string) preg_replace('/^\s*(?:[-*>\x{2022}\x{2013}\x{2014}]+\s+)+/u', '', $withoutDuration);

        // A leading "4. " enumerates the item; without removing it the Praise! reference behind
        // it ("4. Praise no 618 - Facing a task unfinished") never reaches the number rung and a
        // same-titled unnumbered song wins instead. Two digits and a following space at most, so
        // this cannot bite into a decimal or a thousands separator.
        $withoutEnumerator = (string) preg_replace('/^\s*\d{1,2}\.\s+/u', '', $withoutBullet);

        return trim($withoutEnumerator);
    }
}
