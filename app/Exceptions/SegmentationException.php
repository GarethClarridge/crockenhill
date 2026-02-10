<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * SegmentationException - Exception for video segmentation and RMS analysis failures
 *
 * Thrown when RMS log generation, segment analysis, threshold calibration,
 * or boundary detection fails.
 */
class SegmentationException extends ProcessingException
{
    public function __construct(string $message = 'Video segmentation failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
