<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ServiceRecordTimeline
{
    /**
     * Build a merged timeline for a single processing run against a service's planned items.
     *
     * Sections already loaded on $processingLog->serviceSections (with churchServiceItem
     * and publishedSermon relations) are consumed directly — no extra queries are issued.
     *
     * @param  EloquentCollection<int, ChurchServiceItem>  $items  Already-loaded service items (with song)
     * @return list<array<string, mixed>>
     */
    public static function build(
        EloquentCollection $items,
        MediaProcessingLog $processingLog,
    ): array {
        // Keyed by item ID for O(1) look-up; only active (non-soft-deleted) items
        /** @var array<int, ChurchServiceItem> $activeLookup */
        $activeLookup = [];
        foreach ($items as $item) {
            if (! $item->trashed()) {
                $activeLookup[$item->id] = $item;
            }
        }

        // Track which active items were consumed by a section row
        /** @var array<int, true> $consumed */
        $consumed = [];

        $rows = [];

        /** @var EloquentCollection<int, ServiceSection> $sections */
        $sections = $processingLog->relationLoaded('serviceSections')
            ? $processingLog->serviceSections
            : $processingLog->serviceSections()->with(['churchServiceItem', 'publishedSermon'])->orderBy('section_order')->orderBy('id')->get();

        foreach ($sections as $section) {
            $oosAlignment = self::oosAlignment($section);
            $mismatchReason = isset($oosAlignment['mismatch_reason']) && is_string($oosAlignment['mismatch_reason'])
                ? $oosAlignment['mismatch_reason']
                : null;

            // --- Resolve planned context ---
            // Priority 1: the direct FK relationship (includes soft-deleted via withTrashed())
            $linkedItem = $section->churchServiceItem instanceof ChurchServiceItem
                ? $section->churchServiceItem
                : null;

            // Priority 2/3: metadata fallback when the FK is unset
            $expectedItemId = $section->expected_item_id;

            $matchedItemId = $section->matched_item_id;

            // Determine the active item that should be consumed so it isn't also emitted as planned_only
            $consumableActiveItemId = null;
            if ($linkedItem !== null && ! $linkedItem->trashed()) {
                $consumableActiveItemId = $linkedItem->id;
            } elseif ($expectedItemId !== null && isset($activeLookup[$expectedItemId])) {
                $consumableActiveItemId = $expectedItemId;
            } elseif ($matchedItemId !== null && isset($activeLookup[$matchedItemId])) {
                $consumableActiveItemId = $matchedItemId;
            }

            // Build planned-item fields from the best source available
            $plannedItemFields = self::resolvePlannedItemFields(
                linked: $linkedItem,
                activeLookup: $activeLookup,
                oosAlignment: $oosAlignment,
                expectedItemId: $expectedItemId,
                matchedItemId: $matchedItemId,
            );

            $hasPlannedContext = $plannedItemFields['item_id'] !== null;

            // Determine row type
            if ($mismatchReason !== null && $hasPlannedContext) {
                $rowType = 'mismatched';
            } elseif ($hasPlannedContext) {
                $rowType = 'matched';
            } else {
                $rowType = 'unplanned';
            }

            if ($consumableActiveItemId !== null) {
                $consumed[$consumableActiveItemId] = true;
            }

            $rows[] = self::buildRow($rowType, $plannedItemFields, $section, $oosAlignment);
        }

        // Append planned_only rows for items never consumed
        foreach ($items as $item) {
            if ($item->trashed()) {
                continue;
            }

            if (isset($consumed[$item->id])) {
                continue;
            }

            $rows[] = self::buildPlannedOnlyRow($item);
        }

        return $rows;
    }

    public static function formatTimestamp(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, ChurchServiceItem>  $activeLookup
     * @param  array<string, mixed>  $oosAlignment
     * @return array<string, mixed>
     */
    private static function resolvePlannedItemFields(
        ?ChurchServiceItem $linked,
        array $activeLookup,
        array $oosAlignment,
        ?int $expectedItemId,
        ?int $matchedItemId,
    ): array {
        // Case 1: direct FK relation exists (may be soft-deleted)
        if ($linked instanceof ChurchServiceItem) {
            $state = $linked->trashed() ? 'soft_deleted' : 'active';

            return [
                'item_id' => $linked->id,
                'position' => $linked->position,
                'item_type' => $linked->type,
                'planned_title' => $linked->title,
                'item_source' => $linked->source instanceof ChurchServiceItemSource ? $linked->source : null,
                'planned_item_state' => $state,
                'song_id' => $linked->song_id,
                'song_title' => $linked->song?->title,
                'expected_section_type' => self::resolveExpectedSectionType($oosAlignment),
            ];
        }

        // Case 2: expected item from metadata (mismatched scenarios — markMismatch never sets the FK)
        if ($expectedItemId !== null) {
            // Active item still exists: use the live model
            if (isset($activeLookup[$expectedItemId])) {
                $item = $activeLookup[$expectedItemId];

                return [
                    'item_id' => $item->id,
                    'position' => $item->position,
                    'item_type' => $item->type,
                    'planned_title' => $item->title,
                    'item_source' => $item->source instanceof ChurchServiceItemSource ? $item->source : null,
                    'planned_item_state' => 'active',
                    'song_id' => $item->song_id,
                    'song_title' => $item->song?->title,
                    'expected_section_type' => self::resolveExpectedSectionType($oosAlignment),
                ];
            }

            // Item has been deleted: fall back to metadata title so the planned side is still visible
            $expectedTitle = isset($oosAlignment['expected_item_title']) && is_string($oosAlignment['expected_item_title'])
                ? $oosAlignment['expected_item_title']
                : null;

            if ($expectedTitle !== null) {
                return [
                    'item_id' => $expectedItemId,
                    'position' => null,
                    'item_type' => null,
                    'planned_title' => $expectedTitle,
                    'item_source' => null,
                    'planned_item_state' => 'metadata_only',
                    'song_id' => null,
                    'song_title' => null,
                    'expected_section_type' => self::resolveExpectedSectionType($oosAlignment),
                ];
            }
        }

        // Case 3: matched item from metadata only (no live model lookup needed; use metadata title)
        if ($matchedItemId !== null) {
            $title = isset($oosAlignment['matched_item_title']) && is_string($oosAlignment['matched_item_title'])
                ? $oosAlignment['matched_item_title']
                : null;

            // If it's in our active lookup, use the real model
            if (isset($activeLookup[$matchedItemId])) {
                $item = $activeLookup[$matchedItemId];

                return [
                    'item_id' => $item->id,
                    'position' => $item->position,
                    'item_type' => $item->type,
                    'planned_title' => $item->title,
                    'item_source' => $item->source instanceof ChurchServiceItemSource ? $item->source : null,
                    'planned_item_state' => 'active',
                    'song_id' => $item->song_id,
                    'song_title' => $item->song?->title,
                    'expected_section_type' => self::resolveExpectedSectionType($oosAlignment),
                ];
            }

            // Metadata only — no live model
            if ($title !== null) {
                return [
                    'item_id' => $matchedItemId,
                    'position' => null,
                    'item_type' => isset($oosAlignment['matched_item_type']) && is_string($oosAlignment['matched_item_type']) ? $oosAlignment['matched_item_type'] : null,
                    'planned_title' => $title,
                    'item_source' => null,
                    'planned_item_state' => 'metadata_only',
                    'song_id' => null,
                    'song_title' => null,
                    'expected_section_type' => self::resolveExpectedSectionType($oosAlignment),
                ];
            }
        }

        // No planned context
        return [
            'item_id' => null,
            'position' => null,
            'item_type' => null,
            'planned_title' => null,
            'item_source' => null,
            'planned_item_state' => null,
            'song_id' => null,
            'song_title' => null,
            'expected_section_type' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedFields
     * @param  array<string, mixed>  $oosAlignment
     * @return array<string, mixed>
     */
    private static function buildRow(
        string $rowType,
        array $plannedFields,
        ServiceSection $section,
        array $oosAlignment,
    ): array {
        $metadata = $section->metadata?->toArray() ?? [];
        $confidenceLevel = ServiceSectionConfidence::levelFor($section->confidence ?? 0.0);
        $reviewReason = isset($metadata['review_reason']) && is_string($metadata['review_reason'])
            ? $metadata['review_reason']
            : null;
        $mismatchReason = isset($oosAlignment['mismatch_reason']) && is_string($oosAlignment['mismatch_reason'])
            ? $oosAlignment['mismatch_reason']
            : null;
        $detectedSongTitle = isset($metadata['song_title']) && is_string($metadata['song_title']) && trim($metadata['song_title']) !== ''
            ? $metadata['song_title']
            : $plannedFields['song_title'];

        $presentationInference = isset($oosAlignment['presentation_inference']) && is_array($oosAlignment['presentation_inference'])
            ? $oosAlignment['presentation_inference']
            : null;

        return array_merge($plannedFields, [
            'row_type' => $rowType,
            'section_id' => $section->id,
            'section_order' => $section->section_order,
            'section_type' => $section->section_type,
            'section_title' => $section->title,
            'section_summary' => $section->summary,
            'start_time' => $section->start_time,
            'end_time' => $section->end_time,
            'confidence' => $section->confidence,
            'confidence_level' => $confidenceLevel,
            'needs_review' => $section->needs_manual_review,
            'review_reason' => $reviewReason,
            'mismatch_reason' => $mismatchReason,
            'song_match_type' => $section->song_match_type,
            'song_title' => $detectedSongTitle,
            'presentation_inference' => $presentationInference,
            'publication_status' => $section->publication_status,
            'published_sermon' => $section->publishedSermon ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPlannedOnlyRow(ChurchServiceItem $item): array
    {
        return [
            'row_type' => 'planned_only',
            'item_id' => $item->id,
            'position' => $item->position,
            'item_type' => $item->type,
            'planned_title' => $item->title,
            'item_source' => $item->source instanceof ChurchServiceItemSource ? $item->source : null,
            'planned_item_state' => 'active',
            'song_id' => $item->song_id,
            'song_title' => $item->song?->title,
            'expected_section_type' => null,
            'section_id' => null,
            'section_order' => null,
            'section_type' => null,
            'section_title' => null,
            'section_summary' => null,
            'start_time' => null,
            'end_time' => null,
            'confidence' => null,
            'confidence_level' => null,
            'needs_review' => false,
            'review_reason' => null,
            'mismatch_reason' => null,
            'song_match_type' => null,
            'presentation_inference' => null,
            'publication_status' => null,
            'published_sermon' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $oosAlignment
     */
    private static function resolveExpectedSectionType(array $oosAlignment): ?ServiceSectionType
    {
        $value = $oosAlignment['expected_section_type'] ?? null;

        return is_string($value) ? ServiceSectionType::tryFrom($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function oosAlignment(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $alignment = $metadata['oos_alignment'] ?? null;

        return is_array($alignment) ? $alignment : [];
    }
}
