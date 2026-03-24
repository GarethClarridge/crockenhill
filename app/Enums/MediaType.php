<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum MediaType: string
{
    use HasValues;

    case Audio = 'audio';
    case Video = 'video';
    case Livestream = 'livestream';

    public function label(): string
    {
        return match ($this) {
            self::Audio => 'Audio',
            self::Video => 'Video',
            self::Livestream => 'Livestream',
        };
    }

    /**
     * Whether this type produces sermon video output.
     */
    public function hasVideo(): bool
    {
        return $this === self::Video || $this === self::Livestream;
    }
}
