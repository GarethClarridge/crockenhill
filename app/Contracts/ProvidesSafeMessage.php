<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Interface ProvidesSafeMessage
 *
 * Exceptions implementing this interface indicate that their message is safe
 * to be displayed to the end user in API responses and error logs.
 *
 * Safe exceptions are generally those that represent validation errors,
 * expected business logic constraints, or high-level processing failures
 * where the error message does not contain sensitive system details like
 * file paths, database structure, or API keys.
 *
 * Core safe exceptions include:
 * - App\Exceptions\InvalidFileException (Validation)
 * - App\Exceptions\ProcessingException (and its subclasses like TranscriptionException)
 */
interface ProvidesSafeMessage
{
    /**
     * Get the safe error message.
     */
    public function getSafeMessage(): string;
}
