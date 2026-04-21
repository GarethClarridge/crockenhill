<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PreacherSource: string
{
    use HasValues;

    case Id3 = 'id3';
    case SpeakerModel = 'speaker_model';
    case Manual = 'manual';
    case Default = 'default';

    public function label(): string
    {
        return match ($this) {
            self::Id3 => 'ID3 Tag',
            self::SpeakerModel => 'Speaker Model',
            self::Manual => 'Manual',
            self::Default => 'Default',
        };
    }
}
