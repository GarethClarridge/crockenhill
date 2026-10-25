<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongBoundaryEvidenceBackfill;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Whether a song's hold belongs to its publication review, which the flag policy cannot see.
 *
 * Two writers own `needs_manual_review` on a song. One derives it from `review_flags`
 * through {@see SectionReviewFlagPolicy}; the other is the publication review, which
 * records its doubts in `song_publication_review.reasons` and sets the boolean directly
 * ({@see SongBoundaryEvidenceBackfill}, {@see SongPublicationHandler}). Every writer that
 * re-derives the boolean from flags alone reads such a song as "no flags, no hold" and
 * clears it — 35 of 98 such holds on 10-25 — and a published clip cleared that way is
 * never taken down by `service:demote-held-publications`, which reads the boolean.
 *
 * So the hold is kept, never raised: a writer that would clear a held song asks this
 * first. A song awaiting approval without the boolean is held by its status, as
 * publication holds it, and is not this rule's concern.
 *
 * The reasons stay on the section after an operator answers it — confirming the section
 * and approving the clip both leave them — so a reason decided before the operator's
 * latest answer has been answered and keeps nothing.
 */
final class SongPublicationReviewHold
{
    /**
     * @param  array<string, mixed>  $metadata  The metadata about to be written, which may differ from the row's
     */
    public static function keepsHold(ServiceSection $section, array $metadata): bool
    {
        if (! $section->needs_manual_review || $section->section_type !== ServiceSectionType::Song) {
            return false;
        }

        $review = is_array($metadata['song_publication_review'] ?? null) ? $metadata['song_publication_review'] : [];

        if (! is_array($review['reasons'] ?? null) || $review['reasons'] === []) {
            return false;
        }

        $decidedAt = self::instant($review['decided_at'] ?? null);

        // A doubt nobody can date cannot be shown to have been answered.
        if (! $decidedAt instanceof Carbon) {
            return true;
        }

        $answeredAt = array_filter([
            self::instant(data_get($metadata, 'manual_review.confirmed_at')),
            self::instant(data_get($metadata, 'publication.approved_at')),
        ]);

        foreach ($answeredAt as $answer) {
            if ($answer->greaterThanOrEqualTo($decidedAt)) {
                return false;
            }
        }

        return true;
    }

    private static function instant(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
