<?php

declare(strict_types=1);

namespace App\Enums;

enum SermonSourceType: string
{
    case Manual = 'manual';
    case AudioUpload = 'audio_upload';
    case VideoUpload = 'video_upload';
    case Livestream = 'livestream';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::AudioUpload => 'Audio Upload',
            self::VideoUpload => 'Video Upload',
            self::Livestream => 'Livestream',
        };
    }

    public function isFromLivestream(): bool
    {
        return $this === self::Livestream;
    }
}
