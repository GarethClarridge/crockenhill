<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use App\Traits\ReadsSectionMetadata;

class SectionAlignmentBaselineRestorer
{
    use ReadsSectionMetadata;

    /**
     * All review flags that OosAlignmentService owns and recalculates on every alignment pass.
     * Cleared at the start of each run and only re-added when still applicable.
     *
     * @var array<int, string>
     */
    public const OOS_REVIEW_FLAGS = [
        'oos_structure_mismatch',
        'unmatched_song_section',
        'song_alignment_inferred',
        'ambiguous_childrens_talk',
        'inferred_childrens_talk',
    ];

    /**
     * All review reasons that OosAlignmentService owns and recalculates on every alignment pass.
     *
     * @var array<int, string>
     */
    public const OOS_REVIEW_REASONS = [
        'oos_structure_mismatch',
        'unmatched_song_section',
        'song_alignment_inferred',
        'ambiguous_childrens_talk',
        'inferred_childrens_talk',
    ];

    /**
     * Restore a section to its pre-alignment baseline state before a new alignment run.
     *
     * Reads base_* fields from existing oos_alignment metadata (written on the first
     * alignment pass) to make reruns idempotent. Clears all OoS-owned metadata and
     * review flags so each run is rebuilt from scratch, while preserving any non-OoS
     * flags set by other processes.
     */
    public function prepare(ServiceSection $section): void
    {
        $metadata = $this->metadata($section);
        $existingAlignment = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];
        $legacyAligned = ($metadata['classification_mode'] ?? null) === 'openlp_aligned';

        $section->confidence = ServiceSectionConfidence::resolve(
            is_numeric($existingAlignment['base_confidence'] ?? null) ? (float) $existingAlignment['base_confidence'] : $section->confidence,
            $metadata
        );
        $section->needs_manual_review = (bool) ($existingAlignment['base_needs_manual_review'] ?? $section->needs_manual_review);
        $section->title = $legacyAligned
            ? null
            : (array_key_exists('base_title', $existingAlignment) ? $existingAlignment['base_title'] : $section->title);
        $section->church_service_item_id = $legacyAligned
            ? null
            : (array_key_exists('base_church_service_item_id', $existingAlignment) ? $existingAlignment['base_church_service_item_id'] : $section->church_service_item_id);

        // Clear all OoS-owned alignment state so each run is rebuilt from scratch
        unset($metadata['oos_alignment'], $metadata['song_id'], $metadata['reading_reference']);

        $reviewFlags = $this->clearOosReviewFlags($this->reviewFlags($metadata));

        if ($reviewFlags === []) {
            if (in_array($metadata['review_reason'] ?? null, self::OOS_REVIEW_REASONS, true)) {
                unset($metadata['review_reason']);
            }

            unset($metadata['review_flags']);
        } else {
            $metadata['review_flags'] = $reviewFlags;
        }

        $section->metadata = $metadata;
    }

    /**
     * Normalize confidence onto the section column and redundantly into metadata.
     *
     * This redundancy is intentional and must be preserved — both locations are
     * read by different parts of the system.
     */
    public function persistConfidenceLevel(ServiceSection $section): void
    {
        $metadata = $this->metadata($section);
        $confidence = ServiceSectionConfidence::resolve($section->confidence, $metadata);

        $section->confidence = $confidence;
        $metadata['confidence_level'] = ServiceSectionConfidence::levelFor($confidence);
        $metadata['confidence_score'] = $confidence;
        $section->metadata = $metadata;
    }

    /**
     * Build the base_* snapshot written into oos_alignment metadata at the start of
     * each alignment pass, providing the reference point for future reruns.
     *
     * @return array<string, mixed>
     */
    public function baseAlignmentMetadata(ServiceSection $section): array
    {
        $metadata = $this->metadata($section);
        $existing = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];

        return [
            'base_confidence' => ServiceSectionConfidence::resolve(
                is_numeric($existing['base_confidence'] ?? null) ? (float) $existing['base_confidence'] : $section->confidence,
                $metadata
            ),
            'base_needs_manual_review' => (bool) ($existing['base_needs_manual_review'] ?? $section->needs_manual_review),
            'base_title' => array_key_exists('base_title', $existing) ? $existing['base_title'] : $section->title,
            'base_church_service_item_id' => array_key_exists('base_church_service_item_id', $existing)
                ? $existing['base_church_service_item_id']
                : $section->church_service_item_id,
        ];
    }

    /**
     * Remove all OoS-owned review flags from the given flag list.
     *
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    public function clearOosReviewFlags(array $flags): array
    {
        return array_values(array_filter(
            $flags,
            static fn (string $flag): bool => ! in_array($flag, self::OOS_REVIEW_FLAGS, true)
        ));
    }
}
