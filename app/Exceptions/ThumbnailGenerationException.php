<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * ThumbnailGenerationException - Exception for thumbnail generation failures
 *
 * Thrown when frame extraction, image processing, or thumbnail storage fails.
 */
class ThumbnailGenerationException extends ProcessingException
{
    public function __construct(string $message = 'Thumbnail generation failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
