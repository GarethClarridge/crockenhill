<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ServiceSection;

/**
 * One section's re-derived review state, and the two lists that say what moved.
 *
 * The added/removed lists are what a dry run reports. They exist because a count of
 * changed rows says nothing about whether the change is the intended one: a run that
 * withdraws a fossil and a run that quietly withdraws a live alignment flag both report
 * "one section changed".
 */
final readonly class SectionFlagChange
{
    /**
     * @param  list<string>  $addedFlags
     * @param  list<string>  $removedFlags
     * @param  array<string, mixed>  $updates  Column values to force-fill onto the section
     */
    public function __construct(
        public ServiceSection $section,
        public array $addedFlags,
        public array $removedFlags,
        public array $updates,
    ) {}
}
