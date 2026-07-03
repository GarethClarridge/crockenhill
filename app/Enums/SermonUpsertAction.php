<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * @internal The three outcomes for SermonCreationService when an existing record matches.
 */
enum SermonUpsertAction
{
    case Enrich;
    case Replace;
    case Reject;
}
