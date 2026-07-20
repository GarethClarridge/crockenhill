<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Rollout switch for the LLM-first service structure pipeline.
 *
 * Shadow persists an LLM proposal to run metadata without replacing sections.
 * Primary makes the LLM structure authoritative.
 */
enum ServiceStructureMode: string
{
    case Shadow = 'shadow';
    case Primary = 'primary';

    /**
     * Resolve the configured mode; unknown values throw rather than silently
     * falling back.
     */
    public static function fromConfig(): self
    {
        $mode = (string) config('media-processing.service_structure.mode', 'primary');

        return self::tryFrom($mode) ?? throw new InvalidArgumentException(
            "Unknown service structure mode [{$mode}]; expected shadow or primary."
        );
    }
}
