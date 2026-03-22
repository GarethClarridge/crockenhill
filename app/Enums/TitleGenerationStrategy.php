<?php

declare(strict_types=1);

namespace App\Enums;

enum TitleGenerationStrategy: string
{
    case AI_WITH_FALLBACK = 'ai_with_fallback';
    case FILENAME_ONLY = 'filename_only';
    case CUSTOM = 'custom';
}
