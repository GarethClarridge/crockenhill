<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\ProvidesSafeMessage;
use InvalidArgumentException;

/**
 * Exception for invalid arguments that are safe to expose to the end user.
 *
 * This exception implements ProvidesSafeMessage, indicating that its message
 * contains no sensitive system details and can be returned in API responses.
 */
class SafeInvalidArgumentException extends InvalidArgumentException implements ProvidesSafeMessage
{
    public function getSafeMessage(): string
    {
        return $this->getMessage();
    }
}
