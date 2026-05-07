<?php

declare(strict_types=1);

namespace App\Enums;

enum UpsertAction
{
    case Create;
    case Enrich;
    case Replace;
    case Reject;
}
