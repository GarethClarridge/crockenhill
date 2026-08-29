<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileSelfProjectedMergeProposalsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_retires_a_proposal_the_run_raised_against_its_own_items(): void
    {
        $proposal = $this->proposal(itemLinkedToRun: true);

        $this->artisan('service:reconcile-self-projected-proposals', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            ChurchServiceProposalStatus::Stale,
            $proposal->refresh()->status,
        );
    }

    /**
     * The rule the pilot tripped is still doing real work: fourteen of the
     * twenty-one pending proposals stand on services that already held
     * evidence-free items before the pilot touched them.
     */
    #[Test]
    public function it_leaves_a_proposal_standing_on_a_genuine_legacy_item(): void
    {
        $proposal = $this->proposal(itemLinkedToRun: false);

        $this->artisan('service:reconcile-self-projected-proposals', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            ChurchServiceProposalStatus::Pending,
            $proposal->refresh()->status,
        );
    }

    #[Test]
    public function it_changes_nothing_without_apply(): void
    {
        $proposal = $this->proposal(itemLinkedToRun: true);

        $this->artisan('service:reconcile-self-projected-proposals')->assertSuccessful();

        $this->assertSame(
            ChurchServiceProposalStatus::Pending,
            $proposal->refresh()->status,
        );
    }

    #[Test]
    public function it_leaves_a_proposal_carrying_another_conflict_alone(): void
    {
        $proposal = $this->proposal(itemLinkedToRun: true);
        $proposal->forceFill([
            'conflicts' => [
                ['kind' => 'unnormalized_legacy_items', 'reason' => 'Legacy items.'],
                ['kind' => 'reviewed_service', 'reason' => 'A person reviewed this service.'],
            ],
        ])->save();

        $this->artisan('service:reconcile-self-projected-proposals', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            ChurchServiceProposalStatus::Pending,
            $proposal->refresh()->status,
        );
    }

    private function proposal(bool $itemLinkedToRun): ChurchServiceMergeProposal
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2020-03-22',
            'service' => SermonService::Morning->value,
        ]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Opening prayer',
            'position' => 1,
            'source' => ChurchServiceItemSource::Livestream,
            'metadata' => [],
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'reconcile-run',
            'church_service_id' => $churchService->id,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $itemLinkedToRun ? $item->id : null,
            'section_order' => 1,
            'source_segment_ids' => [],
        ]);
        $record = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => ChurchServiceSource::Livestream,
            'source_key' => 'reconcile-run|v1',
        ]);

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $churchService->id,
            'trigger_source_record_id' => $record->id,
            'status' => ChurchServiceProposalStatus::Pending,
            'conflicts' => [
                ['kind' => 'unnormalized_legacy_items', 'reason' => 'Legacy items.'],
            ],
        ]);
    }
}
