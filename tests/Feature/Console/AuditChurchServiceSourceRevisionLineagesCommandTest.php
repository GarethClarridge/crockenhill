<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditChurchServiceSourceRevisionLineagesCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_passes_when_every_lineage_has_one_leaf(): void
    {
        $service = ChurchService::factory()->create();
        $first = $this->record($service, 'message-1', str_repeat('a', 64));
        $this->record($service, 'message-1', str_repeat('b', 64), $first->id);

        $this->artisan('service-tracking:audit-source-revision-lineages')
            ->assertExitCode(0)
            ->expectsOutputToContain('exactly one active leaf');
    }

    #[Test]
    public function it_reports_a_lineage_with_multiple_active_leaves(): void
    {
        $service = ChurchService::factory()->create();
        $this->record($service, 'message-1', str_repeat('a', 64));
        $this->record($service, 'message-1', str_repeat('b', 64));

        $this->artisan('service-tracking:audit-source-revision-lineages')
            ->assertExitCode(1)
            ->expectsOutputToContain('2 active leaves');
    }

    /**
     * Deleting a revision mid-chain used to null its successor's predecessor link
     * (ON DELETE SET NULL), producing a second active leaf that projection then
     * refuses for good. Cascading keeps the single-leaf invariant true by
     * construction.
     */
    #[Test]
    public function deleting_a_revision_takes_the_revisions_that_depended_on_it(): void
    {
        $service = ChurchService::factory()->create();
        $first = $this->record($service, 'message-1', str_repeat('a', 64));
        $second = $this->record($service, 'message-1', str_repeat('b', 64), $first->id);
        $third = $this->record($service, 'message-1', str_repeat('c', 64), $second->id);

        $second->delete();

        $this->assertDatabaseMissing('church_service_source_records', ['id' => $third->id]);
        $this->assertDatabaseHas('church_service_source_records', ['id' => $first->id]);

        $this->artisan('service-tracking:audit-source-revision-lineages')
            ->assertExitCode(0)
            ->expectsOutputToContain('exactly one active leaf');
    }

    #[Test]
    public function deleting_a_service_removes_its_whole_revision_chain(): void
    {
        $service = ChurchService::factory()->create();
        $first = $this->record($service, 'message-1', str_repeat('a', 64));
        $this->record($service, 'message-1', str_repeat('b', 64), $first->id);

        $service->delete();

        $this->assertSame(0, ChurchServiceSourceRecord::query()->count());
    }

    private function record(
        ChurchService $service,
        string $sourceKey,
        string $revisionHash,
        ?int $supersedesId = null,
    ): ChurchServiceSourceRecord {
        return ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => $sourceKey,
            'revision_hash' => $revisionHash,
            'supersedes_id' => $supersedesId,
        ]);
    }
}
