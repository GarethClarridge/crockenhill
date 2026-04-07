<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SermonVideoVisibilityOverride: string
{
    use HasValues;

    case Default = 'default';
    case ForceShow = 'force_show';
    case ForceHide = 'force_hide';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Use automatic verdict',
            self::ForceShow => 'Force show video',
            self::ForceHide => 'Force hide video',
        };
    }
}
