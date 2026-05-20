<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ServiceSectionPublicationTransitionService;

class ExpireSectionPublicationAssets
{
    public function __construct(
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
    ) {}

    /**
     * Transition a section to NOT_APPLICABLE and record expiry audit metadata.
     *
     * The caller retains responsibility for deleting the actual files from storage,
     * as file deletion is an infrastructure concern outside the publication domain.
     *
     * @param  array<string, mixed>  $auditContext
     *
     * @throws \RuntimeException When the section cannot be transitioned to NOT_APPLICABLE
     */
    public function execute(ServiceSection $section, array $auditContext): void
    {
        $previousStatus = $section->publication_status->value;

        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
            throw new \RuntimeException(
                "Failed to transition section #{$section->id} from {$previousStatus} to not_applicable."
            );
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['cleanup'] = array_merge($auditContext, [
            'previous_status' => $previousStatus,
            'cleaned_at' => now()->toIso8601String(),
        ]);

        $section->extracted_video_path = null;
        $section->extracted_audio_path = null;
        $section->extracted_at = null;
        $section->unpublished_expires_at = null;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }
}
