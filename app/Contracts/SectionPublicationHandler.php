<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;

/**
 * Interface SectionPublicationHandler
 *
 * Defines the contract for processing, promoting, and publishing segments
 * (such as sermons or songs) extracted from livestream recordings to public artifacts.
 *
 * Each implementation manages the lifecycle transitions and media asset requirements
 * for a specific segment type within the livestream-to-public publishing pipeline.
 */
interface SectionPublicationHandler
{
    /**
     * Determine whether extracted media for this section type requires an audio file.
     *
     * (e.g., sermons require both video and audio extraction; songs only require video).
     *
     * @return bool True if audio extraction is required, false otherwise.
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Determine whether previously extracted media files can be reused for this section.
     *
     * (e.g., sermon handlers check both video and audio files; song handlers only check video).
     *
     * @param  ServiceSection  $section  The service section to check.
     * @return bool True if reusable media exists, false otherwise.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Determine type-specific eligibility criteria beyond generic status checks.
     *
     * (e.g., songs require a confirmed match, while sermons require a high-confidence score).
     *
     * @param  ServiceSection  $section  The service section to check.
     * @return bool True if the section is eligible for publication, false otherwise.
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Run post-extraction, type-specific enrichment operations on the section.
     *
     * (e.g., performing speaker detection for children's talks).
     *
     * @param  ServiceSection  $section  The service section that was extracted.
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Determine whether explicit administrator approval is required before publishing.
     *
     * @return bool True if administrator approval is required, false otherwise.
     */
    public function requiresApproval(): bool;

    /**
     * Publish the section to the public repository, creating any downstream models.
     *
     * Promotes temporary extracted media files to permanent storage, transitions
     * publication states, and creates the canonical downstream models (e.g., Sermon or SongVideo).
     *
     * @param  ServiceSection  $section  The service section to publish.
     *
     * @throws \RuntimeException If assets are missing, validation checks fail, or database state transitions fail.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Clean up any downstream artifacts when a section is superseded, removed, or deleted.
     *
     * Deletes associated media files from public disks and deletes downstream database records.
     *
     * @param  ServiceSection  $section  The service section that is being removed.
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
