<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;
use InvalidArgumentException;

/**
 * An operator's release of one content hold, once they have checked the content it was about.
 *
 * The record is marked released, never deleted, so the history keeps what was held and why it
 * was let go. The hold flag goes only when no live record is left, and review is recomputed by
 * {@see SectionReviewFlagPolicy}, as {@see \App\Services\ChurchService\ContentHoldRechecker} does
 * when a check clears one. This is how run 1112's sermon hold was released on 2026-09-24.
 */
final class ReleaseSectionContentHold
{
    /**
     * @return array<string, mixed> The record as released
     *
     * @throws InvalidArgumentException when no single live hold matches, or no reason is given
     */
    public function __invoke(ServiceSection $section, ?string $heldAt, string $because): array
    {
        $because = trim($because);

        if ($because === '') {
            throw new InvalidArgumentException('A release needs the reason the content is now right.');
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $records = HoldSectionForContentReview::holdsIn($metadata);
        $matching = array_keys(array_filter(
            $records,
            static fn (array $record): bool => HoldSectionForContentReview::isLive($record)
                && ($heldAt === null || ($record['held_at'] ?? null) === $heldAt),
        ));

        if (count($matching) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Section %d has %d live hold(s) matching%s; name exactly one with --held-at.',
                $section->id,
                count($matching),
                $heldAt === null ? '' : " held at {$heldAt}",
            ));
        }

        $index = $matching[0];
        $records[$index] = [
            ...$records[$index],
            'released_at' => now()->toIso8601String(),
            'released_by' => 'operator',
            'released_reason' => $because,
        ];
        $metadata[HoldSectionForContentReview::METADATA_KEY] = $records;

        if (array_filter($records, HoldSectionForContentReview::isLive(...)) === []) {
            $metadata['review_flags'] = array_values(array_diff($section->metadata->reviewFlags ?? [], [HoldSectionForContentReview::FLAG]));
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();

        return $records[$index];
    }
}
