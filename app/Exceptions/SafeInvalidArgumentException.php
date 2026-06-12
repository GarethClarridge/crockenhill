<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\ProvidesSafeMessage;
use InvalidArgumentException;

/**
 * Exception thrown when an argument is invalid but the message is safe for user display.
 */
class SafeInvalidArgumentException extends InvalidArgumentException implements ProvidesSafeMessage
{
    /**
     * Get the safe error message.
     */
    public function getSafeMessage(): string
    {
        return $this->getMessage();
    }
}
