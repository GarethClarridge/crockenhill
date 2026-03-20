<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;

class RequeueSectionPublication
{
    /**
     * Transition a section back to pending approval.
     *
     * Returns true on success, false if the section cannot be requeued in its current state.
     */
    public function execute(ServiceSection $section): bool
    {
        if (! $section->transitionTo(ServiceSectionPublicationStatus::PENDING_APPROVAL)) {
            return false;
        }

        $section->save();

        return true;
    }
}
