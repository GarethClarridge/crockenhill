<?php

declare(strict_types=1);

namespace App\Services\Scripture;

/**
 * How a passage is said aloud, to tell whether speech introduces a reading (T4, K18).
 *
 * Several books share their name with people who read or lead here (John, Mark, Luke, Ruth), so a
 * bare name is not enough: "I'm going to ask John, if you will, to come and do the Bible reading"
 * hands over a John reading without introducing it. A name counts where it is said as a book —
 * followed by a chapter, a number or "gospel"/"letter", or after "in", "of", "turn to", "letter to
 * the" — and a chapter of the passage counts on its own ("chapters 32 and 33", run 1053).
 */
class SpokenPassage
{
    /** Spoken forms of a numbered book's prefix. */
    private const SPOKEN_BOOK_NUMBERS = ['1' => 'first', '2' => 'second', '3' => 'third'];

    public function __construct(private readonly ScriptureReferenceResolver $scriptureReferences) {}

    /**
     * The ways a reference's first book is said aloud: "John", "1 Corinthians" or "First
     * Corinthians", "Psalm" or "Psalms".
     *
     * @return list<string>
     */
    public function bookNames(?string $reference): array
    {
        $normalized = $reference === null ? null : ($this->scriptureReferences->normalizeAll($reference) ?? $reference);

        if ($normalized === null || preg_match('/^(?:([1-3])\s+)?([A-Za-z][A-Za-z ]*?)\s+\d/', $normalized, $match) !== 1) {
            return [];
        }

        $book = mb_strtolower($match[2]);
        $names = in_array($book, ['psalm', 'psalms'], true) ? ['psalm', 'psalms'] : [$book];

        if ($match[1] !== '') {
            $names = [...array_map(static fn (string $name): string => "{$match[1]} {$name}", $names),
                ...array_map(static fn (string $name): string => self::SPOKEN_BOOK_NUMBERS[$match[1]].' '.$name, $names)];
        }

        return $names;
    }

    /**
     * Whether the words name one of these passages: its book said as a book, or one of its chapters.
     *
     * @param  list<?string>  $references
     */
    public function isNamedIn(string $text, array $references): bool
    {
        $text = mb_strtolower(str_replace('’', "'", $text));

        foreach ($references as $reference) {
            foreach ($this->bookNames($reference) as $book) {
                $name = preg_quote($book, '/');

                if (preg_match("/\\b{$name}(?:'s)?\\s+(?:chapter|\\d|gospel|letter)/u", $text) === 1
                    || preg_match("/\\b(?:in|of|turn(?: with me)? to|letter to the)\\s+(?:the\\s+)?{$name}\\b/u", $text) === 1) {
                    return true;
                }
            }

            foreach ($this->chapters($reference) as $chapter) {
                if (preg_match("/\\bchapters?\\s+{$chapter}\\b/u", $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The chapters a reference reads: "Genesis 32:1-21" reads 32, "James 2:14-24; James 1:22-25" 2 and 1.
     *
     * @return list<string>
     */
    private function chapters(?string $reference): array
    {
        $normalized = $reference === null ? '' : ($this->scriptureReferences->normalizeAll($reference) ?? $reference);
        preg_match_all('/[A-Za-z]\s+(\d+)/', $normalized, $matches);

        return array_values(array_unique($matches[1]));
    }
}
