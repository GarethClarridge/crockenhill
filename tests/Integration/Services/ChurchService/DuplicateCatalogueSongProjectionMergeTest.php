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
 * Catalogue titles often carry the praise number inline ("Facing A Task Unfinished
 * #618") even though `songs.praise_number` holds it in its own column, so the stored
 * canonical key inherits it. A hymn imported once with its number and once without
 * therefore exists as two catalogue rows under two canonical keys.
 *
 * {@see ChurchServiceProjector::matchPair()} used to hard-stop as soon as two strong
 * identities differed, before any title tier could run, so each source projected its
 * own item and the hymn was duplicated.
 *
 * Measured on the 2026-08-24 corpus of record (10,696 assertions, 696 distinct song
 * identities): seven hymn families are catalogued twice this way, colliding inside six
 * services, and every collision is an email/OpenLP pair. The same sweep found exactly
 * one base title carrying two different praise numbers — the zero-padding pair below —
 * so the relaxation merges seven pairs and nothing else.
 */
class DuplicateCatalogueSongProjectionMergeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_hymn_catalogued_with_and_without_its_praise_number_projects_once(): void
    {
        // The real service 459/471 shape: email carries the numbered title, OpenLP the bare one.
        $items = $this->projectedSongItems(
            $this->serviceWithSources(
                catalogueTitles: ['Facing A Task Unfinished #618', 'Facing a task unfinished'],
                emailTitle: 'Facing A Task Unfinished #618',
                openLpTitle: 'Facing a task unfinished',
            )
        );

        self::assertCount(
            1,
            $items,
            'One hymn catalogued twice must still project one item, not one per source.',
        );
    }

    #[Test]
    public function zero_padding_alone_does_not_split_a_hymn(): void
    {
        // Service 538 (2025-02-02): "#024b" against "#24b".
        $items = $this->projectedSongItems(
            $this->serviceWithSources(
                catalogueTitles: ['This Earth Belongs To God #024b', 'This Earth Belongs To God #24b'],
                emailTitle: 'This Earth Belongs To God #024b',
                openLpTitle: 'This Earth Belongs To God #24b',
            )
        );

        self::assertCount(1, $items, 'Praise numbers differing only in leading zeros name one hymn.');
    }

    #[Test]
    public function two_different_praise_numbers_under_one_title_stay_separate(): void
    {
        // The guard: a shared title can cover genuinely distinct settings, and the
        // praise number is the only thing separating them. Merging here would be wrong.
        $items = $this->projectedSongItems(
            $this->serviceWithSources(
                catalogueTitles: ['Amazing Grace #100', 'Amazing Grace #200'],
                emailTitle: 'Amazing Grace #100',
                openLpTitle: 'Amazing Grace #200',
            )
        );

        self::assertCount(
            2,
            $items,
            'Two different praise numbers are two different hymns and must not be merged.',
        );
    }

    /**
     * @param  list<string>  $catalogueTitles
     */
    private function serviceWithSources(array $catalogueTitles, string $emailTitle, string $openLpTitle): ChurchService
    {
        foreach ($catalogueTitles as $title) {
            Song::factory()->create([
                'title' => $title,
                'canonical_key' => Song::canonicalizeKey($title),
            ]);
        }

        $service = ChurchService::factory()->create([
            'date' => '2025-02-02',
            'service' => SermonService::Morning,
        ]);

        $this->ingest($service, ChurchServiceSource::Email, $emailTitle);
        $this->ingest($service, ChurchServiceSource::OpenLp, $openLpTitle);

        return $service;
    }

    private function ingest(ChurchService $service, ChurchServiceSource $source, string $title): void
    {
        $items = [[
            'position' => 1,
            'type' => 'songs',
            'title' => $title,
            'source_title' => $title,
        ]];

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
}
