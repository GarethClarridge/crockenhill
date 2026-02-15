<?php

namespace App\Enums;

enum SampleSource: string
{
    case Backfill = 'backfill';
    case UploadAuto = 'upload_auto';
    case ManualUpload = 'manual_upload';

    public function label(): string
    {
        return match ($this) {
            self::Backfill => 'Backfill',
            self::UploadAuto => 'Upload (Auto)',
            self::ManualUpload => 'Manual Upload',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
