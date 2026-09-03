<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;

class HistoricNormalOutputServiceManifest
{
    public function __construct(
        private readonly HistoricProcessingResultSectionKey $sectionKey,
    ) {}

    /** @return array<string, mixed> */
    public function build(ChurchService $service): array
    {
        $service->loadMissing([
            'items.song',
            'items.livestreamServiceSection.processingLog',
        ]);

        $serviceIdentity = $service->date->toDateString().'|'.$service->service->value;

        return [
            'date' => $service->date->toDateString(),
            'service' => $service->service->value,
            'service_identity' => $serviceIdentity,
            'source' => $service->source,
            'summary' => $service->summary,
            // The operator's confirmation travels with the label. Without it an
            // import lands the proposal unconfirmed and the answer has to be
            // given twice — and unlike the review-queue state, this decision is
            // about what the public page may say, which is what this manifest is.
            'occasion' => $service->occasion?->value,
            'occasion_confirmed_at' => $service->occasion_confirmed_at?->toIso8601String(),
            'notices' => $service->notices,
            'chapter_markers' => $service->chapter_markers,
            'items' => $service->items->sortBy('position')->map(
                fn (ChurchServiceItem $item): array => [
                    'service_identity' => $serviceIdentity,
                    'position' => $item->position,
                    'canonical_identity' => $item->canonical_identity,
                    'type' => $item->type,
                    'section_type' => $item->section_type?->value,
                    'source' => $item->source?->value,
                    'title' => $item->title,
                    'source_title' => $item->source_title,
                    'song_canonical_key' => $item->song?->canonical_key,
                    'livestream_processing_key' => $item->livestream_processing_id,
                    'livestream_section_key' => $this->livestreamSectionKey($item),
                    'occurrence_state' => $item->occurrence_state?->value,
                    'manual_occurrence_decision' => $item->manual_occurrence_decision,
                    'metadata' => $item->metadata,
                    'source_assertion_hashes' => $item->metadata['source_assertion_hashes'] ?? [],
                ],
            )->values()->all(),
        ];
    }

    private function livestreamSectionKey(ChurchServiceItem $item): ?string
    {
        $section = $item->livestreamServiceSection;
        $processingId = $section?->processingLog?->processing_id;

        if ($section === null || ! is_string($processingId)) {
            return null;
        }

        return $this->sectionKey->for($processingId, $section, $item->canonical_identity);
    }
}
