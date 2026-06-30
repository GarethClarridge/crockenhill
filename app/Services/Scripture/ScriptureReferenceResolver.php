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
     * For single-chapter books (Obadiah, Philemon, 2 John, 3 John, Jude) a bare
     * "Book N" reference is conventionally verse N, but the parser reads N as a
     * (non-existent) chapter and rejects it. When the initial parse fails, we
     * retry with an explicit "1:" chapter so references like "Jude 3" or
     * "Philemon 6" resolve.
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
            return $this->parser->parse($reference);
        } catch (\Throwable) {
            $rewritten = $this->rewriteSingleChapterReference($reference);

            if ($rewritten === null) {
                return [];
            }

            try {
                return $this->parser->parse($rewritten);
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * Rewrite any bare single-chapter "Book N" parts of a reference to "Book 1:N".
     *
     * The reference is split on the same separators the parser uses, so a single
     * offending part in an otherwise valid multi-part reference (e.g. the "Jude 3"
     * in "John 3:16; Jude 3") is rewritten in place while the rest is preserved.
     * Returns null when no part could be rewritten.
     */
    private function rewriteSingleChapterReference(string $reference): ?string
    {
        // Keep the separators (&, ",", ";", "and") so the reference can be reassembled.
        $tokens = preg_split('/(\s*(?:&|,|;|\band\b)\s*)/i', $reference, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($tokens === false) {
            return null;
        }

        $changed = false;

        // Section tokens are at even indices; captured separators sit between them.
        for ($index = 0; $index < count($tokens); $index += 2) {
            $rewritten = $this->rewriteSingleChapterSection($tokens[$index]);

            if ($rewritten !== null) {
                $tokens[$index] = $rewritten;
                $changed = true;
            }
        }

        return $changed ? implode('', $tokens) : null;
    }

    /**
     * Rewrite a single "Book N" section for a single-chapter book to "Book 1:N".
     *
     * Returns null when the section is not of that shape or the book is not a
     * single-chapter book. Book recognition (including abbreviations) is delegated
     * to the parser, so forms such as "Phlm 6" are handled.
     */
    private function rewriteSingleChapterSection(string $section): ?string
    {
        $trimmed = trim($section);

        // Split a trailing verse expression (digits, ranges and lists, no ":")
        // from the book name, e.g. "3 John 4-8" -> book "3 John", verses "4-8".
        if (! preg_match('/^(.+?)\s+(\d+(?:\s*[-,]\s*\d+)*)$/', $trimmed, $matches)) {
            return null;
        }

        [, $bookName, $verses] = $matches;

        try {
            $bookPassages = $this->parser->parse($bookName);
        } catch (\Throwable) {
            return null;
        }

        if ($bookPassages === [] || $bookPassages[0]->from()->book()->chaptersInBook() !== 1) {
            return null;
        }

        return $bookName.' 1:'.$verses;
    }
}
