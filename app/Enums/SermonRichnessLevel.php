<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * @internal Ranks for the SermonCreationService upsert matrix. Higher value = richer.
 */
enum SermonRichnessLevel: int
{
    case Audio = 1;
    case Video = 2;
    case Livestream = 3;
}
