<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PreacherSource: string
{
    use HasValues;

    case ID3 = 'id3';
    case SPEAKER_MODEL = 'speaker_model';
    case MANUAL = 'manual';
    case DEFAULT = 'default';

    public function label(): string
    {
        return match ($this) {
            self::ID3 => 'ID3 Tag',
            self::SPEAKER_MODEL => 'Speaker Model',
            self::MANUAL => 'Manual',
            self::DEFAULT => 'Default',
        };
    }
}
