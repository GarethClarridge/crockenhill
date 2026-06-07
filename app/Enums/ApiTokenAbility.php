<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ApiTokenAbility: string
{
    use HasValues;

    case MediaProcess = 'media:process';
    case ServiceUpload = 'service:upload';
}
