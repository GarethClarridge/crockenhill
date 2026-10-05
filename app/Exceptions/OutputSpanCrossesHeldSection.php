<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A cut whose widened edges reach into another section's held content. */
class OutputSpanCrossesHeldSection extends RuntimeException
{
    /** @param  list<int>  $sectionIds */
    public function __construct(public readonly array $sectionIds)
    {
        parent::__construct('span_crosses_held_section: '.implode(', ', $sectionIds));
    }
}
