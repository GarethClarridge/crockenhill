<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\SongTitleHygieneReport;
use App\Enums\SongTitleDefect;
use App\Enums\SongTitleHygieneVerdict;

/**
 * Classifies the *shape* of an extracted song title, and strips the decoration a resolver ought to
 * see through (item 0(4) of the queued parser plan).
 *
 * Why this exists. `song_link.unmatched_titles` counts titles the catalogue could not resolve and
 * says nothing about why, so the figure reads as an extraction error rate and is not one. Measured
 * over the 283 unresolved staged song items in the 2026-08-16 item ground truth, the population
 * splits four ways with four different owners: 136 are a faithful title behind decoration the
 * resolver does not strip, 61 carry real extraction damage, 39 contain no title to extract at all,
 * and 47 are well-formed titles the catalogue genuinely lacks. Reporting one number for those four
 * would repeat the mistake item 0(2) caught, where a whole-order comparison that scored 1 in 222
 * measured the difference between a projection deck and an order rather than extraction quality.
 *
 * Why it is pure and shared. Both consumers — `OosArchiveEvaluator`'s per-entry song-link metrics
 * and `HistoricItemGroundTruth`'s unresolved-title census — classify with this one policy, for the
 * same reason `CatalogueTitleMatcher` was extracted: two consumers applying two slightly different
 * policies to one corpus produce two different censuses and no way to tell which is wrong.
 *
 * What normalisation is and is not. {@see normalise()} models what a *corrected* resolver would
 * strip; it does not change `SongTitleResolver`, which is live production code on the weekly
 * inbound-email path. Re-probing the resolver with the normalised title therefore sizes a resolver
 * fix rather than performing one, and when that fix lands the recovery count is its acceptance
 * figure — it should fall to approximately zero.
 *
 * Known limits, recorded so the census is not over-read. This is a shape classifier: it cannot see
 * that four consecutive well-formed "Chosen to be ..." items are sermon points rather than songs,
 * and it cannot distinguish a truncated line from the fragment that follows it any better than the
 * punctuation allows — both report as `Defective`, which is the verdict that matters, but the
 * sub-family split between `Truncated` and `LineFragment` is approximate. The families were
 * calibrated against one corpus and deliberately use structural signatures (unbalanced delimiters,
 * leading bullets, conversational pronouns) rather than corpus-specific vocabulary, so that the
 * measure does not flatter itself on the sample it was built from.
 */
final class SongTitleHygiene
{
    /**
     * UTF-8 curly punctuation decoded as Latin-1 upstream. The marker is the shared two-character
     * lead-in; the repair map below covers the sequences the corpus actually carries.
     */
    private const MOJIBAKE = '/\x{00E2}\x{20AC}|\x{00C2}[\x{00A0}\x{00AB}\x{00BB}]/u';

    /** @var array<string, string> */
    private const MOJIBAKE_REPAIRS = [
        "\u{00E2}\u{20AC}\u{02DC}" => "\u{2018}",
        "\u{00E2}\u{20AC}\u{2122}" => "\u{2019}",
        "\u{00E2}\u{20AC}\u{0153}" => "\u{201C}",
        "\u{00E2}\u{20AC}\u{009D}" => "\u{201D}",
        "\u{00E2}\u{20AC}\u{201C}" => "\u{2013}",
        "\u{00E2}\u{20AC}\u{201D}" => "\u{2014}",
        "\u{00E2}\u{20AC}\u{00A6}" => "\u{2026}",
        "\u{00C2}\u{00A0}" => ' ',
    ];

    /** A planning duration marker the order-of-service email carries in front of an item. */
    private const DURATION_PREFIX = '/^\s*\[\s*\d+\s*m\s*\]\s*/iu';

    /** A list bullet or a quoted-reply marker kept by the extraction. */
    private const BULLET_PREFIX = '/^\s*(?:[-*>\x{2022}\x{2013}\x{2014}]+\s+)+/u';

    /** Markdown emphasis, a markdown link, or a bare URL. */
    private const MARKUP_RESIDUE = '/\*[^*]+\*|\[[^\]]*\]\(|https?:\/\/|\bwww\./iu';

    /**
     * The item's role kept in front of its title.
     *
     * `SongTitleResolver::stripLeadingLabel()` recognises the same idea but its alternation binds
     * the optional qualifier words to `song` alone, so "Communion song -" strips while "Communion
     * hymn -" and "Final hymn:" do not, and "Carol" is not in its vocabulary at all. That single
     * grouping accounts for 128 of the 283 unresolved occurrences.
     *
     * The separator is required, exactly as the resolver requires it, so that a genuine title
     * opening with the word "Song" survives — unless what follows opens with a quote, a hash or a
     * digit, which cannot begin a title's second word and therefore disambiguates without risk.
     */
    private const ROLE_LABEL = '/^\s*(?:\p{L}+(?:[\'\x{2019}]s)?\s+){0,3}(?:songs?|hymns?|carols?)\s*(?:[:;.\-\x{2013}\x{2014}]+\s*|(?=[#\d"\'\x{2018}\x{201C}]))/iu';

    /**
     * A trailing credit: a known music-source marker, or a dash-attribution that follows a closing
     * quote. The closing-quote requirement keeps "Hymn 714 - Love Divine" — where the text after
     * the dash *is* the title — out of the family.
     */
    private const ATTRIBUTION_SUFFIX = '/(?:\s*[\-\x{2013}\x{2014}]\s*(?:music[-.]min(?:i)?stry\.org|EM[UW]|St\.?\s*Helens)\s*$)|(?<=[\'\x{2019}"\x{201D}])\s*[\-\x{2013}\x{2014}]\s*\p{Lu}[\p{L}.]*(?:\s+\p{Lu}[\p{L}.]*)+\s*$/u';

    /** A choice the email defers rather than makes. */
    private const DEFERRED_CHOICE = '/\b(?:to be chosen|to follow|to choose|chosen by|see below|link to follow|remind me of the title)\b/iu';

    /** Nothing left but the role word once bullets, markers, numbers-free text and asides are gone. */
    private const BARE_ROLE_WORD = '/^\s*(?:closing|final|communion|last|new|opening|intro\s+to)?\s*(?:songs?|hymns?|carols?)\s*(?:x\s*\d+)?\s*[.\x{2026}:;,!?]*\s*$/iu';

    /** An item of another kind classified as a song. */
    private const NOT_A_SONG_ITEM = '/^\s*(?:sermons?|call\s+too?\s+worship|prayers?|notices|welcome|offering|benediction)\b|^\s*NCC\s*Q\s*\d+/iu';

    /**
     * One item naming two songs, or a hymn sung to another entry's tune. The first number may
     * carry a book suffix — "100a + 191" is two hymns, not one.
     */
    private const MULTIPLE_SONGS = '/\bx\s*\d+\b|\d[a-z]?\s*\+\s*\d|\bplus\s+\d|\btune\s+\d{2,4}\b/iu';

    /** Conversation captured as the title. */
    private const PROSE_MARKERS = '/\b(?:I\'m|I am|I\'d like|I would|I think|we could|we\'ve|can we|could we|do we know|would you|maybe you|see you|thanks|please come back|tells me|suggest we|use the music|are we ready)\b/iu';

    /**
     * A copula outside a quoted segment: the line is a sentence *about* a song rather than the
     * song's title. Plenty of real titles contain a copula — "Jesus is Lord", "Lord, you were
     * rich", "Christ is risen" — so this only runs on a title that quotes its reference, where
     * the quotation marks say which half is the title and which half is the surrounding sentence.
     * A title with no quotes gives no such separation and is left alone.
     */
    private const UNQUOTED_COPULA = '/\b(?:is|are|was|were|will\s+be|would\s+be)\b/iu';

    /** A final word that cannot end a title. */
    private const ENDS_MID_CLAUSE = '/\b(?:the|of|a|an|to|and|in|on|at|by|for|with|my|your|his|her|our|is|was|that|this|from|but|as)\s*[\'\x{2019}"\x{201D}]?\s*$/iu';

    /** The tail of a hard-wrapped line, kept as an item of its own. */
    private const OPENS_ON_CLOSING_PUNCTUATION = '/^\s*[)\]}\x{2019}\x{201D},;:!?]/u';

    /** Beyond this a title is a sentence, whatever else it carries. */
    private const PROSE_WORD_COUNT = 16;

    public function inspect(string $title): SongTitleHygieneReport
    {
        $raw = trim($title);
        $repaired = $this->repairMojibake($raw);

        /** @var list<SongTitleDefect> $defects */
        $defects = [];

        foreach (SongTitleDefect::cases() as $defect) {
            if ($this->fires($defect, $raw, $repaired)) {
                $defects[] = $defect;
            }
        }

        return new SongTitleHygieneReport(
            verdict: $this->verdict($defects),
            defects: $defects,
            original: $raw,
            normalised: $this->normalise($raw),
        );
    }

    /**
     * The title with recoverable decoration removed, in the order the decoration nests: the
     * encoding repair first, because a mis-decoded dash hides the role label behind it; then the
     * outermost markers; then the label; then the trailing credit.
     *
     * Truncation, prose bleed and multiple-song items are deliberately not "repaired" — there is
     * no correct single title to recover, and inventing one would manufacture evidence.
     */
    public function normalise(string $title): string
    {
        $working = $this->repairMojibake(trim($title));

        $working = (string) preg_replace(self::DURATION_PREFIX, '', $working);
        $working = (string) preg_replace(self::BULLET_PREFIX, '', $working);
        $working = $this->stripMarkupEmphasis($working);
        $working = (string) preg_replace(self::ROLE_LABEL, '', $working);
        $working = (string) preg_replace(self::ATTRIBUTION_SUFFIX, '', $working);

        return trim($working);
    }

    private function fires(SongTitleDefect $defect, string $raw, string $repaired): bool
    {
        return match ($defect) {
            SongTitleDefect::Placeholder => $this->isPlaceholder($repaired),
            SongTitleDefect::NotASongItem => preg_match(self::NOT_A_SONG_ITEM, $repaired) === 1,
            SongTitleDefect::Truncated => $this->isTruncated($repaired),
            SongTitleDefect::LineFragment => $this->isLineFragment($repaired),
            SongTitleDefect::ProseBleed => $this->isProse($repaired),
            SongTitleDefect::Mojibake => preg_match(self::MOJIBAKE, $raw) === 1,
            SongTitleDefect::MultipleSongs => preg_match(self::MULTIPLE_SONGS, $repaired) === 1
                && ! $this->isPlaceholder($repaired),
            SongTitleDefect::RoleLabel => preg_match(self::ROLE_LABEL, $this->undecorated($repaired)) === 1,
            SongTitleDefect::BulletPrefix => preg_match(self::BULLET_PREFIX, $repaired) === 1,
            SongTitleDefect::DurationPrefix => preg_match(self::DURATION_PREFIX, $repaired) === 1,
            SongTitleDefect::AttributionSuffix => preg_match(self::ATTRIBUTION_SUFFIX, $repaired) === 1,
            SongTitleDefect::MarkupResidue => preg_match(self::MARKUP_RESIDUE, $repaired) === 1,
        };
    }

    /**
     * @param  list<SongTitleDefect>  $defects
     */
    private function verdict(array $defects): SongTitleHygieneVerdict
    {
        foreach ([
            SongTitleHygieneVerdict::NotATitle,
            SongTitleHygieneVerdict::Defective,
            SongTitleHygieneVerdict::Decorated,
        ] as $verdict) {
            foreach ($defects as $defect) {
                if ($defect->verdict() === $verdict) {
                    return $verdict;
                }
            }
        }

        return SongTitleHygieneVerdict::Clean;
    }

    /**
     * A deferred choice, or nothing but a role word once the decoration and any aside are removed
     * — "Hymn: (Mark to choose)" and "* Hymn" reduce to the same thing.
     *
     * A deferred phrase only defers when no reference is present. "Song: 'To be a Pilgrim' (see
     * below)" names its song and then points at a note about it; treating that as a title yet to
     * be chosen would discard a title the parser extracted correctly.
     */
    private function isPlaceholder(string $title): bool
    {
        if (preg_match(self::DEFERRED_CHOICE, $title) === 1 && ! $this->carriesReference($title)) {
            return true;
        }

        $bare = (string) preg_replace('/\([^)]*\)/u', '', $this->undecorated($title));

        return preg_match(self::BARE_ROLE_WORD, $bare) === 1;
    }

    /**
     * Whether the text names a song at all: a quoted segment, or a book number in front of it.
     */
    private function carriesReference(string $title): bool
    {
        return preg_match('/[\x{2018}\x{201C}"]|(?<![\p{L}])\'/u', $title) === 1
            || SongTitleResolver::leadingPraiseNumber($this->undecorated($title)) !== null;
    }

    private function isProse(string $title): bool
    {
        if (preg_match(self::PROSE_MARKERS, $title) === 1) {
            return true;
        }

        if (count(preg_split('/\s+/u', $title) ?: []) >= self::PROSE_WORD_COUNT) {
            return true;
        }

        $unquoted = (string) preg_replace(
            '/[\x{2018}\'][^\x{2019}\']*[\x{2019}\']|[\x{201C}"][^\x{201D}"]*[\x{201D}"]/u',
            '',
            $title,
        );

        if ($unquoted === $title) {
            return false;
        }

        return preg_match(self::UNQUOTED_COPULA, $unquoted) === 1;
    }

    private function isTruncated(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        if (str_ends_with($title, '...') || str_ends_with($title, "\u{2026}")) {
            return true;
        }

        if (preg_match(self::ENDS_MID_CLAUSE, $title) === 1) {
            return true;
        }

        return $this->hasUnbalancedDelimiters($title);
    }

    /**
     * Opens on closing punctuation, or on a lowercase word with nothing that would mark the start
     * of a reference — no digit, no opening quote, no role label. A lowercase title that carries
     * any of those is an ordinary lower-cased line, not the tail of a wrapped one.
     */
    private function isLineFragment(string $title): bool
    {
        if (preg_match(self::OPENS_ON_CLOSING_PUNCTUATION, $title) === 1) {
            return true;
        }

        if (preg_match('/^\p{Ll}/u', $title) !== 1) {
            return false;
        }

        return preg_match('/\d/u', $title) !== 1
            && ! $this->hasOpeningQuote($title)
            && preg_match(self::ROLE_LABEL, $title) !== 1
            && preg_match('/^\s*(?:songs?|hymns?|carols?)\b/iu', $title) !== 1;
    }

    /**
     * Brackets, and quotes once intra-word apostrophes are discounted — "David's" and "let's" are
     * apostrophes, not an unclosed quotation, and treating them as one flagged perfectly intact
     * titles as truncated.
     */
    private function hasUnbalancedDelimiters(string $title): bool
    {
        if (substr_count($title, '(') !== substr_count($title, ')')) {
            return true;
        }

        $withoutApostrophes = (string) preg_replace('/(?<=\p{L})[\'\x{2019}](?=\p{L})/u', '', $title);

        $opening = preg_match_all('/[\x{2018}\x{201C}]/u', $withoutApostrophes);
        $closing = preg_match_all('/[\x{2019}\x{201D}]/u', $withoutApostrophes);

        if ($opening !== $closing) {
            return true;
        }

        return substr_count($withoutApostrophes, "'") % 2 !== 0
            || substr_count($withoutApostrophes, '"') % 2 !== 0;
    }

    private function hasOpeningQuote(string $title): bool
    {
        return preg_match('/[\x{2018}\x{201C}"]|(?<![\p{L}])\'/u', $title) === 1;
    }

    /**
     * The title with the outer markers removed but the label left in place — what the role-label
     * and placeholder rules need to see, since a bulleted "- Hymn 490 Jesus is King" carries its
     * label behind the bullet.
     */
    private function undecorated(string $title): string
    {
        $working = (string) preg_replace(self::DURATION_PREFIX, '', $title);
        $working = (string) preg_replace(self::BULLET_PREFIX, '', $working);

        return trim($this->stripMarkupEmphasis($working));
    }

    private function stripMarkupEmphasis(string $title): string
    {
        return (string) preg_replace('/\*+([^*]+)\*+/u', '$1', $title);
    }

    private function repairMojibake(string $title): string
    {
        if (preg_match(self::MOJIBAKE, $title) !== 1) {
            return $title;
        }

        return strtr($title, self::MOJIBAKE_REPAIRS);
    }
}
