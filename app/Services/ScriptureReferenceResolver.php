<?php

declare(strict_types=1);

namespace App\Services;

use TechWilk\BibleVerseParser\BiblePassageParser;
use TechWilk\BibleVerseParser\Exception\UnableToParseException;

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
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        try {
            $passages = $this->parser->parse($reference);

            if (empty($passages)) {
                return null;
            }

            // Use the first passage for normalization (single-reference sermons only)
            return (string) $passages[0];
        } catch (UnableToParseException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
