<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceEvidenceKind;
use App\Models\Song;
use App\Support\MojibakeRepair;
use BackedEnum;
use Illuminate\Support\Str;

class ChurchServiceAssertionNormalizer
{
    /** The width of every text column on `church_service_item_assertions`. */
    private const MaxTextLength = 255;

    public function __construct(
        private readonly ServiceItemCatalogueSongResolver $catalogueSongResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function normalize(array $items, ChurchServiceEvidenceKind $evidenceKind): array
    {
        $items = $this->resolveSongIdentity($items, $evidenceKind);
        $assertions = [];
        $canonicalKeysBySongId = $this->canonicalKeysBySongId($items);

        foreach (array_values($items) as $index => $item) {
            $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : $index + 1;
            // Repair double-encoded text before anything derives from it, so the stored title,
            // the assertion key and the match key all agree with what the operator wrote. The
            // banked archive parse cache is keyed on the source file's digest rather than on its
            // body, so results extracted before the ingest-side repair still arrive damaged.
            $title = MojibakeRepair::repair(trim((string) ($item['title'] ?? '')));
            $sourceTitle = is_string($item['source_title'] ?? null)
                ? MojibakeRepair::repair(trim($item['source_title']))
                : null;
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
                'title' => $this->boundedText($title),
                'source_title' => $this->boundedText($sourceTitle),
                'normalized_title' => $this->boundedText(Str::of($sourceTitle ?? $title)->lower()->squish()->value()),
                'song_id' => is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null,
                'song_canonical_key' => $this->songCanonicalKey($item, $metadata, $canonicalKeysBySongId),
                'scripture_reference' => $this->boundedText($this->scriptureReference($item, $metadata)),
                'normalized_scripture_key' => $this->boundedText($this->scalarOrNull($item['normalized_scripture_key'] ?? null)),
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

    /**
     * Fit free text to the `varchar(255)` columns that store it.
     *
     * Every text column on `church_service_item_assertions` is 255 characters,
     * and nothing upstream bounds what a parser may call a title. The 2026-08-11
     * Email staging run met the consequence: a conversational note was read as an
     * order of service and one long line became an item title, failing the insert
     * partway through a service.
     *
     * Truncation is lossy and is still the right trade. A title this long is
     * parser noise rather than a service item, the readable beginning is what an
     * operator needs in order to recognise it as noise, and the alternative on
     * offer is losing the whole service. `rtrim` keeps the result acceptable to
     * the items table's CHECK constraint, which requires a trimmed, non-empty
     * title.
     */
    private function boundedText(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) <= self::MaxTextLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::MaxTextLength));
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
     * Planned evidence resolves its song titles against the catalogue before it is
     * stored, so both sides of a later comparison carry a song link.
     *
     * Without this, every historic assertion has a null `song_canonical_key`,
     * {@see ChurchServiceProjector} never reaches its tier-1 `song_identity` match, and
     * song matching degrades to the anchored-title and anchored-position fallbacks —
     * which duplicate a song per source and can pair two different songs by position.
     * The catalogue is the one vocabulary Email and OpenLP can each reach independently;
     * their raw text agrees on almost nothing, because an Email plan carries the
     * projectionist's shorthand while OpenLP carries the archive file's own title.
     *
     * Observed evidence is excluded because a detected run resolves songs from lyrics
     * and OCR, which is stronger than a heard title. Manual evidence is excluded because
     * a person's decision is not something machine inference may quietly restate.
     *
     * Maintainer decision 2026-08-21 and the invariant 4 amendment it required:
     * see §2.5 and §3.2 of `docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md`.
     * The resolved key must stay re-derivable from the source snapshot plus the
     * catalogue, so a catalogue change is a versioned reprojection rather than an edit.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveSongIdentity(array $items, ChurchServiceEvidenceKind $evidenceKind): array
    {
        if ($evidenceKind !== ChurchServiceEvidenceKind::Planned) {
            return $items;
        }

        return $this->catalogueSongResolver->resolveItems($items);
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
