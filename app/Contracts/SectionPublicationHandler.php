<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;

/**
 * Contract defining application boundaries for promoting livestream sections to public assets.
 *
 * Each type of section (e.g., sermons, song videos) has its own publication handler implementing
 * this contract. Handlers manage type-specific checks, extraction requirements, metadata enrichment,
 * and downstream model creation (such as Sermon or SongVideo).
 */
interface SectionPublicationHandler
{
    /**
     * Whether extracted media should include audio (sermons: yes, songs: no).
     *
     * @return bool
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Whether previously extracted media can be reused for this section.
     *
     * Sermon handler checks both video + audio; song handler checks video only.
     *
     * @param  ServiceSection  $section  The service section to check media reuse for.
     * @return bool
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Type-specific eligibility beyond generic status checks.
     *
     * e.g. songs require confirmed match, sermons require high confidence.
     *
     * @param  ServiceSection  $section  The service section being evaluated.
     * @return bool
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Runs after extraction — type-specific enrichment (e.g. speaker detection).
     *
     * @param  ServiceSection  $section  The service section being enriched.
     * @return void
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Whether admin approval is needed before publishing.
     *
     * @return bool
     */
    public function requiresApproval(): bool;

    /**
     * Create the downstream artifact (Sermon, SongVideo, etc.).
     *
     * @param  ServiceSection  $section  The approved section to publish.
     * @return void
     *
     * @throws \RuntimeException If assets are missing, identity cannot be resolved, or state transitions fail.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Clean up downstream artifacts when a section is superseded or deleted.
     *
     * @param  ServiceSection  $section  The removed service section.
     * @return void
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
