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
     * The held content that no incoming section covered.
     *
     * Kept structured, not only described in the message: the detection job
     * records these ids on the run so an operator can see which holds refused
     * the replacement without parsing prose.
     *
     * @var list<array{id: int, section_type: string, start_time: float, end_time: float}>
     */
    public array $unplacedContent = [];

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

        $exception = new self(
            "Re-detection left content holds with no overlapping section of their type: {$described}. "
            .'Confirm or re-hold these sections before re-running.',
        );

        $exception->unplacedContent = $heldContent;

        return $exception;
    }

    /**
     * @return list<int>
     */
    public function unplacedSectionIds(): array
    {
        return array_map(
            static fn (array $held): int => $held['id'],
            $this->unplacedContent,
        );
    }
}
