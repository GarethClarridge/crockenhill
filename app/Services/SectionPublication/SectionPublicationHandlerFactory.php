<?php

declare(strict_types=1);

namespace App\Services\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Models\ServiceSection;

class SectionPublicationHandlerFactory
{
    public function forSection(ServiceSection $section): ?SectionPublicationHandler
    {
        /** @var array<string, class-string<SectionPublicationHandler>> $handlers */
        $handlers = config('media-processing.section_publishing.handlers', []);
        $class = $handlers[$section->section_type->value] ?? null;

        return $class !== null ? app($class) : null;
    }
}
