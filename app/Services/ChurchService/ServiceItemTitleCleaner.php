<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Services\Scripture\ScriptureReferenceResolver;

/**
 * Turns the raw line an order of service carries into the title a reader should see.
 *
 * An order of service is written for the person holding it, so its lines lean on the
 * document around them ("Notices (see above)") and restate the type they sit under
 * ("Bible Reading: Joshua 5:13-6:27"). Stored as a title, both lose their referent: the
 * document is gone and the type is already a column. Cleaning is therefore a display
 * concern only — every caller keeps the raw line in `source_title`, which is the field
 * cross-source matching and song resolution actually read.
 */
class ServiceItemTitleCleaner
{
    /**
     * Things an order of service points at that a stored title cannot reach.
     *
     * Longer alternatives lead so "overleaf" is not matched as "over".
     */
    private const CROSS_REFERENCE_TARGETS = 'above|below|attachments?|attached|overleaf|over'
        .'|powerpoint|power\s*point|ppt|pp|slides?|screen|handouts?|inserts?'
        .'|notice\s*sheet|separate\s*sheet|sheet|e-?mails?|previous\s+page|next\s+page';

    /**
     * A parenthetical whose whole payload is a pointer at the surrounding document.
     *
     * The target vocabulary is the guard: matching any parenthetical that opens with
     * "see" would strip "(see Joshua 5)", which names content rather than a location.
     */
    private const CROSS_REFERENCE_PATTERN = '/\s*[\(\[]\s*(?:see|as\s+per|as|cf\.?|refer\s+to|per)?\s*(?:'
        .self::CROSS_REFERENCE_TARGETS
        .')\b[^)\]]*[\)\]]/iu';

    /**
     * A leading restatement of the bible-reading type, with any ordinal and separator.
     */
    private const READING_LABEL_PATTERN = '/^\s*(?:(?:old|new)\s+testament|o\.?t\.?|n\.?t\.?|bible|scripture'
        .'|first|second|1st|2nd)?\s*readings?\s*(?:\d+\s*)?[:\-–—]*\s*/iu';

    /**
     * Punctuation left dangling once a stripped fragment is removed ("Family Talk –").
     *
     * Trimmed with a /u pattern rather than trim()'s byte list, which would split the
     * multi-byte dashes and leave mojibake behind a title ending in another such glyph.
     */
    private const DANGLING_PUNCTUATION_PATTERN = '/^[\s\-–—:;,]+|[\s\-–—:;,]+$/u';

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferenceResolver,
    ) {}

    /**
     * The title to show for a raw order-of-service line.
     *
     * Never returns an empty string: a line that is nothing but decoration ("Bible
     * Reading" with no passage) keeps its raw text, because an item with a blank title
     * fails validation and is dropped from the prefill entirely.
     */
    public function displayTitle(string $rawTitle, ServiceSectionType $sectionType): string
    {
        $stripped = $this->withoutCrossReferences($rawTitle);

        // Only a line that yields a passage loses its label: stripping unconditionally
        // would reduce "Bible Reading (see Joshua 5)" to a bare "(see Joshua 5)", and
        // leave a label-only "Bible Reading" with no title at all. The normalised form
        // is preferred over the stripped text so "Joshua 5:13 - 6:27" and
        // "Joshua 5:13-6:27" become one title rather than two.
        if ($sectionType === ServiceSectionType::BibleReading) {
            $reference = $this->referenceIn($stripped);

            if ($reference !== null) {
                return $reference;
            }
        }

        return $stripped === '' ? trim($rawTitle) : $stripped;
    }

    /**
     * The passage a bible-reading line names, canonically formatted, or null.
     *
     * Both the cleaned title and the raw line are tried: an admin may have typed the
     * reference straight into the title, while an imported line still carries its label.
     */
    public function readingReference(string $title, ?string $rawTitle = null): ?string
    {
        foreach ([$title, $rawTitle] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $reference = $this->referenceIn($this->withoutCrossReferences($candidate));

            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * The passage a cross-reference-free line names once any reading label is removed.
     */
    private function referenceIn(string $value): ?string
    {
        $stripped = $this->tidy($this->withoutReadingLabel($value));

        if ($stripped === '') {
            return null;
        }

        return $this->scriptureReferenceResolver->normalizeAll($stripped);
    }

    private function withoutCrossReferences(string $value): string
    {
        return $this->tidy(preg_replace(self::CROSS_REFERENCE_PATTERN, '', $value) ?? $value);
    }

    private function withoutReadingLabel(string $value): string
    {
        return preg_replace(self::READING_LABEL_PATTERN, '', $value) ?? $value;
    }

    private function tidy(string $value): string
    {
        $collapsed = preg_replace('/[ \t]{2,}/', ' ', $value) ?? $value;

        return preg_replace(self::DANGLING_PUNCTUATION_PATTERN, '', $collapsed) ?? trim($collapsed);
    }
}
