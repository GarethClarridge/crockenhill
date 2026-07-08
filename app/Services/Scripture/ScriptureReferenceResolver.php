<?php

declare(strict_types=1);

namespace App\Services\Scripture;

use TechWilk\BibleVerseParser\BiblePassage;
use TechWilk\BibleVerseParser\BiblePassageParser;

class ScriptureReferenceResolver
{
    public function __construct(
        private readonly BiblePassageParser $parser,
    ) {}

    /**
     * Normalize a raw reference string to a canonical form.
     *
     * Returns null if the reference cannot be parsed (unparseable input).
     * The normalized form is used as the cache key for scripture_passages.
     */
    public function normalize(string $reference): ?string
    {
        $passages = $this->parse($reference);

        if ($passages === []) {
            return null;
        }

        // Use the first passage for normalization (single-reference sermons only)
        return (string) $passages[0];
    }

    /**
     * Normalize a raw reference string to a canonical form, preserving every passage.
     *
     * Unlike {@see normalize()}, which returns only the first passage (for use as a
     * single-reference cache key), this joins all parsed passages so multi-part
     * references such as "John 3:16-18, 4:1-2" are kept whole ("John 3:16-18,
     * John 4:1-2"). Returns null if the reference cannot be parsed.
     */
    public function normalizeAll(string $reference): ?string
    {
        $passages = $this->parse($reference);

        if ($passages === []) {
            return null;
        }

        return implode(', ', array_map(static fn (BiblePassage $passage): string => (string) $passage, $passages));
    }

    /**
     * Whether two references name the same reading, allowing subrange forms.
     *
     * A transcript-derived reference often subdivides the planned passage
     * ("Luke 18:31-33, 35-43" heard against a planned "Luke 18:31-43"), and
     * strict string comparison flags that as a conflict. Two references agree
     * when (a) every passage on each side overlaps at least one passage on the
     * other, so no passage is read in isolation, and (b) wherever two passages
     * overlap, one contains the other. Requirement (b) rejects a crossing
     * overlap where each side reads beyond their shared verses ("Luke 18:31-43"
     * vs "Luke 18:40-50" share 40-43 but each extends past) — a genuine
     * conflict, not a subrange subdivision. An unparseable side never agrees.
     */
    public function referencesAgree(string $left, string $right): bool
    {
        $leftSpans = $this->verseSpans($left);
        $rightSpans = $this->verseSpans($right);

        if ($leftSpans === [] || $rightSpans === []) {
            return false;
        }

        if (! $this->everySpanOverlapsOne($leftSpans, $rightSpans)
            || ! $this->everySpanOverlapsOne($rightSpans, $leftSpans)) {
            return false;
        }

        return ! $this->anyOverlapCrosses($leftSpans, $rightSpans);
    }

    /**
     * Whether any left/right passage pair overlaps without one containing the
     * other — i.e. the two references cross rather than one subdividing the
     * other, so each side reads verses beyond their shared span.
     *
     * @param  list<array{0: int, 1: int}>  $leftSpans
     * @param  list<array{0: int, 1: int}>  $rightSpans
     */
    private function anyOverlapCrosses(array $leftSpans, array $rightSpans): bool
    {
        foreach ($leftSpans as [$leftFrom, $leftTo]) {
            foreach ($rightSpans as [$rightFrom, $rightTo]) {
                $overlaps = $leftFrom <= $rightTo && $leftTo >= $rightFrom;

                if (! $overlaps) {
                    continue;
                }

                $leftContainsRight = $leftFrom <= $rightFrom && $leftTo >= $rightTo;
                $rightContainsLeft = $rightFrom <= $leftFrom && $rightTo >= $leftTo;

                if (! $leftContainsRight && ! $rightContainsLeft) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Each passage as an inclusive [from, to] span in the parser's integer
     * notation (book/chapter/verse packed into one comparable integer).
     *
     * @return list<array{0: int, 1: int}>
     */
    private function verseSpans(string $reference): array
    {
        return array_values(array_map(
            static fn (BiblePassage $passage): array => [
                $passage->from()->integerNotation(),
                $passage->to()->integerNotation(),
            ],
            $this->parse($reference)
        ));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     * @param  list<array{0: int, 1: int}>  $others
     */
    private function everySpanOverlapsOne(array $spans, array $others): bool
    {
        foreach ($spans as [$from, $to]) {
            $overlaps = false;

            foreach ($others as [$otherFrom, $otherTo]) {
                if ($from <= $otherTo && $to >= $otherFrom) {
                    $overlaps = true;

                    break;
                }
            }

            if (! $overlaps) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse a reference into passages, returning [] when it cannot be parsed.
     *
     * @return array<int, BiblePassage>
     */
    private function parse(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return [];
        }

        try {
            return $this->parser->parse($this->normaliseSingleChapterReferences($reference));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Insert an explicit "1:" chapter for single-chapter book verse references.
     *
     * For single-chapter books (Obadiah, Philemon, 2 John, 3 John, Jude) a bare
     * "Book N" reference means verse N, but the parser reads N as a (non-existent)
     * chapter and rejects it — and reads "Book 1" as the whole book rather than
     * verse 1. Rewriting "Book N" to "Book 1:N" before parsing fixes both, while
     * leaving the verse expression (ranges such as "3-5", "3–5" or "3 to 5", and
     * verse lists) for the parser to normalise.
     *
     * Each separator-delimited section is handled independently, so a single
     * offending part of a multi-part reference (e.g. the "Jude 3" in
     * "John 3:16; Jude 3") is rewritten in place while the rest is preserved.
     */
    private function normaliseSingleChapterReferences(string $reference): string
    {
        // Keep the separators (&, ",", ";", "and") so the reference can be reassembled.
        $tokens = preg_split('/(\s*(?:&|,|;|\band\b)\s*)/i', $reference, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($tokens === false) {
            return $reference;
        }

        // Section tokens are at even indices; captured separators sit between them.
        for ($index = 0; $index < count($tokens); $index += 2) {
            $tokens[$index] = $this->normaliseSingleChapterSection($tokens[$index]);
        }

        return implode('', $tokens);
    }

    /**
     * Rewrite a "Book N" section for a single-chapter book to "Book 1:N".
     *
     * Returns the section unchanged when it is not of that shape, already names a
     * chapter (contains ":"), or the book is not a single-chapter book. Book
     * recognition (including abbreviations) is delegated to the parser, so forms
     * such as "Phlm 6" are handled.
     */
    private function normaliseSingleChapterSection(string $section): string
    {
        $trimmed = trim($section);

        // Split a leading book name from a trailing verse expression that begins
        // with a digit and does not already specify a chapter (no ":").
        if (! preg_match('/^(.+?)\s+(\d[^:]*)$/', $trimmed, $matches)) {
            return $section;
        }

        [, $bookName, $verses] = $matches;

        try {
            $bookPassages = $this->parser->parse($bookName);
        } catch (\Throwable) {
            return $section;
        }

        if ($bookPassages === [] || $bookPassages[0]->from()->book()->chaptersInBook() !== 1) {
            return $section;
        }

        return $bookName.' 1:'.$verses;
    }
}
