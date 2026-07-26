<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;

/**
 * Interface defining the contract for publication handlers of extracted service sections.
 *
 * This contract establishes the boundary between raw livestream segment extractions and
 * downstream canonical church artifacts (e.g., Sermons, SongVideos, or Children's Talks).
 * Handlers are responsible for validating section eligibility, performing post-extraction
 * enrichment, managing assets (audio/video promotion to public disks), executing the final
 * publication state transition, and handling cleanup when sections are removed or superseded.
 *
 * @see SermonPublicationHandler
 * @see SongPublicationHandler
 */
interface SectionPublicationHandler
{
    /**
     * Determine whether the extracted media for this section type requires an audio-only track.
     *
     * Sermons require a separate, high-quality audio file (e.g., MP3) for podcast feeds and
     * audio players. Songs only require the video clip (e.g., MP4) as they are typically
     * watched and not distributed via audio-only platforms.
     *
     * @return bool True if a dedicated audio file must be extracted, false otherwise.
     */
    public function requiresAudioExtraction(): bool;

    /**
     * Check whether previously extracted media files still exist and can be reused for this section.
     *
     * Handlers inspect the configured storage disk and verify files. Sermon handlers inspect both
     * video and audio paths, whereas song handlers verify the video path only.
     *
     * @param  ServiceSection  $section  The service section to inspect.
     * @return bool True if all required media files are present on the disk, false otherwise.
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /**
     * Evaluate type-specific eligibility criteria beyond basic extraction/segmentation success.
     *
     * Handlers apply domain-specific thresholds before allowing publication. For example:
     * - Sermon publication requires a high-confidence classification to prevent automatic publishing of low-confidence segments.
     * - Song publication requires a valid link to a canonical Song record in the database (either confirmed or inferred match).
     *
     * @param  ServiceSection  $section  The service section to evaluate.
     * @return bool True if the section meets all type-specific criteria for publication, false otherwise.
     */
    public function isEligible(ServiceSection $section): bool;

    /**
     * Perform post-extraction, type-specific enrichment and analysis.
     *
     * Executed immediately after a segment's media files have been successfully sliced from the livestream.
     * Used for background tasks such as detecting and storing speaker profiles for Children's Talks.
     *
     * @param  ServiceSection  $section  The newly extracted service section.
     */
    public function afterExtraction(ServiceSection $section): void;

    /**
     * Check if this section type requires manual administrator approval before transitioning to published.
     *
     * Sermons typically require approval to verify metadata and content correctness. Songs are usually
     * automated and do not require separate admin approval once matches are verified.
     *
     * @return bool True if the section must be manually approved, false if it can be auto-published.
     */
    public function requiresApproval(): bool;

    /**
     * Publish the section, promoting its media assets and creating the downstream canonical records.
     *
     * Handlers copy/move extracted media files from transient or staging disks to the public/permanent
     * disks, update paths, execute state transitions (via the publication transition service), and
     * create or update downstream domain models (such as Sermon or SongVideo).
     *
     * This method is designed to be idempotent; calling it on an already published section should exit gracefully.
     *
     * @param  ServiceSection  $section  The approved or ready-to-publish section.
     *
     * @throws \RuntimeException If required media assets are missing, linked database resources are broken,
     *                           validation signatures mismatch, or state transitions fail.
     */
    public function publish(ServiceSection $section): void;

    /**
     * Clean up or unpublish downstream artifacts when a section is superseded, removed, or deleted.
     *
     * Handlers must delete associated media files from public disks and cascade-delete the downstream
     * records (e.g., deleting a SongVideo and its promoted file) to prevent orphaned files and broken references.
     *
     * @param  ServiceSection  $section  The section being removed or superseded.
     */
    public function onSectionRemoved(ServiceSection $section): void;
}
