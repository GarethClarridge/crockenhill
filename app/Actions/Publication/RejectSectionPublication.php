<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ServiceSectionPublicationTransitionService;

class RejectSectionPublication
{
    public function __construct(
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
    ) {}

    /**
     * Transition a section to the rejected state.
     *
     * Returns true on success, false if the section cannot be rejected in its current state.
     */
    public function execute(ServiceSection $section): bool
    {
        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::REJECTED)) {
            return false;
        }

        $section->save();

        return true;
    }
}
