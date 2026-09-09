<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Data\ServiceSectionMetadata;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;

/**
 * Take a published section back out of view when its own state no longer
 * supports publication (P8-Q16 gap 1).
 *
 * `PrepareSectionPublicationCandidates` has three branches for a section that is
 * already `published` — the handler finds it ineligible, it needs manual review,
 * or it is plainly published — and every one of them `continue`s or merely saves.
 * A section demoted anywhere else in the pipeline reaches `not_applicable`; a
 * published one is the single case that never does. So a section can gain a
 * review hold, or lose the service item that identifies its song, and stay
 * public with nothing reconciling it.
 *
 * **`needs_manual_review` is the verdict; the flag array is only its input.**
 * Of the 29 published sections carrying a review flag, 14 carry only
 * `structure_oos_cross_type_inversion`, which {@see SectionReviewFlagPolicy}
 * always demotes because it "questions which OoS *item* a section aligns to,
 * never the section's own quality". Reading the raw flags would unpublish all 14
 * for a fact the codebase has already ruled harmless.
 *
 * **Demotion is deliberately not deletion.** The handler's
 * {@see SectionPublicationHandler::onSectionRemoved()} deletes the video file and
 * its row, which is right for a section that was superseded or removed and wrong
 * for one that is merely waiting on a person. Public visibility for a song is
 * exactly {@see SongVideo::scopePubliclyReleased()} — `publication_state` being
 * `published` — so quarantining the row takes it out of view and leaves every
 * artifact intact for whoever adjudicates it.
 */
final class PublishedSectionReconciler
{
    public function __construct(
        private readonly SectionPublicationHandlerFactory $handlers,
        private readonly ServiceSectionPublicationTransitionService $transitions,
    ) {}

    /**
     * Why this published section should no longer be public, or null if it should.
     *
     * @return array{reason: string, detail: string}|null
     */
    public function assess(ServiceSection $section): ?array
    {
        if ($section->publication_status !== ServiceSectionPublicationStatus::Published) {
            return null;
        }

        $handler = $this->handlers->forSection($section);

        if (! $handler instanceof SectionPublicationHandler) {
            return [
                'reason' => 'no_publication_handler',
                'detail' => sprintf('no publication handler accepts a %s section', $section->section_type->value),
            ];
        }

        if (! $handler->isEligible($section)) {
            return [
                'reason' => 'handler_ineligible',
                'detail' => 'the publication handler no longer accepts this section',
            ];
        }

        if ($section->needs_manual_review) {
            $metadata = $section->metadata;
            $flags = $metadata instanceof ServiceSectionMetadata ? $metadata->reviewFlags : [];

            return [
                'reason' => 'needs_manual_review',
                'detail' => $flags === []
                    ? 'held for review with no recorded flag'
                    : 'held for review: '.implode(', ', $flags),
            ];
        }

        return null;
    }

    /**
     * Whether this section can be demoted without leaving state disagreeing with
     * storage, and why not when it cannot.
     *
     * A released historic song video was *moved* to the delivery disk by
     * {@see \App\Services\Import\HistoricSermonPublicationService}. Setting its
     * state back without reversing that move would leave `publication_state` and
     * `asset_disk` describing different decisions — the shape P8-Q3 records, where
     * a row says one thing and the bytes another. That reversal belongs to the
     * release path, so it is refused here and reported rather than skipped.
     *
     * A published sermon is refused for the same reason: its public exposure is
     * governed by the release path and `Sermon::publication_state`, and no sermon
     * is published in this database, so there is no exercised path to demote one.
     *
     * @return string|null the refusal, or null when the demotion may proceed
     */
    public function refusal(ServiceSection $section): ?string
    {
        if ($section->section_type === ServiceSectionType::Sermon || $section->published_sermon_id !== null) {
            return 'a published sermon is released and withdrawn by the historic release path, not here';
        }

        $video = $this->songVideo($section);

        if ($video instanceof SongVideo
            && $video->publication_state === SermonPublicationState::Published
            && $video->historic_import_operation_id !== null
        ) {
            return 'its song video was released by a historic import, which moved the asset; reversing that belongs to the release path';
        }

        return null;
    }

    /**
     * Take the section out of view, recording why and what it was.
     *
     * @param  array{reason: string, detail: string}  $assessment
     * @return array{section: bool, video: bool}
     */
    public function demote(ServiceSection $section, array $assessment): array
    {
        $video = $this->songVideo($section);
        $videoDemoted = false;

        if ($video instanceof SongVideo && $video->publication_state === SermonPublicationState::Published) {
            $video->publication_state = SermonPublicationState::Quarantined;
            $video->save();
            $videoDemoted = true;
        }

        if (! $this->transitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
            return ['section' => false, 'video' => $videoDemoted];
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $publication = is_array($metadata['publication'] ?? null) ? $metadata['publication'] : [];

        // `published_at` is cleared so the row cannot read as both demoted and
        // published, and kept here so a reader can still see when it went out.
        $publication['demoted'] = [
            'at' => now()->toISOString(),
            'reason' => $assessment['reason'],
            'detail' => $assessment['detail'],
            'previously_published_at' => $section->published_at?->toISOString(),
            'previously_published_sermon_id' => $section->published_sermon_id,
            'song_video_quarantined' => $videoDemoted,
        ];
        $metadata['publication'] = $publication;

        // The schema requires both to be null off `published`
        // (`service_sections_publication_link_check`), so a demotion that cleared
        // only the timestamp would be rejected by the database rather than leaving
        // a half-demoted row — the invariant is enforced there, not just here.
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->published_at = null;
        $section->published_sermon_id = null;
        $section->unpublished_expires_at = now();
        $section->save();

        return ['section' => true, 'video' => $videoDemoted];
    }

    /**
     * Whether this section's downstream artifact is currently visible publicly.
     */
    public function isPubliclyVisible(ServiceSection $section): bool
    {
        return $this->songVideo($section)?->publication_state === SermonPublicationState::Published;
    }

    private function songVideo(ServiceSection $section): ?SongVideo
    {
        return SongVideo::query()->where('service_section_id', $section->id)->first();
    }
}
