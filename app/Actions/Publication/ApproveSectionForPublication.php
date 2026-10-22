<?php

declare(strict_types=1);

namespace App\Actions\Publication;

use App\Data\HistoricStagingContext;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\ChurchService\TalkReadingPairing;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Preacher\TalkSpeakerService;

class ApproveSectionForPublication
{
    public function __construct(
        private readonly TalkSpeakerService $speakerService,
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
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

        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::Approved)) {
            return 'This section cannot be approved in its current state.';
        }

        $metadata = $section->metadata?->toArray() ?? [];
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
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->extracted_at ??= now();
        $section->save();

        // The job takes its staging context from the queue payload, and an approval is made where
        // none is active: a historic section's clip would land outside its run's batch (1363, 10-22).
        // A pending dispatch queues when it is destroyed, so it must not outlive the closure.
        $dispatch = static function () use ($section): null {
            PublishApprovedServiceSection::dispatch($section->id)
                ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

            return null;
        };
        $stagingContext = $section->processingLog->historicStagingContext();
        $stagingContext instanceof HistoricStagingContext
            ? app(HistoricStagingContextRegistry::class)->within($stagingContext, $dispatch)
            : $dispatch();

        return null;
    }

    /**
     * Check whether a section has all pre-conditions met for approval.
     * Returns null if the section can be approved, or an error message if not.
     */
    public function approvalBlocker(ServiceSection $section): ?string
    {
        if (! $this->mediaChecker->hasExtractedMedia($section)) {
            return 'Section media is missing. Reclassify and prepare candidates again.';
        }

        if (! $this->speakerService->hasResolvedSpeaker($section)) {
            return 'Choose a speaker for this short talk before approving publication.';
        }

        if (! $section->hasResolvedTalkType()) {
            return 'Choose the talk type before approving publication.';
        }

        if (($reading = app(TalkReadingPairing::class)->heldReading($section)) !== null) {
            return "The reading cut with this talk (section {$reading->id}) is held for content review.";
        }

        return null;
    }
}
