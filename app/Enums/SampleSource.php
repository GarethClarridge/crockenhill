<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SampleSource: string
{
    use HasValues;

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
}
