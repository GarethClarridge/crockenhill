<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\ProvidesSafeMessage;
use Exception;

/**
 * InvalidFileException - Exception for invalid file uploads
 *
 * Thrown when a file fails validation checks during processing.
 * Part of the improved error handling in the refactored architecture.
 */
class InvalidFileException extends Exception implements ProvidesSafeMessage
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(array $errors, int $code = 0, ?\Throwable $previous = null)
    {
        $message = 'Invalid file: '.implode(', ', $errors);
        parent::__construct($message, $code, $previous);
    }

    public function getSafeMessage(): string
    {
        return $this->getMessage();
    }
}
