<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

/**
 * What a re-derivation pass found for one processing run: the section changes it would
 * make, and a note when it could not answer everything it was asked.
 *
 * The note is not a skip. A run whose banked structure no longer describes its rows, or
 * which banked no structure at all, can still have a retired flag withdrawn — that
 * judgement needs no structure. The note says what was *not* re-derived, so a partial
 * answer is never mistaken for a complete one.
 */
final readonly class SectionFlagRederivation
{
    /**
     * @param  list<SectionFlagChange>  $changes
     */
    public function __construct(
        public array $changes = [],
        public ?string $note = null,
    ) {}
}
