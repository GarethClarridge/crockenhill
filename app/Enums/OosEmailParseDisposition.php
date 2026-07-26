<?php

declare(strict_types=1);

namespace App\Enums;

enum OosEmailParseDisposition: string
{
    case AutoImportable = 'auto_importable';
    case ReviewRequired = 'review_required';
    case InvalidExtraction = 'invalid_extraction';
}
