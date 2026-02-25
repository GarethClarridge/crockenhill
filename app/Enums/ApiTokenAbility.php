<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ApiTokenAbility: string
{
    use HasValues;

    case MEDIA_PROCESS = 'media:process';
}
