<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\ChurchService\TalkReadingPairing;
use App\Support\PublicationCandidate;
use App\Support\SectionReviewFlagPolicy;

/** The same fresh judgement for a new candidate and one already awaiting approval. */
class AutomaticPublicationJudgement
{
    public function __construct(
        private readonly ExtractedSectionMediaChecker $mediaChecker,
        private readonly ServiceSectionPublicationTransitionService $transitions,
        private readonly TalkReadingPairing $readingPairing,
    ) {}

    /**
     * Assess without persisting. The handler may refresh review metadata on the supplied model;
     * the locked caller saves it. Read-only callers can inspect the same judgement without saving.
     */
    public function reasonToWithhold(ServiceSection $section, SectionPublicationHandler $handler): ?string
    {
        if ($section->processingLog->isRetired()) {
            return 'the owning run has been retired';
        }

        if ($section->needs_manual_review) {
            return 'the section has been held for manual review';
        }

        $metadata = $section->metadata;
        $flags = $metadata === null ? [] : $metadata->reviewFlags;
        $reference = $section->metadata?->raw['sermon_reference'] ?? null;
        if (SectionReviewFlagPolicy::requiresManualReview($section->section_type, $flags, is_string($reference) ? $reference : null)
            || $this->readingPairing->heldReading($section) !== null) {
            return 'the section carries a blocking review flag or content hold';
        }

        if ($section->publication_status !== ServiceSectionPublicationStatus::PendingApproval
            && ! $this->transitions->canTransition($section, ServiceSectionPublicationStatus::Published)) {
            return sprintf('the section may not become published from %s', $section->publication_status->value);
        }

        if (! $handler->isEligible($section)) {
            return 'the section is no longer eligible for publication';
        }

        if ($handler->requiresApproval($section)) {
            return 'the current publication assessment requires approval';
        }

        if (isset($section->metadata?->raw['publication_candidate_extraction_blocked'])) {
            return 'the candidate extraction is blocked';
        }

        if (($stale = PublicationCandidate::staleReason($section, $handler->audioProfile())) !== null) {
            return 'the candidate '.$stale;
        }

        if (! $this->mediaChecker->hasExtractedMedia($section)) {
            return 'the extracted media is missing';
        }

        return null;
    }
}
