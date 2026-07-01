<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Rollout switch for the LLM-first service structure pipeline.
 *
 * - Off: nothing new runs; the heuristic pipeline is byte-for-byte unchanged.
 * - Shadow: the heuristic chain stays authoritative; the LLM path also runs,
 *   persists its proposal to run metadata only, and logs a structured diff.
 * - Primary: the LLM chain is authoritative; the heuristic cluster no longer
 *   runs. Validation failure routes to the existing manual-review flow.
 */
enum ServiceStructureMode: string
{
    case Off = 'off';
    case Shadow = 'shadow';
    case Primary = 'primary';

    /**
     * Resolve the configured mode; unknown values throw rather than silently
     * falling back.
     */
    public static function fromConfig(): self
    {
        $mode = (string) config('media-processing.service_structure.mode', 'off');

        return self::tryFrom($mode) ?? throw new InvalidArgumentException(
            "Unknown service structure mode [{$mode}]; expected off, shadow or primary."
        );
    }
}
