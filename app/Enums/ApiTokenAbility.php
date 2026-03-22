<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ApiTokenAbility: string
{
    use HasValues;

    case MEDIA_PROCESS = 'media:process';
    case SERVICE_UPLOAD = 'service:upload';
}
