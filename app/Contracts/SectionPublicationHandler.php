<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;

/**
 * Contract defining the interface for livestream section publication handlers.
 *
 * Implementations of this contract manage the custom publication lifecycle and downstream promotion
 * of video segments extracted from livestream recordings (e.g., promoting sermon or song clips
 * to public archives). This decouples the core extraction orchestration from the specific model
 * constraints, approval logic, and metadata enrichment required for different section types.
 *
 * @see SermonPublicationHandler
 * @see SongPublicationHandler
 */
interface SectionPublicationHandler
{
    /**
     * Whether the extracted media file must include an audio stream.
     *
     * Returns true if audio extraction is required (e.g. for standalone sermon audio podcasts),
     * and false if video is the only target delivery medium (e.g. for song recordings).
     *
     * @return bool True if audio extraction is required, false otherwise.
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Determine if previously extracted media files can be reused for the given section.
     *
     * Minimizes storage overhead and processing costs by verifying if the expected
     * output artifacts are already present and complete in temporary or public disks.
     * Typically, a sermon handler checks both video and audio assets, whereas a song
     * handler might only verify the video asset.
     *
     * @param  ServiceSection  $section  The service section being processed.
     * @return bool True if existing media is complete and reusable, false if re-extraction is required.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Assess type-specific eligibility criteria beyond the generic status validation.
     *
     * Checks model and domain constraints. For example, song sections might require a confirmed
     * match to a known hymn/song index, whereas sermon sections might require confidence scores
     * that exceed the calibrated heuristic threshold.
     *
     * @param  ServiceSection  $section  The service section to evaluate.
     * @return bool True if the section meets type-specific publishing preconditions, false otherwise.
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Execute type-specific post-extraction tasks or metadata enrichment.
     *
     * Invoked immediately after media files have been successfully sliced from the parent recording.
     * For example, a childrens talk section might trigger voice-print speaker detection, while a sermon
     * section might queue asynchronous AI classification/transcription jobs.
     *
     * @param  ServiceSection  $section  The newly extracted service section.
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Determine if manual administrative approval is required before the section can be published.
     *
     * @return bool True if approval is required (blocking automatic publication), false if automatic promotion is permitted.
     */
    public function requiresApproval(): bool;

    /**
     * Promote extracted temporary files and publish the section to the public repository.
     *
     * Creates or updates the downstream domain model (e.g., creating a Sermon model or a SongVideo),
     * moves temporary assets to permanent storage disks, and handles security and signature verification.
     *
     * @param  ServiceSection  $section  The approved section to be published.
     *
     * @throws \RuntimeException If assets are missing, signatures do not match, or state transitions fail.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Handle cleanup when a published section is deleted or superseded.
     *
     * Ensures database and disk consistency by purging downstream assets or sending admin alerts
     * if published data is silently removed during subsequent sync cycles.
     *
     * @param  ServiceSection  $section  The removed or superseded service section.
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
