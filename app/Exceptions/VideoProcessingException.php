<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * VideoProcessingException - Exception for video extraction and processing failures
 *
 * Thrown when video segment extraction, re-encoding, or audio extraction fails.
 */
class VideoProcessingException extends ProcessingException
{
    public function __construct(string $message = 'Video processing failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
