<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Log;

class ServiceSectionPublicationTransitionService
{
    public function isPublishableType(ServiceSection $section): bool
    {
        /** @var array<string, class-string> $handlers */
        $handlers = config('media-processing.section_publishing.handlers', []);

        return isset($handlers[$section->section_type->value]);
    }

    public function canTransition(ServiceSection $section, ServiceSectionPublicationStatus $target): bool
    {
        $current = $section->publication_status;
        $allowed = match ($current) {
            ServiceSectionPublicationStatus::NOT_APPLICABLE => [
                ServiceSectionPublicationStatus::PENDING_APPROVAL,
                ServiceSectionPublicationStatus::PUBLISHED,
            ],
            ServiceSectionPublicationStatus::PENDING_APPROVAL => [
                ServiceSectionPublicationStatus::APPROVED,
                ServiceSectionPublicationStatus::REJECTED,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::APPROVED => [
                ServiceSectionPublicationStatus::PUBLISHED,
                ServiceSectionPublicationStatus::REJECTED,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::REJECTED => [
                ServiceSectionPublicationStatus::PENDING_APPROVAL,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::PUBLISHED => [
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
        };

        if ($target === $current) {
            return true;
        }

        return in_array($target, $allowed, true);
    }

    public function transition(ServiceSection $section, ServiceSectionPublicationStatus $target): bool
    {
        if (! $this->canTransition($section, $target)) {
            Log::error('Invalid service section publication transition attempted', [
                'service_section_id' => $section->id,
                'from' => $section->publication_status->value,
                'to' => $target->value,
            ]);

            return false;
        }

        $section->publication_status = $target;

        return true;
    }
}
