<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * TranscriptionException - Exception for audio transcription failures
 *
 * Thrown when audio transcription fails, including API errors, file
 * processing errors, and chunking failures.
 */
class TranscriptionException extends ProcessingException
{
    public function __construct(string $message = 'Audio transcription failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
