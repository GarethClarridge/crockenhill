<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\ServiceSectionConfidence;
use App\Traits\ReadsSectionMetadata;

class SectionAlignmentBaselineRestorer
{
    use ReadsSectionMetadata;

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
        $section->song_match_type = null;
        $section->matched_item_id = null;
        $section->expected_item_id = null;

        // Clear all OoS-owned alignment state so each run is rebuilt from scratch
        unset(
            $metadata['oos_alignment'],
            $metadata['song_id'],
            $metadata['reading_reference'],
            $metadata['media_interlude'],
            $metadata['media_title'],
        );

        $reviewFlags = $this->clearOosReviewFlags($this->reviewFlags($metadata));

        if ($reviewFlags === []) {
            if (in_array($metadata['review_reason'] ?? null, ServiceStructureValidator::OOS_REVIEW_REASONS, true)) {
                unset($metadata['review_reason']);
            }

            unset($metadata['review_flags']);
        } else {
            $metadata['review_flags'] = $reviewFlags;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    /**
     * Normalize confidence onto the section column.
     *
     * The confidence column is now the sole runtime authority; confidence_level
     * is derived from it as needed and no longer written redundantly to JSON.
     */
    public function persistConfidenceLevel(ServiceSection $section): void
    {
        $metadata = $this->metadata($section);
        $confidence = ServiceSectionConfidence::resolve($section->confidence, $metadata);

        $section->confidence = $confidence;
        $metadata['confidence_score'] = $confidence;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
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
            static fn (string $flag): bool => ! in_array($flag, ServiceStructureValidator::OOS_REVIEW_FLAGS, true)
        ));
    }
}
