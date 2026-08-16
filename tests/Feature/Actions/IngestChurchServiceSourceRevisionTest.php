<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceSourceRecord;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class IngestChurchServiceSourceRevisionTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_persists_an_immutable_revision_and_assertions(): void
    {
        $service = ChurchService::factory()->create();

        $result = app(IngestChurchServiceSourceRevision::class)->execute($service, $this->revision());

        $this->assertTrue($result->wasCreated);
        $this->assertSame(2, $result->sourceRecord->assertions->count());
        $this->assertDatabaseHas('church_service_source_records', [
            'church_service_id' => $service->id,
            'source' => 'email',
            'source_key' => 'message-1|morning:2026-07-29',
        ]);
    }

    #[Test]
    public function an_identical_revision_is_a_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);

        $first = $action->execute($service, $this->revision());
        $second = $action->execute($service, $this->revision());

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($first->sourceRecord->id, $second->sourceRecord->id);
        $this->assertSame(1, ChurchServiceSourceRecord::query()->count());
    }

    #[Test]
    public function a_changed_payload_creates_a_revision_linked_to_its_predecessor(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $first = $action->execute($service, $this->revision());
        $changed = $this->revision([
            $this->assertion(1, 'Opening Song'),
            $this->assertion(2, 'Changed Sermon'),
        ]);

        $second = $action->execute($service, $changed);

        $this->assertTrue($second->wasCreated);
        $this->assertSame($first->sourceRecord->id, $second->sourceRecord->supersedes_id);
        $this->assertSame(2, ChurchServiceSourceRecord::query()->count());
    }

    /**
     * Only the leaf is the current revision. Matching an ancestor's payload used
     * to report an idempotent no-op, which silently discarded the arriving
     * evidence and left canonical state on the correction being withdrawn.
     */
    #[Test]
    public function replaying_a_superseded_payload_is_refused_rather_than_treated_as_a_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $original = $action->execute($service, $this->revision());
        $action->execute($service, $this->revision([
            $this->assertion(1, 'Opening Song'),
            $this->assertion(2, 'Changed Sermon'),
        ]));

        try {
            $action->execute($service, $this->revision());
            $this->fail('Replaying a superseded payload should not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                "identical to source revision {$original->sourceRecord->id}, which has already been superseded",
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, ChurchServiceSourceRecord::query()->count());
        $this->assertSame(
            'Changed Sermon',
            $service->fresh()->items()->orderBy('position')->get()->last()->title,
        );
    }

    #[Test]
    public function an_identical_payload_on_the_current_leaf_stays_a_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $action->execute($service, $this->revision());
        $changed = $this->revision([
            $this->assertion(1, 'Opening Song'),
            $this->assertion(2, 'Changed Sermon'),
        ]);
        $leaf = $action->execute($service, $changed);

        $replayed = $action->execute($service, $changed);

        $this->assertFalse($replayed->wasCreated);
        $this->assertSame($leaf->sourceRecord->id, $replayed->sourceRecord->id);
        $this->assertSame(2, ChurchServiceSourceRecord::query()->count());
    }

    #[Test]
    public function replaying_a_manifest_authorised_cross_key_correction_is_a_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $original = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'original-message|morning:2026-07-29',
            inputHash: str_repeat('a', 64),
            assertions: [$this->assertion(1, 'Opening Song')],
            processingFingerprint: ['format' => 'test', 'version' => 1],
        );
        $correction = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'correction-message|morning:2026-07-29',
            inputHash: str_repeat('b', 64),
            assertions: [$this->assertion(1, 'Corrected Opening Song')],
            processingFingerprint: [
                'format' => 'test',
                'version' => 1,
                'manifest_supersedes_source_key' => $original->sourceKey,
            ],
            supersedesSourceKey: $original->sourceKey,
        );

        $action->execute($service, $original);
        $firstCorrection = $action->execute($service, $correction);
        $replayedCorrection = $action->execute($service, $correction);

        $this->assertTrue($firstCorrection->wasCreated);
        $this->assertFalse($replayedCorrection->wasCreated);
        $this->assertSame($firstCorrection->sourceRecord->id, $replayedCorrection->sourceRecord->id);
        $this->assertSame(2, ChurchServiceSourceRecord::query()->count());
    }

    #[Test]
    public function it_rejects_a_lineage_with_multiple_active_leaves(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-07-29',
            'revision_hash' => str_repeat('a', 64),
        ]);
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-07-29',
            'revision_hash' => str_repeat('b', 64),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one active leaf');

        app(IngestChurchServiceSourceRevision::class)->execute($service, $this->revision());
    }

    #[Test]
    public function it_rejects_a_successor_from_another_source_lineage(): void
    {
        $service = ChurchService::factory()->create();
        $predecessor = ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-07-29',
            'revision_hash' => str_repeat('a', 64),
        ]);
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::OpenLp,
            'source_key' => 'different-message|morning:2026-07-29',
            'revision_hash' => str_repeat('b', 64),
            'supersedes_id' => $predecessor->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different church service or source');

        app(IngestChurchServiceSourceRevision::class)->execute($service, $this->revision());
    }

    #[Test]
    public function it_refuses_a_manifest_correction_when_its_declared_predecessor_is_absent(): void
    {
        $service = ChurchService::factory()->create();
        $revision = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'correction|morning:2026-07-29',
            inputHash: str_repeat('a', 64),
            assertions: [$this->assertion(1, 'Corrected order')],
            processingFingerprint: ['format' => 'test', 'version' => 1],
            supersedesSourceKey: 'original|morning:2026-07-29',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declared Email predecessor is absent');

        app(IngestChurchServiceSourceRevision::class)->execute($service, $revision);
    }

    #[Test]
    public function a_failed_assertion_insert_rolls_back_the_source_record(): void
    {
        $service = ChurchService::factory()->create();
        $invalid = $this->revision([
            $this->assertion(1, 'Opening Song'),
            $this->assertion(1, 'Duplicate key'),
        ]);

        try {
            app(IngestChurchServiceSourceRevision::class)->execute($service, $invalid);
            $this->fail('Expected the duplicate assertion key to fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('church_service_source_records', 0);
            $this->assertDatabaseCount('church_service_item_assertions', 0);
        }
    }

    #[Test]
    public function an_outer_transaction_rollback_removes_evidence(): void
    {
        $service = ChurchService::factory()->create();

        try {
            DB::transaction(function () use ($service): void {
                app(IngestChurchServiceSourceRevision::class)->execute($service, $this->revision());

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('church_service_source_records', 0);
            $this->assertDatabaseCount('church_service_item_assertions', 0);
        }
    }

    #[Test]
    public function a_machine_revision_cannot_change_a_reviewed_canonical_revision(): void
    {
        $service = ChurchService::factory()->create([
            'canonical_revision' => 4,
            'canonical_hash' => str_repeat('b', 64),
            'reviewed_canonical_revision' => 4,
            'summary' => 'Reviewed summary',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Reviewed item',
        ]);

        app(IngestChurchServiceSourceRevision::class)->execute($service, $this->revision());

        $service->refresh();

        $this->assertSame(4, $service->canonical_revision);
        $this->assertSame(str_repeat('b', 64), $service->canonical_hash);
        $this->assertSame('Reviewed summary', $service->summary);
        $this->assertSame(['Reviewed item'], $service->items()->pluck('title')->all());
        $this->assertDatabaseHas('church_service_merge_proposals', [
            'church_service_id' => $service->id,
            'base_canonical_revision' => 4,
            'base_canonical_hash' => str_repeat('b', 64),
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function source_key_variants_share_one_portable_identity_for_every_ingress(): void
    {
        $action = app(IngestChurchServiceSourceRevision::class);

        foreach ([ChurchServiceSource::Email, ChurchServiceSource::OpenLp, ChurchServiceSource::Manual] as $source) {
            $service = ChurchService::factory()->create();
            $firstRecordId = null;

            foreach (['  CAFÉ | Sunday  ', 'cafe | sunday', 'café | sunday'] as $sourceKey) {
                $result = $action->execute($service, new ChurchServiceSourceRevision(
                    source: $source,
                    sourceKey: $sourceKey,
                    inputHash: str_repeat('a', 64),
                    assertions: [$this->assertion(1, 'Opening Song')],
                    processingFingerprint: ['format' => 'test', 'version' => 1],
                ));

                $firstRecordId ??= $result->sourceRecord->id;
                $this->assertSame($firstRecordId, $result->sourceRecord->id);
            }

            $record = $service->sourceRecords()->sole();
            $this->assertSame('cafe | sunday', $record->source_key);
            $this->assertSame(ChurchServiceSourceKey::identity('café | sunday'), $record->source_key_hash);
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $assertions
     */
    private function revision(?array $assertions = null): ChurchServiceSourceRevision
    {
        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'message-1|morning:2026-07-29',
            inputHash: str_repeat('a', 64),
            assertions: $assertions ?? [
                $this->assertion(1, 'Opening Song'),
                $this->assertion(2, 'Sermon'),
            ],
            processingFingerprint: ['format' => 'test', 'version' => 1],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assertion(int $position, string $title): array
    {
        return [
            'assertion_key' => (string) $position,
            'source_position' => $position,
            'evidence_kind' => 'planned',
            'type' => 'custom',
            'section_type' => null,
            'title' => $title,
            'source_title' => $title,
            'normalized_title' => strtolower($title),
            'song_id' => null,
            'song_canonical_key' => null,
            'scripture_reference' => null,
            'normalized_scripture_key' => null,
            'start_seconds' => null,
            'end_seconds' => null,
            'confidence' => null,
            'metadata' => null,
        ];
    }
}
