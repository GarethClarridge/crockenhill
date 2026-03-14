<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Interface ProvidesSafeMessage
 *
 * Exceptions implementing this interface indicate that their message is safe
 * to be displayed to the end user in API responses and error logs.
 */
interface ProvidesSafeMessage
{
    /**
     * Get the safe error message.
     */
    public function getSafeMessage(): string;
}
