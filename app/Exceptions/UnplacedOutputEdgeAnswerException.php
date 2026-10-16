<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\ChurchService\OutputEdgeAnswers;

/**
 * A re-detection found no section of its type to carry an operator's output edge
 * ({@see OutputEdgeAnswers}), so it wrote nothing: the answer was heard on that content, and only
 * an operator can say it no longer applies. A content hold's refusal, so the detection job parks
 * the run the same way instead of retrying the detector.
 */
class UnplacedOutputEdgeAnswerException extends UnplacedContentHoldException
{
    /**
     * @param  list<array{id: int, section_type: string, start_time: float, end_time: float}>  $answeredContent
     */
    public static function forAnswers(array $answeredContent): self
    {
        $described = implode('; ', array_map(
            static fn (array $answered): string => sprintf('section %d (%s, %s–%s s)', $answered['id'], $answered['section_type'], $answered['start_time'], $answered['end_time']),
            $answeredContent,
        ));

        $exception = new self(
            "Re-detection left operator output edges without a unique overlapping section of their type (missing or merged answers): {$described}. "
            .'Clear or re-set these edges on the service screen before re-running.',
        );

        $exception->unplacedContent = $answeredContent;

        return $exception;
    }
}
