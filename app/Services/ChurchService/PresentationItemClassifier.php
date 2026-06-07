<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class PresentationItemClassifier
{
    /**
     * Classifies presentation items using an evidence-tiered decision model.
     *
     * Evidence tiers (from strongest to weakest):
     * - explicit: section_type column is set → use that type directly, no review required
     * - strong:   title clearly names a childrens talk or notices → reclassify, flag for review
     * - weak:     position only → resolve to OTHER, attach a suspected_type hint, no reclassification
     *
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     * @return array{
     *     decisions: array<int, array{
     *         resolved_type: ServiceSectionType,
     *         suspected_type: ServiceSectionType|null,
     *         evidence: 'explicit'|'strong'|'weak',
     *         requires_review: bool,
     *         review_flag: string|null,
     *         reason: string
     *     }>,
     *     childrens_talk_count: int
     * }
     */
    public function classify(EloquentCollection $items): array
    {
        $firstSongPosition = $items
            ->filter(fn (ChurchServiceItem $item): bool => strtolower($item->type) === 'songs')
            ->min('position') ?? PHP_INT_MAX;

        $decisions = [];
        $childrensTalkCount = 0;

        foreach ($items as $item) {
            if (strtolower($item->type) !== 'presentations') {
                continue;
            }

            $decision = $this->makeDecision($item, $firstSongPosition);
            $decisions[$item->id] = $decision;

            if ($decision['resolved_type'] === ServiceSectionType::ChildrensTalk) {
                $childrensTalkCount++;
            }
        }

        return ['decisions' => $decisions, 'childrens_talk_count' => $childrensTalkCount];
    }

    /**
     * Produce a single presentation classification decision for one item.
     *
     * @return array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }
     */
    private function makeDecision(ChurchServiceItem $item, int $firstSongPosition): array
    {
        if ($item->section_type instanceof ServiceSectionType) {
            return [
                'resolved_type' => $item->section_type,
                'suspected_type' => null,
                'evidence' => 'explicit',
                'requires_review' => false,
                'review_flag' => null,
                'reason' => 'explicit_section_type',
            ];
        }

        $normalizedTitle = strtolower(trim($item->title ?? ''));

        // Tier 2a: title clearly signals a childrens talk
        if (preg_match('/\b(children\'?s?\s+talk|children)\b/', $normalizedTitle) === 1) {
            return [
                'resolved_type' => ServiceSectionType::ChildrensTalk,
                'suspected_type' => null,
                'evidence' => 'strong',
                'requires_review' => true,
                'review_flag' => 'inferred_childrens_talk',
                'reason' => 'presentation_title_children_keyword',
            ];
        }

        // Tier 2b: title clearly signals notices
        if (preg_match('/\b(notices?|announcements?)\b/', $normalizedTitle) === 1) {
            return [
                'resolved_type' => ServiceSectionType::Notices,
                'suspected_type' => null,
                'evidence' => 'strong',
                'requires_review' => false,
                'review_flag' => null,
                'reason' => 'presentation_title_notices_keyword',
            ];
        }

        // Tier 3: position-only inference — weak, never reclassifies
        if ($item->position <= $firstSongPosition) {
            return [
                'resolved_type' => ServiceSectionType::Other,
                'suspected_type' => ServiceSectionType::Notices,
                'evidence' => 'weak',
                'requires_review' => false,
                'review_flag' => null,
                'reason' => 'pre_first_song_presentation',
            ];
        }

        return [
            'resolved_type' => ServiceSectionType::Other,
            'suspected_type' => ServiceSectionType::ChildrensTalk,
            'evidence' => 'weak',
            'requires_review' => false,
            'review_flag' => null,
            'reason' => 'post_first_song_presentation',
        ];
    }
}
