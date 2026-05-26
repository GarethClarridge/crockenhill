<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

class ServiceSectionPublicationTransitionService
{
    use SanitizesLogData;

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
            ServiceSectionPublicationStatus::NotApplicable => [
                ServiceSectionPublicationStatus::PendingApproval,
                ServiceSectionPublicationStatus::Published,
            ],
            ServiceSectionPublicationStatus::PendingApproval => [
                ServiceSectionPublicationStatus::Approved,
                ServiceSectionPublicationStatus::Rejected,
                ServiceSectionPublicationStatus::NotApplicable,
            ],
            ServiceSectionPublicationStatus::Approved => [
                ServiceSectionPublicationStatus::Published,
                ServiceSectionPublicationStatus::Rejected,
                ServiceSectionPublicationStatus::NotApplicable,
            ],
            ServiceSectionPublicationStatus::Rejected => [
                ServiceSectionPublicationStatus::PendingApproval,
                ServiceSectionPublicationStatus::NotApplicable,
            ],
            ServiceSectionPublicationStatus::Published => [
                ServiceSectionPublicationStatus::NotApplicable,
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
            Log::error('Invalid service section publication transition attempted', $this->sanitizeArrayForLog([
                'service_section_id' => $section->id,
                'from' => $section->publication_status->value,
                'to' => $target->value,
            ]));

            return false;
        }

        $section->publication_status = $target;

        return true;
    }
}
