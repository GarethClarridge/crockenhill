<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ServiceSectionPublicationTransitionService;

class RequeueSectionPublication
{
    public function __construct(
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
    ) {}

    /**
     * Transition a section back to pending approval.
     *
     * Returns true on success, false if the section cannot be requeued in its current state.
     */
    public function execute(ServiceSection $section): bool
    {
        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::PendingApproval)) {
            return false;
        }

        $section->save();

        return true;
    }
}
