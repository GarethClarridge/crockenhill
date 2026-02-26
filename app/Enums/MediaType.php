<?php

namespace App\Enums;

enum MediaType: string
{
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
