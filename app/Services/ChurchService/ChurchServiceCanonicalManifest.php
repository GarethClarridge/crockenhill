<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchService;
use App\Support\CanonicalJson;

class ChurchServiceCanonicalManifest
{
    /** @return array<string, mixed> */
    public function build(ChurchService $service): array
    {
        $service->loadMissing('items.song');

        return [
            'date' => $service->date->toDateString(),
            'service' => $service->service->value,
            'summary' => $service->summary,
            'notices' => $service->notices ?? [],
            'chapter_markers' => $service->chapter_markers ?? [],
            'items' => $service->items->sortBy('position')->map(fn ($item): array => [
                'position' => $item->position,
                'canonical_identity' => $item->canonical_identity,
                'type' => $item->type,
                'section_type' => $item->section_type?->value,
                'source' => $item->source?->value,
                'title' => $item->title,
                'source_title' => $item->source_title,
                'song_canonical_key' => $item->song?->canonical_key,
                'occurrence_state' => $item->occurrence_state?->value,
                'manual_occurrence_decision' => $item->manual_occurrence_decision,
                'source_assertion_hashes' => $item->metadata['source_assertion_hashes'] ?? [],
            ])->values()->all(),
        ];
    }

    public function hash(ChurchService $service): string
    {
        return CanonicalJson::hash($this->build($service));
    }
}
