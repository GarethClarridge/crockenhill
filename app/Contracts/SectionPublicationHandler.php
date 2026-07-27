<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;

/**
 * Interface SectionPublicationHandler
 *
 * Defines the contract and architectural boundary for promoting, publishing, and
 * managing the lifecycle of extracted media segments (such as sermons and songs)
 * from church service livestreams.
 *
 * This contract isolates the media-processing pipeline from concrete publishing logic,
 * allowing diverse publication handlers to manage their own asset validation, state
 * transitions, automated enrichment (e.g., speaker detection, audio enhancement),
 * and downstream database records (e.g., Sermon, SongVideo).
 */
interface SectionPublicationHandler
{
    /**
     * Determines whether the extracted media for this section must include audio.
     *
     * For example, sermons require audio extraction (returning true), whereas
     * song recordings may only require video extraction (returning false).
     *
     * @return bool True if audio extraction is required, false otherwise.
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Determines whether previously extracted media files can be reused for the given section.
     *
     * Concrete implementations verify the presence of temporary files on the designated disk.
     * Sermon handlers check both video and audio assets, whereas song handlers check video only.
     *
     * @param  ServiceSection  $section  The service section to evaluate for reusable media.
     * @return bool True if reusable media exists and is verified, false otherwise.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Evaluates type-specific eligibility requirements for publication beyond generic status checks.
     *
     * For example, song sections require a confirmed or highly confident song match linked
     * to a canonical database Song record, whereas sermons enforce high classification confidence.
     *
     * @param  ServiceSection  $section  The service section to evaluate.
     * @return bool True if the section meets type-specific eligibility criteria, false otherwise.
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Executes type-specific enrichment operations on the section after media extraction is complete.
     *
     * This is used to trigger automated secondary processing, such as detecting speakers
     * for children's talks or running speaker identification logic on sermons.
     *
     * @param  ServiceSection  $section  The service section undergoing post-extraction enrichment.
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Determines whether administrative review and approval are required before publishing the section.
     *
     * @return bool True if the section must be approved by an admin before publication, false otherwise.
     */
    public function requiresApproval(): bool;

    /**
     * Publishes the given service section, promoting media files and generating public domain models.
     *
     * Promotes extracted video/audio assets from temporary directories to permanent public storage,
     * performs state transitions, and creates or updates downstream artifacts (such as a public Sermon
     * record or a SongVideo association).
     *
     * @param  ServiceSection  $section  The approved or eligible service section to publish.
     *
     * @throws \RuntimeException If required media files are missing, the parent processing log is invalid,
     *                           required metadata/signatures fail validation, or state transition fails.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Performs clean-up operations on published artifacts when a service section is deleted or replaced.
     *
     * Deletes promoted public files and removes linked model instances (e.g., SongVideo) to prevent
     * orphan records or stale media references when a livestream timeline is reconstructed.
     *
     * @param  ServiceSection  $section  The service section being removed.
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
