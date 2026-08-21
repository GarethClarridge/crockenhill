<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The defect the 2026-08-21 song-identity decision exists to fix is in the projection,
 * not in the corroboration gate: with a null `song_canonical_key` on every historic
 * assertion, {@see ChurchServiceProjector} never reaches its tier-1 `song_identity`
 * match, so each song is projected once per source and the position fallback can pair
 * two different songs.
 *
 * These are the service 297 titles from the catalogued rehearsal (morning, 2021-10-24),
 * where both sources list the same six songs in the same order and raw string
 * comparison agreed on none of them.
 *
 * A fall in conflict counts alone does NOT demonstrate the fix — that is what the
 * rejected comparison-time placement would have produced, while still projecting the
 * duplicates. So these cases assert the projected items.
 */
class HistoricSongProjectionMergeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{catalogue: string, email: string, openlp: string}> */
    private const SongPairs = [
        [
            'catalogue' => 'Come People of the Risen King',
            'email' => 'NIP ‘Come People of the Risen King’',
            'openlp' => 'Come People Of The Risen King',
        ],
        [
            'catalogue' => 'I Serve a Risen Saviour',
            'email' => 'NIP ‘I serve a Risen Saviour’',
            'openlp' => 'I Serve a Risen Saviour',
        ],
        [
            'catalogue' => 'Speak O Lord',
            'email' => 'NIP ‘Speak o Lord’',
            'openlp' => 'Speak O Lord',
        ],
    ];

    #[Test]
    public function both_sources_project_one_item_per_song_rather_than_one_per_source(): void
    {
        $service = $this->serviceWithBothSources();

        $songItems = $this->projectedSongItems($service);

        self::assertCount(
            count(self::SongPairs),
            $songItems,
            'Each song must project once. One item per source per song is the duplication defect.',
        );
    }

    #[Test]
    public function every_projected_song_carries_the_identity_of_the_song_it_names(): void
    {
        $service = $this->serviceWithBothSources();

        foreach ($this->projectedSongItems($service) as $item) {
            $expectedKey = Song::query()
                ->whereKey($this->songIdForTitle($item['title']))
                ->value('canonical_key');

            // The trailing occurrence index distinguishes a song sung twice in one
            // service; each song here is sung once, so every occurrence is #1.
            self::assertSame(
                'song:'.$expectedKey.'#1',
                $item['canonical_identity'],
                "Projected item '{$item['title']}' is filed under another song's identity.",
            );
        }
    }

    #[Test]
    public function corroborated_song_dimensions_raise_no_mismatch(): void
    {
        $service = $this->serviceWithBothSources();

        $projection = app(ChurchServiceProjector::class)->project(
            $service->fresh('sourceRecords.assertions')->sourceRecords,
        );

        $mismatches = array_values(array_filter(
            $projection->conflicts,
            static fn (array $conflict): bool => ($conflict['kind'] ?? null) === 'corroboration_mismatch',
        ));

        self::assertSame([], $mismatches);
    }

    private function serviceWithBothSources(): ChurchService
    {
        foreach (self::SongPairs as $pair) {
            Song::factory()->create([
                'title' => $pair['catalogue'],
                'canonical_key' => Song::canonicalizeKey($pair['catalogue']),
            ]);
        }

        $service = ChurchService::factory()->create([
            'date' => '2021-10-24',
            'service' => SermonService::Morning,
        ]);

        $this->ingest($service, ChurchServiceSource::Email, 'email');
        $this->ingest($service, ChurchServiceSource::OpenLp, 'openlp');

        return $service;
    }

    private function ingest(ChurchService $service, ChurchServiceSource $source, string $titleKey): void
    {
        $items = [];

        foreach (array_values(self::SongPairs) as $index => $pair) {
            $items[] = [
                'position' => $index + 1,
                'type' => 'songs',
                'title' => $pair[$titleKey],
                'source_title' => $pair[$titleKey],
            ];
        }

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: $source,
            sourceKey: $source->value.'-1',
            inputHash: CanonicalJson::hash($items),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize(
                $items,
                ChurchServiceEvidenceKind::Planned,
            ),
            processingFingerprint: ['format' => 'test', 'version' => 1],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function projectedSongItems(ChurchService $service): array
    {
        $projection = app(ChurchServiceProjector::class)->project(
            $service->fresh('sourceRecords.assertions')->sourceRecords,
        );

        return array_values(array_filter(
            $projection->items,
            static fn (array $item): bool => ($item['type'] ?? null) === 'songs',
        ));
    }

    private function songIdForTitle(string $projectedTitle): int
    {
        foreach (self::SongPairs as $pair) {
            if ($projectedTitle === $pair['email'] || $projectedTitle === $pair['openlp']) {
                return (int) Song::query()
                    ->where('canonical_key', Song::canonicalizeKey($pair['catalogue']))
                    ->value('id');
            }
        }

        self::fail("Projected title '{$projectedTitle}' matches neither source's text.");
    }
}
