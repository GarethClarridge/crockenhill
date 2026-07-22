<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;

class ConfirmServiceSection
{
    /**
     * Confirm a section's current classification and clear its manual-review state.
     *
     * Song matches that were inferred are promoted to confirmed. Unmatched song
     * sections remain unmatched, but the operator's decision that the match has
     * been reviewed is recorded so the section does not re-enter the queue.
     */
    public function execute(ServiceSection $section, int $userId): void
    {
        $this->apply($section, $userId);

        $section->save();
    }

    /**
     * Apply the shared review-clearing state without saving the section.
     *
     * SaveServiceSection uses this before it handles publication transitions,
     * so both review paths write the same audit metadata.
     */
    public function apply(ServiceSection $section, int $userId): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $now = now()->toIso8601String();
        $manualReview = is_array($metadata['manual_review'] ?? null)
            ? $metadata['manual_review']
            : [];

        unset($metadata['review_reason'], $metadata['review_flags']);

        $manualReview['updated_at'] = $now;
        $manualReview['updated_by_user_id'] = $userId;
        $manualReview['confirmed_at'] = $now;
        $manualReview['confirmed_by_user_id'] = $userId;

        if ($section->section_type === ServiceSectionType::Song) {
            $manualReview['song_match_reviewed_at'] = $now;
            $manualReview['song_match_reviewed_by_user_id'] = $userId;

            if ($section->song_match_type === ServiceSectionSongMatchType::Inferred) {
                $section->song_match_type = ServiceSectionSongMatchType::Confirmed;
            }
        }

        $metadata['manual_review'] = $manualReview;
        $section->needs_manual_review = false;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }
}
