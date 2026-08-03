<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceSourceRevisionLineageInspector;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceProjectorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function every_machine_source_arrival_order_produces_the_same_projection(): void
    {
        $manifests = [];

        foreach ($this->sourcePermutations() as $index => $sources) {
            $service = ChurchService::factory()->create(['date' => '2026-09-0'.($index + 1)]);

            foreach ($sources as $source) {
                $this->ingest($service, $source);
            }

            $service->refresh();
            $manifests[] = CanonicalJson::encode([
                'hash' => $service->canonical_hash,
                'source_summary' => $service->source_summary,
                'summary' => $service->summary,
                'notices' => $service->notices,
                'chapter_markers' => $service->chapter_markers,
                'items' => $service->items()
                    ->orderBy('position')
                    ->get([
                        'position',
                        'canonical_identity',
                        'type',
                        'title',
                        'source_title',
                        'occurrence_state',
                    ])
                    ->toArray(),
            ]);
        }

        $this->assertCount(1, array_unique($manifests), implode("\n", array_unique($manifests)));
    }

    #[Test]
    public function it_derives_occurrence_states_and_field_authority_from_all_active_evidence(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Livestream);
        $this->ingest($service, ChurchServiceSource::Email);
        $this->ingest($service, ChurchServiceSource::OpenLp);

        $items = $service->items()->get()->keyBy('canonical_identity');

        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedAndObserved,
            $items['song:opening-song#1']->occurrence_state,
        );
        $this->assertSame('OpenLP song title', $items['song:opening-song#1']->title);
        $this->assertSame(
            ChurchServiceOccurrenceState::ObservedOnly,
            $items['custom:welcome#1']->occurrence_state,
        );
        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedOnly,
            $items['bibles:john 3:16#1']->occurrence_state,
        );
        $this->assertSame('Email sermon title', $items['custom:sermon#1']->title);
        $this->assertSame('mixed', $service->fresh()->source_summary);
        $this->assertSame(
            [ChurchServiceSource::Email->value, ChurchServiceSource::Livestream->value, ChurchServiceSource::OpenLp->value],
            collect($items['song:opening-song#1']->provenanceSources())->map->value->sort()->values()->all(),
        );
    }

    #[Test]
    public function planned_only_items_are_inserted_between_observed_plan_anchors(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingestItems($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Welcome', 'welcome'),
            $this->item(2, 'custom', 'Prayer', 'prayer'),
            $this->item(3, 'custom', 'Sermon', 'sermon'),
        ]);
        $this->ingestItems($service, ChurchServiceSource::Livestream, [
            [...$this->item(1, 'custom', 'Welcome', 'welcome'), 'start_seconds' => 10.0],
            [...$this->item(2, 'custom', 'Sermon', 'sermon'), 'start_seconds' => 50.0],
        ], ChurchServiceEvidenceKind::Observed);

        $this->assertSame(
            ['Welcome', 'Prayer', 'Sermon'],
            $service->fresh()->items()->orderBy('position')->pluck('title')->all(),
        );
    }

    #[Test]
    public function compatible_incomplete_sources_match_without_a_proposal(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingestItems($service, ChurchServiceSource::Email, [[
            ...$this->item(1, 'songs', 'Come Thou Fount', 'come thou fount'),
            'song_canonical_key' => null,
        ]]);
        $this->ingestItems($service, ChurchServiceSource::OpenLp, [[
            ...$this->item(1, 'songs', 'Come Thou Fount of Every Blessing', 'come thou fount'),
            'song_canonical_key' => 'come-thou-fount',
        ]]);

        $service = $service->fresh();

        $this->assertCount(1, $service->items);
        $this->assertSame('song:come-thou-fount#1', $service->items->sole()->canonical_identity);
        $this->assertSame([], $service->mergeProposals()->get()->all());
    }

    #[Test]
    public function projection_policy_version_is_disjoint_from_processing_fingerprints(): void
    {
        $service = ChurchService::factory()->create();
        $this->ingest($service, ChurchServiceSource::Email);
        $this->ingest($service, ChurchServiceSource::Livestream);
        $records = $this->loadedRecords($service);
        $processingFingerprints = $this->storedProcessingFingerprints($service);

        // §9.4's loop advances the projector repeatedly over a corpus processed
        // exactly once, so re-projection must never touch media, a queue or a
        // provider. These fakes are the regression guard, not decoration.
        Bus::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Event::fake();

        $defaultProjector = app(ChurchServiceProjector::class);
        $changedPolicyProjector = new ChurchServiceProjector(
            app(ChurchServiceSourceRevisionLineageInspector::class),
            ChurchServiceProjector::PROJECTION_POLICY_VERSION + 1,
        );

        $defaultProjection = $defaultProjector->project($records);
        $changedProjection = $changedPolicyProjector->project($records);

        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();

        $this->assertNotSame($defaultProjection->policyFingerprint, $changedProjection->policyFingerprint);
        $this->assertSame($defaultProjection->items, $changedProjection->items);
        $this->assertSame($defaultProjection->hash, $changedProjection->hash);

        // Re-read from the database: comparing the same in-memory models to
        // themselves could not detect a write.
        $this->assertSame($processingFingerprints, $this->storedProcessingFingerprints($service));
    }

    #[Test]
    public function projecting_already_active_records_is_the_same_as_projecting_every_revision(): void
    {
        $service = ChurchService::factory()->create();
        $normalizer = app(ChurchServiceAssertionNormalizer::class);

        foreach (['Welcome', 'Welcome Everyone'] as $title) {
            $assertions = $normalizer->normalize(
                [$this->item(1, 'custom', $title, Str::slug($title))],
                ChurchServiceEvidenceKind::Planned,
            );

            app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
                source: ChurchServiceSource::Email,
                sourceKey: 'message-1|corrected',
                inputHash: CanonicalJson::hash($assertions),
                assertions: $assertions,
                processingFingerprint: ['format' => 'test', 'version' => 1],
            ));
        }

        $projector = app(ChurchServiceProjector::class);
        $records = $this->loadedRecords($service);

        $this->assertCount(2, $records);

        $fromEveryRevision = $projector->project($records);
        $fromActiveRevisions = $projector->project($projector->activeSourceRecords($records));

        $this->assertSame(['Welcome Everyone'], array_column($fromEveryRevision->items, 'title'));
        $this->assertSame($fromEveryRevision->hash, $fromActiveRevisions->hash);
        $this->assertSame($fromEveryRevision->fieldDecisions, $fromActiveRevisions->fieldDecisions);
        $this->assertTrue($projector->hasCompleteAudit($projector->activeSourceRecords($records), $fromActiveRevisions));
    }

    /** @return Collection<int, ChurchServiceSourceRecord> */
    private function loadedRecords(ChurchService $service): Collection
    {
        return ChurchServiceSourceRecord::query()
            ->whereBelongsTo($service, 'churchService')
            ->with('assertions.sourceRecord')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    private function storedProcessingFingerprints(ChurchService $service): array
    {
        return ChurchServiceSourceRecord::query()
            ->whereBelongsTo($service, 'churchService')
            ->orderBy('id')
            ->pluck('processing_fingerprint')
            ->all();
    }

    #[Test]
    public function an_identical_revision_is_a_complete_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $revision = $this->revision(ChurchServiceSource::Email);

        $first = $action->execute($service, $revision);
        $canonicalRevision = $service->fresh()->canonical_revision;
        $second = $action->execute($service, $revision);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($first->sourceRecord->id, $second->sourceRecord->id);
        $this->assertSame($canonicalRevision, $service->fresh()->canonical_revision);
    }

    #[Test]
    public function explicit_revision_lineage_wins_when_capture_times_match(): void
    {
        $service = ChurchService::factory()->create();
        $capturedAt = now();
        $original = ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-07-29',
            'revision_hash' => str_repeat('f', 64),
            'captured_at' => $capturedAt,
        ]);
        $correction = ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-07-29',
            'revision_hash' => str_repeat('0', 64),
            'supersedes_id' => $original->id,
            'captured_at' => $capturedAt,
        ]);
        ChurchServiceItemAssertion::factory()->for($original, 'sourceRecord')->create([
            'assertion_key' => 'original-sermon',
            'title' => 'Original sermon',
            'normalized_title' => 'original sermon',
        ]);
        ChurchServiceItemAssertion::factory()->for($correction, 'sourceRecord')->create([
            'assertion_key' => 'corrected-sermon',
            'title' => 'Corrected sermon',
            'normalized_title' => 'corrected sermon',
        ]);

        $projection = app(ChurchServiceProjector::class)->project(
            $service->sourceRecords()->with(['assertions', 'assertions.sourceRecord'])->get(),
        );

        $this->assertSame(['Corrected sermon'], array_column($projection->items, 'title'));
    }

    #[Test]
    public function it_rejects_multiple_active_leaves_in_a_projection_lineage(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1',
            'revision_hash' => str_repeat('a', 64),
        ]);
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1',
            'revision_hash' => str_repeat('b', 64),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exactly one active leaf');

        app(ChurchServiceProjector::class)->project($service->sourceRecords()->with('assertions.sourceRecord')->get());
    }

    #[Test]
    public function it_rejects_a_projection_revision_that_supersedes_another_lineage(): void
    {
        $service = ChurchService::factory()->create();
        $predecessor = ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1',
            'revision_hash' => str_repeat('a', 64),
        ]);
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-2',
            'revision_hash' => str_repeat('b', 64),
            'supersedes_id' => $predecessor->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside its source lineage');

        app(ChurchServiceProjector::class)->project($service->sourceRecords()->with('assertions.sourceRecord')->get());
    }

    #[Test]
    public function a_manual_revision_is_the_complete_projection_and_content_authority(): void
    {
        $service = ChurchService::factory()->create();
        $this->ingest($service, ChurchServiceSource::Livestream);
        $this->ingest($service, ChurchServiceSource::Email);

        $manualRevision = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: 'review-1',
            inputHash: hash('sha256', 'review-1'),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize([
                $this->item(1, 'custom', 'Reviewed sermon', 'reviewed-sermon'),
            ], ChurchServiceEvidenceKind::Manual),
            processingFingerprint: ['format' => 'test', 'version' => 1],
            serviceContent: [
                'summary' => 'Reviewed summary',
                'notices' => [['title' => 'Reviewed notice', 'details' => null]],
                'chapter_markers' => [],
            ],
        );

        app(IngestChurchServiceSourceRevision::class)->execute($service, $manualRevision);

        $service->refresh();
        $this->assertSame('manual', $service->source_summary);
        $this->assertSame('Reviewed summary', $service->summary);
        $this->assertSame(['Reviewed sermon'], $service->items()->pluck('title')->all());
        $this->assertSame(
            ChurchServiceOccurrenceState::ManuallyConfirmed,
            $service->items()->firstOrFail()->occurrence_state,
        );
    }

    private function ingest(ChurchService $service, ChurchServiceSource $source): void
    {
        app(IngestChurchServiceSourceRevision::class)->execute(
            $service,
            $this->revision($source, (string) $service->getKey()),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingestItems(
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

    private function revision(ChurchServiceSource $source, string $keySuffix = 'default'): ChurchServiceSourceRevision
    {
        $items = match ($source) {
            ChurchServiceSource::Email => [
                $this->item(1, 'songs', 'Email song title', 'opening-song'),
                $this->item(2, 'custom', 'Email sermon title', 'sermon'),
            ],
            ChurchServiceSource::OpenLp => [
                $this->item(1, 'songs', 'OpenLP song title', 'opening-song'),
                $this->item(2, 'bibles', 'John 3:16', 'john 3:16'),
            ],
            ChurchServiceSource::Livestream => [
                $this->item(1, 'custom', 'Welcome', 'welcome'),
                $this->item(2, 'songs', 'Observed song title', 'opening-song'),
                $this->item(3, 'custom', 'Observed sermon title', 'sermon'),
            ],
            ChurchServiceSource::Manual => [],
        };
        $evidenceKind = $source === ChurchServiceSource::Livestream
            ? ChurchServiceEvidenceKind::Observed
            : ChurchServiceEvidenceKind::Planned;
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize($items, $evidenceKind);

        return new ChurchServiceSourceRevision(
            source: $source,
            sourceKey: "{$source->value}-{$keySuffix}",
            inputHash: CanonicalJson::hash($items),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test', 'version' => 1],
            serviceContent: $source === ChurchServiceSource::Livestream ? [
                'summary' => 'Projected summary',
                'notices' => [['title' => 'Notice', 'details' => 'Details']],
                'chapter_markers' => [['title' => 'Sermon', 'start_time' => 100.0, 'end_time' => 200.0]],
            ] : null,
        );
    }

    /**
     * @return array{position: int, type: string, title: string, source_title: string, canonical_identity: string, song_canonical_key?: string}
     */
    private function item(int $position, string $type, string $title, string $identity): array
    {
        $item = [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $identity,
            'canonical_identity' => $identity,
        ];

        if ($type === 'songs') {
            $item['song_canonical_key'] = $identity;
        }

        return $item;
    }

    /**
     * @return list<list<ChurchServiceSource>>
     */
    private function sourcePermutations(): array
    {
        $email = ChurchServiceSource::Email;
        $openLp = ChurchServiceSource::OpenLp;
        $livestream = ChurchServiceSource::Livestream;

        return [
            [$email, $openLp, $livestream],
            [$email, $livestream, $openLp],
            [$openLp, $email, $livestream],
            [$openLp, $livestream, $email],
            [$livestream, $email, $openLp],
            [$livestream, $openLp, $email],
        ];
    }
}
