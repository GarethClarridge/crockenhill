<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;

class ApproveSectionForPublication
{
    public function __construct(
        private readonly ChildrensTalkSpeakerService $speakerService
    ) {}

    /**
     * Approve a section for publication: validates pre-conditions, transitions state,
     * writes audit metadata, and dispatches the publish job.
     *
     * Returns null on success, or an error message string on failure.
     *
     * @param  array<string, mixed>|null  $auditMetadata
     */
    public function execute(ServiceSection $section, ?array $auditMetadata = null): ?string
    {
        $error = $this->approvalBlocker($section);
        if ($error !== null) {
            return $error;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::APPROVED)) {
            return 'This section cannot be approved in its current state.';
        }

        $metadata = is_array($section->metadata) ? $section->metadata : [];
        $publicationMetadata = is_array($metadata['publication'] ?? null) ? $metadata['publication'] : [];
        $publicationMetadata['approved_signature'] = $section->classificationSignature();
        $publicationMetadata['approved_at'] = now()->toIso8601String();

        if ($auditMetadata !== null) {
            $batchApprovals = is_array($publicationMetadata['batch_approvals'] ?? null)
                ? $publicationMetadata['batch_approvals']
                : [];
            $batchApprovals[] = $auditMetadata;
            $publicationMetadata['batch_approvals'] = $batchApprovals;
        }

        $metadata['publication'] = $publicationMetadata;
        $section->metadata = $metadata;
        $section->save();

        PublishApprovedServiceSection::dispatch($section->id)
            ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

        return null;
    }

    /**
     * Check whether a section has all pre-conditions met for approval.
     * Returns null if the section can be approved, or an error message if not.
     */
    public function approvalBlocker(ServiceSection $section): ?string
    {
        if (! $section->hasExtractedMedia()) {
            return 'Section media is missing. Reclassify and prepare candidates again.';
        }

        if (! $this->speakerService->hasResolvedSpeaker($section)) {
            return "Choose a speaker for this children's talk before approving publication.";
        }

        return null;
    }
}
