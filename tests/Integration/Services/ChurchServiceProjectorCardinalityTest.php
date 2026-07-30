<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R8 WP3 cardinality and ambiguity semantics: an item asserted more than once in a
 * service is more than one canonical occurrence, and a pairing the projector had to
 * guess is a review conflict rather than a silent decision.
 */
class ChurchServiceProjectorCardinalityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_song_sung_twice_in_one_service_projects_as_two_canonical_items(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
            $this->item(2, 'custom', 'Sermon', 'sermon'),
            $this->item(3, 'songs', 'Amazing Grace', 'amazing-grace'),
        ]);

        $items = $service->fresh()->items()->orderBy('position')->get();

        $this->assertSame(
            ['Amazing Grace', 'Sermon', 'Amazing Grace'],
            $items->pluck('title')->all(),
        );
        $this->assertSame(
            ['song:amazing-grace#1', 'custom:sermon#1', 'song:amazing-grace#2'],
            $items->pluck('canonical_identity')->all(),
        );
    }

    #[Test]
    public function repeated_generic_items_from_one_source_stay_distinct(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Prayer', 'prayer'),
            $this->item(2, 'custom', 'Bible reading', 'bible-reading'),
            $this->item(3, 'custom', 'Prayer', 'prayer'),
        ]);

        $this->assertCount(3, $service->fresh()->items);
    }

    #[Test]
    public function cross_source_repeats_pair_up_by_occurrence(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
            $this->item(2, 'custom', 'Sermon', 'sermon'),
            $this->item(3, 'songs', 'Amazing Grace', 'amazing-grace'),
        ]);
        $this->ingest($service, ChurchServiceSource::Livestream, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
            $this->item(2, 'custom', 'Sermon', 'sermon'),
            $this->item(3, 'songs', 'Amazing Grace', 'amazing-grace'),
        ], ChurchServiceEvidenceKind::Observed);

        $items = $service->fresh()->items()->orderBy('position')->get();

        $this->assertCount(3, $items);
        $this->assertSame(
            [
                ChurchServiceOccurrenceState::PlannedAndObserved,
                ChurchServiceOccurrenceState::PlannedAndObserved,
                ChurchServiceOccurrenceState::PlannedAndObserved,
            ],
            $items->pluck('occurrence_state')->all(),
        );
    }

    #[Test]
    public function an_over_sung_song_becomes_a_second_observed_only_occurrence(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
        ]);
        $this->ingest($service, ChurchServiceSource::Livestream, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
            $this->item(2, 'songs', 'Amazing Grace', 'amazing-grace'),
        ], ChurchServiceEvidenceKind::Observed);

        $items = $service->fresh()->items()->orderBy('position')->get();

        $this->assertCount(2, $items);
        $this->assertSame(
            [
                ChurchServiceOccurrenceState::PlannedAndObserved,
                ChurchServiceOccurrenceState::ObservedOnly,
            ],
            $items->pluck('occurrence_state')->all(),
        );
    }

    #[Test]
    public function every_arrival_order_of_repeated_items_produces_the_same_manifest(): void
    {
        $hashes = [];

        foreach ([[1, 2], [2, 1]] as $index => $order) {
            $service = ChurchService::factory()->create(['date' => '2026-10-0'.($index + 1)]);
            $revisions = [
                1 => fn () => $this->ingest($service, ChurchServiceSource::Email, [
                    $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
                    $this->item(2, 'custom', 'Prayer', 'prayer'),
                    $this->item(3, 'songs', 'Amazing Grace', 'amazing-grace'),
                ]),
                2 => fn () => $this->ingest($service, ChurchServiceSource::OpenLp, [
                    $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
                    $this->item(2, 'custom', 'Prayer', 'prayer'),
                    $this->item(3, 'songs', 'Amazing Grace', 'amazing-grace'),
                ]),
            ];

            foreach ($order as $step) {
                $revisions[$step]();
            }

            $hashes[] = $service->fresh()->canonical_hash;
        }

        $this->assertCount(1, array_unique($hashes));
    }

    #[Test]
    public function an_ambiguous_repeat_pairing_is_reported_as_a_conflict(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Prayer', 'prayer'),
            $this->item(2, 'custom', 'Prayer', 'prayer'),
        ]);
        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'custom', 'Prayer', 'prayer'),
        ]);

        $projection = app(ChurchServiceProjector::class)->project(
            ChurchServiceSourceRecord::query()
                ->whereBelongsTo($service, 'churchService')
                ->with('assertions.sourceRecord')
                ->get(),
        );

        $this->assertNotSame([], $projection->conflicts);
        $this->assertSame(
            'ambiguous_repeat_match',
            $projection->conflicts[0]['kind'],
        );
    }

    #[Test]
    public function sources_that_disagree_about_order_report_an_inversion_conflict(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'songs', 'Amazing Grace', 'amazing-grace'),
            $this->item(2, 'songs', 'How Great Thou Art', 'how-great-thou-art'),
        ]);
        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'songs', 'How Great Thou Art', 'how-great-thou-art'),
            $this->item(2, 'songs', 'Amazing Grace', 'amazing-grace'),
        ]);

        $projection = app(ChurchServiceProjector::class)->project(
            ChurchServiceSourceRecord::query()
                ->whereBelongsTo($service, 'churchService')
                ->with('assertions.sourceRecord')
                ->get(),
        );

        $this->assertContains(
            'order_inversion',
            array_column($projection->conflicts, 'kind'),
        );
    }

    #[Test]
    public function every_assertion_receives_a_recorded_field_decision(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'songs', 'Email song title', 'opening-song'),
            $this->item(2, 'custom', 'Sermon', 'sermon'),
        ]);
        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'songs', 'OpenLP song title', 'opening-song'),
        ]);

        $projection = app(ChurchServiceProjector::class)->project(
            ChurchServiceSourceRecord::query()
                ->whereBelongsTo($service, 'churchService')
                ->with('assertions.sourceRecord')
                ->get(),
        );

        $decisions = collect($projection->fieldDecisions);

        $this->assertCount(
            3,
            $decisions,
            'Every assertion across every active source revision needs its own recorded decision.',
        );

        $songDecisions = $decisions->filter(
            fn (array $decision): bool => $decision['assertion_key'] === 'opening-song-1',
        );

        $this->assertCount(2, $songDecisions);

        foreach ($songDecisions as $decision) {
            $this->assertSame('song_identity', $decision['match_method']);
            $this->assertSame('song:opening-song#1', $decision['canonical_identity']);
            $this->assertNotSame('', trim($decision['explanation']));
        }

        $openLpDecision = $songDecisions->firstOrFail(
            fn (array $decision): bool => $decision['source'] === 'openlp',
        );

        $this->assertContains('title', $openLpDecision['selected_fields']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingest(
        ChurchService $service,
        ChurchServiceSource $source,
        array $items,
        ChurchServiceEvidenceKind $evidenceKind = ChurchServiceEvidenceKind::Planned,
    ): void {
        app(IngestChurchServiceSourceRevision::class)->execute(
            $service,
            new ChurchServiceSourceRevision(
                source: $source,
                sourceKey: "{$source->value}-{$service->getKey()}",
                inputHash: CanonicalJson::hash($items),
                assertions: app(ChurchServiceAssertionNormalizer::class)->normalize($items, $evidenceKind),
                processingFingerprint: ['format' => 'test', 'version' => 1],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $position, string $type, string $title, string $identity): array
    {
        $item = [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $identity,
            'assertion_key' => "{$identity}-{$position}",
        ];

        if ($type === 'songs') {
            $item['song_canonical_key'] = $identity;
        }

        return $item;
    }
}
