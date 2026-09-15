<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A re-detection found no section to carry an operator's content hold, so it
 * wrote nothing: only an operator can say whether the defect went with the content.
 */
class UnplacedContentHoldException extends RuntimeException
{
    /**
     * @param  list<array{id: int, section_type: string, start_time: float, end_time: float}>  $heldContent
     */
    public static function forSections(array $heldContent): self
    {
        $described = implode('; ', array_map(
            static fn (array $held): string => sprintf(
                'section %d (%s, %s–%s s)',
                $held['id'],
                $held['section_type'],
                $held['start_time'],
                $held['end_time'],
            ),
            $heldContent,
        ));

        return new self(
            "Re-detection left content holds with no overlapping section of their type: {$described}. "
            .'Confirm or re-hold these sections before re-running.',
        );
    }
}
