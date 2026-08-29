<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;

interface SectionPublicationHandler
{
    /** Whether extracted media should include audio (sermons: yes, songs: no). */
    public function requiresAudioExtraction(): bool;

    /**
     * Whether previously extracted media can be reused for this section.
     *
     * Sermon handler checks both video + audio; song handler checks video only.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Type-specific eligibility beyond generic status checks.
     *
     * e.g. songs require confirmed match, sermons require high confidence.
     */
    public function isEligible(ServiceSection $section): bool;

    /** Runs after extraction — type-specific enrichment (e.g. speaker detection). */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Whether admin approval is needed before publishing this section.
     *
     * Takes the section because the answer is not always a property of the type.
     * A song publishes itself, but a fragmentary or duplicated one must not.
     */
    public function requiresApproval(ServiceSection $section): bool;

    /** Create the downstream artifact (Sermon, SongVideo, etc.). */
    public function publish(ServiceSection $section): void;

    /** Clean up downstream artifacts when a section is superseded or deleted. */
    public function onSectionRemoved(ServiceSection $section): void;
}
