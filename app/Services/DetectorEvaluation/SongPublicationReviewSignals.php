<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Enums\DetectorSurface;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;

/**
 * Reads the objections the song publication policy recorded against a section.
 *
 * **A read, not a re-run.** `SongPublicationHandler::requiresApproval()` already
 * persists the policy's verdict to `metadata.song_publication_review.reasons`,
 * so this adapter has the same read-only property as the others: it reports what
 * production decided, and cannot hand back a fresher opinion from newer code and
 * have it scored as though it shipped. Re-running the policy here would also
 * need each run's own staging context for transcript and RMS inputs, which is
 * exactly the dependency that left 19 sections unassessable during the
 * 2026-09-21 backfill.
 *
 * **Absence is two different things.** The review key is `unset` when the policy
 * found no objection, so a missing key means either "assessed and clear" or
 * "never assessed". The boundary evidence key is written on the same pass
 * whatever the outcome, so it is the discriminator: evidence present with no
 * review means assessed clear; neither present means nobody looked. (The 19
 * sections in runs 884, 888, 890, 893 and 914 were deliberately left unwritten
 * and so read as never assessed, which is the correct answer for them.)
 *
 * **Staleness is reported, not resolved.** Stored reasons are a snapshot from
 * whenever the handler last ran, and §4.3a was burned by this before: banked
 * verdicts substituted for current-policy reassessment concealed real policy
 * drift. This adapter does not attempt to detect drift — that needs the
 * evidence version the report binds — but it surfaces `decided_at` in context so
 * a verdict older than the policy that produced it is visible rather than
 * silently scored as current.
 */
class SongPublicationReviewSignals
{
    /**
     * Every recorded objection across this run's sections.
     *
     * @return list<DetectorSignal>
     */
    public function for(MediaProcessingLog $run): array
    {
        $signals = [];

        foreach ($run->serviceSections()->get() as $section) {
            $signals = [...$signals, ...($this->forSection($run, $section) ?? [])];
        }

        return $signals;
    }

    /**
     * The objections recorded against one section, or null when the policy has
     * never assessed it.
     *
     * Null is unknown, never clean.
     *
     * @return list<DetectorSignal>|null
     */
    public function forSection(MediaProcessingLog $run, ServiceSection $section): ?array
    {
        $metadata = $section->metadata?->toArray() ?? [];

        if (! array_key_exists(SongPublicationBoundaryEvidenceService::METADATA_KEY, $metadata)) {
            return null;
        }

        $review = $metadata['song_publication_review'] ?? null;

        // The key is unset when the policy raised nothing, so an absent or empty
        // review here is the positive claim that this section was assessed and
        // found clear — the banked evidence checked above is what proves it was
        // assessed at all.
        if (! is_array($review) || ! is_array($review['reasons'] ?? null) || $review['reasons'] === []) {
            return [];
        }

        $reasons = $review['reasons'];
        $decidedAt = is_string($review['decided_at'] ?? null) ? $review['decided_at'] : null;

        $signals = [];

        foreach ($reasons as $reason) {
            if (! is_array($reason) || ! is_string($reason['kind'] ?? null)) {
                continue;
            }

            $signals[] = DetectorSignal::forStoredSignal(
                surface: DetectorSurface::SongPublicationReview,
                signal: $reason['kind'],
                runId: (int) $run->id,
                sectionId: (int) $section->id,
                start: $section->start_time,
                end: $section->end_time,
                held: (bool) $section->needs_manual_review,
                context: [
                    'detail' => is_string($reason['detail'] ?? null) ? $reason['detail'] : null,
                    'decided_at' => $decidedAt,
                ],
            );
        }

        return $signals;
    }

    /**
     * Whether this section is usable as detector-negative evidence: the policy
     * assessed it and raised nothing.
     */
    public function isDetectorNegative(ServiceSection $section): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];

        return array_key_exists(SongPublicationBoundaryEvidenceService::METADATA_KEY, $metadata)
            && ($metadata['song_publication_review'] ?? null) === null;
    }
}
