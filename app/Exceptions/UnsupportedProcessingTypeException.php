<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * UnsupportedProcessingTypeException - Exception for unsupported processing types
 *
 * Thrown when attempting to process a file with an unsupported processing type.
 * Part of the improved error handling in the refactored architecture.
 */
class UnsupportedProcessingTypeException extends Exception
{
    public function __construct(string $message = 'Unsupported processing type', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
