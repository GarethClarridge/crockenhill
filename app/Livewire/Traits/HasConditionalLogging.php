<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Illuminate\Support\Facades\Log;

trait HasConditionalLogging
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::info($message, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::warning($message, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logError(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::error($message, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logDebug(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::debug($message, $context);
        }
    }
}
