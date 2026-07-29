<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;

/**
 * Interface SectionPublicationHandler
 *
 * Defines the contract for handlers that orchestrate type-specific publication
 * behaviors of livestream sections. This interface serves as an application boundary
 * separating the generic livestream extraction/processing pipeline from downstream
 * domain systems (such as public Sermons or Song Videos).
 *
 * Each concrete handler determines its own extraction requirements, eligibility
 * thresholds, approval workflows, post-extraction enrichment processes, and
 * downstream artifact creation/lifecycle operations.
 */
interface SectionPublicationHandler
{
    /**
     * Determine whether extracted media for this section type requires an audio stream.
     *
     * Informs the extraction pipeline to extract a separate audio track. For example,
     * sermons require audio extraction (true), whereas songs typically only require
     * the extracted video asset (false).
     *
     * @return bool True if a separate audio stream is required, false otherwise.
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Determine whether previously extracted media assets exist and can be reused for this section.
     *
     * Checks storage disks for existing files before initiating a new extraction process.
     * Sermon handlers typically verify both video and audio files, while song handlers
     * verify only the video file.
     *
     * @param  ServiceSection  $section  The livestream service section model.
     * @return bool True if all required media assets are found in storage, false otherwise.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Determine if a section meets type-specific publication criteria beyond generic status checks.
     *
     * Evaluates additional domain constraints before publication (e.g., songs require a confirmed
     * or high-confidence match to a canonical Song model, while sermons require high classification confidence).
     *
     * @param  ServiceSection  $section  The livestream service section model to evaluate.
     * @return bool True if the section is eligible for automated or manual publication, false otherwise.
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Run post-extraction, type-specific enrichment operations on the section.
     *
     * Executes immediately after extraction finishes to handle metadata enrichment and analysis.
     * For example, sermon publication handlers might trigger voice-fingerprinting or speaker
     * detection tasks on childrens' talks.
     *
     * @param  ServiceSection  $section  The livestream service section model.
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Determine whether this type of section requires administrative approval before publication.
     *
     * Controls whether the section is queued for manual approval or can transition directly
     * to publication once processed. For example, sermons require manual review (true) while
     * matched songs can be auto-published (false).
     *
     * @return bool True if manual administrative review is required, false otherwise.
     */
    public function requiresApproval(): bool;

    /**
     * Publish the section, creating the appropriate downstream public-facing artifact.
     *
     * Promotes temporary media assets to permanent public storage, resolves target metadata
     * (e.g. preacher identity, title, series), and creates the corresponding model (Sermon, SongVideo).
     *
     * @param  ServiceSection  $section  The livestream service section model to publish.
     *
     * @throws \RuntimeException If required media files are missing, target database links are
     *                           broken, metadata cannot be resolved, or state transitions fail.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Clean up and remove downstream public-facing artifacts when a section is removed.
     *
     * Executed when a section is deleted or superseded. Deletes published assets from public
     * disks and cleans up related database records (such as deleting associated SongVideos).
     *
     * @param  ServiceSection  $section  The livestream service section model that was removed.
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
