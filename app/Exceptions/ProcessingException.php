<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * ProcessingException - Base exception for all media processing errors
 *
 * Extend this class to create domain-specific processing exceptions.
 */
class ProcessingException extends Exception
{
    public function __construct(string $message = 'A processing error occurred', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
