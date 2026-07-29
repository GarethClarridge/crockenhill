<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceSourceRecord;
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
