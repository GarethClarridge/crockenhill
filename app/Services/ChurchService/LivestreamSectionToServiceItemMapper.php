<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection;

class LivestreamSectionToServiceItemMapper
{
    /**
     * Minimum confidence threshold below which a section is excluded from projection.
     */
    private const float MIN_PROJECTION_CONFIDENCE = 0.3;

    /**
     * Section types that should never become canonical service items.
     *
     * @var list<ServiceSectionType>
     */
    private const array EXCLUDED_SECTION_TYPES = [
        ServiceSectionType::Other,
    ];

    /**
     * Convert classified ServiceSections into ChurchServiceItem sync payloads.
     *
     * @param  Collection<int, ServiceSection>  $sections
     * @return list<array{position: int, type: string, section_type: string, title: string, source_title: null, openlp_search_title: null, song_id: null, livestream_processing_id: string, livestream_service_section_id: int, metadata: array<string, mixed>}>
     */
    public function map(Collection $sections, string $processingId): array
    {
        $items = [];
        $position = 0;

        foreach ($sections as $section) {
            if (! $this->shouldProject($section)) {
                continue;
            }

            $position++;

            $items[] = [
                'position' => $position,
                'type' => $this->resolveItemType($section->section_type),
                'section_type' => $section->section_type->value,
                'title' => $this->resolveTitle($section),
                'source_title' => null,
                'openlp_search_title' => null,
                'song_id' => null,
                'livestream_processing_id' => $processingId,
                'livestream_service_section_id' => $section->id,
                'metadata' => $this->buildMetadata($section),
            ];
        }

        return $items;
    }

    private function shouldProject(ServiceSection $section): bool
    {
        if (in_array($section->section_type, self::EXCLUDED_SECTION_TYPES, true)) {
            return false;
        }

        if (is_numeric($section->confidence) && (float) $section->confidence < self::MIN_PROJECTION_CONFIDENCE) {
            return false;
        }

        return true;
    }

    private function resolveItemType(ServiceSectionType $sectionType): string
    {
        return match ($sectionType) {
            ServiceSectionType::Song => 'songs',
            ServiceSectionType::BibleReading => 'bibles',
            default => 'custom',
        };
    }

    private function resolveTitle(ServiceSection $section): string
    {
        if (is_string($section->title) && trim($section->title) !== '') {
            return trim($section->title);
        }

        $hint = $section->metadata['song_title_hint'] ?? null;
        if ($section->section_type === ServiceSectionType::Song && is_string($hint) && trim($hint) !== '') {
            return trim($hint);
        }

        return $section->section_type->label();
    }

    /**
     * Build derived/display-only fields that are not yet promoted to columns.
     *
     * `processing_id` and `service_section_id` are intentionally omitted — those
     * live on dedicated columns (`livestream_processing_id`,
     * `livestream_service_section_id`) and are written as top-level payload keys
     * by the mapper. Duplicating them inside the JSON blob invited drift.
     *
     * @return array{section_summary: string|null, livestream_projection: array{source_segment_ids: array<int, int>, confidence_level: string, needs_manual_review: bool}}
     */
    private function buildMetadata(ServiceSection $section): array
    {
        return [
            'section_summary' => $section->summary,
            'livestream_projection' => [
                'source_segment_ids' => $section->source_segment_ids ?? [],
                'confidence_level' => $this->confidenceLevel($section->confidence),
                'needs_manual_review' => (bool) $section->needs_manual_review,
            ],
        ];
    }

    private function confidenceLevel(?float $confidence): string
    {
        if ($confidence === null) {
            return 'unknown';
        }

        return match (true) {
            $confidence >= 0.8 => 'high',
            $confidence >= 0.5 => 'medium',
            default => 'low',
        };
    }
}
