<?php

declare(strict_types=1);

namespace App\Enums;

enum TitleGenerationStrategy: string
{
    case AiWithFallback = 'ai_with_fallback';
    case FilenameOnly = 'filename_only';
    case Custom = 'custom';
}
