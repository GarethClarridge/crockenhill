<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use RuntimeException;

/**
 * A reviewed recut of a talk's clip ({@see SaveServiceSection}'s shortened end, or an operator's
 * output edge, {@see SetOutputEdge}) makes its candidate stale: the media and its approval go, and
 * an approved talk returns to candidate preparation, which the caller dispatches after saving.
 */
class ReturnTalkCandidateForRecut
{
    public function __construct(private readonly ServiceSectionPublicationTransitionService $publicationTransitions) {}

    /** Apply to the section without saving it. */
    public function apply(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $publication = $metadata['publication'] ?? null;

        if (is_array($publication)) {
            unset($publication['approved_signature'], $publication['approved_at']);

            if ($publication === []) {
                unset($metadata['publication']);
            } else {
                $metadata['publication'] = $publication;
            }
        }

        $section->extracted_video_path = null;
        $section->extracted_audio_path = null;
        $section->extracted_at = null;
        $section->unpublished_expires_at = null;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        if ($section->publication_status === ServiceSectionPublicationStatus::Approved
            && ! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
            throw new RuntimeException('Unable to return a recut section to candidate preparation.');
        }
    }
}
