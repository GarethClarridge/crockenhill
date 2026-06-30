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
