<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceEvidenceKind;
use App\Models\Song;
use BackedEnum;
use Illuminate\Support\Str;

class ChurchServiceAssertionNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function normalize(array $items, ChurchServiceEvidenceKind $evidenceKind): array
    {
        $assertions = [];
        $canonicalKeysBySongId = $this->canonicalKeysBySongId($items);

        foreach (array_values($items) as $index => $item) {
            $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : $index + 1;
            $title = trim((string) ($item['title'] ?? ''));
            $sourceTitle = is_string($item['source_title'] ?? null) ? trim($item['source_title']) : null;
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : null;
            if (is_array($metadata)) {
                unset($metadata['source_assertion_hashes'], $metadata['source_assertion_sources'], $metadata['source_evidence']);
            }
            $metadata = array_filter([
                ...($metadata ?? []),
                'livestream_processing_id' => $item['livestream_processing_id'] ?? null,
                'livestream_service_section_id' => $item['livestream_service_section_id'] ?? null,
                'openlp_search_title' => $item['openlp_search_title'] ?? null,
                'openlp_search_key' => $this->openLpSearchKey($item),
            ], fn (mixed $value): bool => $value !== null);
            $metadata = $metadata === [] ? null : $metadata;

            $assertions[] = [
                'assertion_key' => $this->assertionKey($position, $item, $title),
                'source_position' => $position,
                'evidence_kind' => $evidenceKind->value,
                'type' => (string) ($item['type'] ?? 'custom'),
                'section_type' => $this->scalarOrNull($item['section_type'] ?? null),
                'title' => $title,
                'source_title' => $sourceTitle,
                'normalized_title' => Str::of($sourceTitle ?? $title)->lower()->squish()->value(),
                'song_id' => is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null,
                'song_canonical_key' => $this->songCanonicalKey($item, $metadata, $canonicalKeysBySongId),
                'scripture_reference' => $this->scriptureReference($item, $metadata),
                'normalized_scripture_key' => $this->scalarOrNull($item['normalized_scripture_key'] ?? null),
                'start_seconds' => $this->numericOrNull($item['start_seconds'] ?? null),
                'end_seconds' => $this->numericOrNull($item['end_seconds'] ?? null),
                'confidence' => $this->confidence($item, $metadata),
                'metadata' => $metadata,
            ];
        }

        return $assertions;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertionKey(int $position, array $item, string $title): string
    {
        $stableIdentity = $item['assertion_key']
            ?? $item['livestream_service_section_id']
            ?? $item['canonical_identity']
            ?? null;

        if (is_scalar($stableIdentity) && (string) $stableIdentity !== '') {
            return (string) $stableIdentity;
        }

        return hash('sha256', "{$position}\0".(string) ($item['type'] ?? 'custom')."\0".$title);
    }

    private function scalarOrNull(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $metadata
     */
    private function scriptureReference(array $item, ?array $metadata): ?string
    {
        $value = $item['scripture_reference'] ?? $metadata['scripture_reference'] ?? null;

        return $this->scalarOrNull($value);
    }

    /**
     * A local `song_id` cannot survive export into another database, so the
     * catalogue's canonical key is resolved here — while the local id is still in
     * scope — and carried on the assertion as the portable song identity the
     * projector matches on.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $canonicalKeysBySongId
     */
    private function songCanonicalKey(array $item, ?array $metadata, array $canonicalKeysBySongId): ?string
    {
        $declared = $this->scalarOrNull(
            $item['song_canonical_key']
            ?? $metadata['song_canonical_key']
            ?? $metadata['linked_song_canonical_key']
            ?? null,
        );

        if ($declared !== null) {
            return $declared;
        }

        $songId = is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null;

        return $songId === null ? null : ($canonicalKeysBySongId[$songId] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function canonicalKeysBySongId(array $items): array
    {
        $songIds = [];

        foreach ($items as $item) {
            if (is_numeric($item['song_id'] ?? null)) {
                $songIds[] = (int) $item['song_id'];
            }
        }

        if ($songIds === []) {
            return [];
        }

        /** @var array<int, string> $keys */
        $keys = Song::query()
            ->whereIn('id', array_unique($songIds))
            ->whereNotNull('canonical_key')
            ->pluck('canonical_key', 'id')
            ->all();

        return $keys;
    }

    /** @param array<string, mixed> $item */
    private function openLpSearchKey(array $item): ?string
    {
        $searchTitle = $this->scalarOrNull($item['openlp_search_title'] ?? null);

        if ($searchTitle === null) {
            return null;
        }

        return Str::of($searchTitle)
            ->replaceEnd('@', '')
            ->lower()
            ->squish()
            ->value() ?: null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $metadata
     */
    private function confidence(array $item, ?array $metadata): ?float
    {
        $value = $item['confidence'] ?? $metadata['livestream_projection']['confidence'] ?? null;

        return $this->numericOrNull($value);
    }
}
