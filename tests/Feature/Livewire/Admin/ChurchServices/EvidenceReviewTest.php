<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvidenceReviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function only_an_admin_can_mutate_the_evidence_review(): void
    {
        $service = ChurchService::factory()->create();
        $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $user = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($user)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('selectAllPendingEvidence')
            ->assertForbidden();
    }

    #[Test]
    public function it_shows_all_source_proposals_assertions_and_occurrence_badges(): void
    {
        $service = ChurchService::factory()->create([
            'summary' => 'Current summary',
            'reviewed_canonical_revision' => 0,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Email item',
            'occurrence_state' => 'planned_and_observed',
        ]);
        $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $this->createProposal($service, ChurchServiceSource::Livestream, 'Observed item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Review source evidence')
            ->assertSee('Email item')
            ->assertSee('Observed item')
            ->assertSee('Planned And Observed')
            ->assertSee('Select source assertion')
            ->assertSee('Current service content')
            ->assertSee('Proposed service content');
    }

    #[Test]
    public function it_resolves_only_the_selected_proposals_and_preserves_all_history(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Current item',
        ]);
        $email = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $openLp = $this->createProposal($service, ChurchServiceSource::OpenLp, 'OpenLP item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("selectedProposals.{$openLp->id}", false)
            ->set("proposalResolutions.{$email->id}", 'rejected')
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors()
            ->assertDispatched(
                'notify',
                type: 'success',
                message: 'Selected evidence reviewed. Source records and proposal history were preserved.',
            );

        $this->assertSame(ChurchServiceProposalStatus::Rejected, $email->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $openLp->fresh()->status);
        $this->assertDatabaseCount('church_service_merge_proposals', 2);
        $this->assertDatabaseCount('church_service_source_records', 3);
        $this->assertDatabaseCount('church_service_review_sessions', 1);
        $this->assertSame([$email->id], $service->reviewSessions()->firstOrFail()->included_proposal_ids);
    }

    #[Test]
    public function it_can_review_every_current_proposal_and_apply_custom_manual_values(): void
    {
        $service = ChurchService::factory()->create();
        $email = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $openLp = $this->createProposal($service, ChurchServiceSource::OpenLp, 'OpenLP item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set('evidenceReviewItems.0.title', 'Reviewed custom title')
            ->set('evidenceSummary', 'Reviewed summary')
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors();

        $service->refresh();

        $this->assertSame('Reviewed summary', $service->summary);
        $this->assertSame('Reviewed custom title', $service->items()->firstOrFail()->title);
        $this->assertSame($service->canonical_revision, $service->reviewed_canonical_revision);
        $this->assertSame('manual', $service->source_summary);
        $this->assertSame(
            [ChurchServiceProposalStatus::Accepted, ChurchServiceProposalStatus::Accepted],
            [$email->fresh()->status, $openLp->fresh()->status],
        );
    }

    #[Test]
    public function it_stops_when_the_canonical_revision_or_proposal_set_changed(): void
    {
        $service = ChurchService::factory()->create();
        $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $component = Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service]);

        $service->increment('canonical_revision');

        $component
            ->call('reviewSelectedEvidence')
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'This service changed since you opened it. Reload the page before reviewing the evidence.',
            );

        $service->decrement('canonical_revision');
        $this->createProposal($service, ChurchServiceSource::OpenLp, 'Late item');

        $component
            ->call('$refresh')
            ->assertSee('New evidence arrived after this screen loaded.')
            ->call('reviewSelectedEvidence')
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'New evidence arrived after this screen loaded. Reload before submitting your review.',
            );

        $this->assertDatabaseCount('church_service_review_sessions', 0);
    }

    private function createProposal(
        ChurchService $service,
        ChurchServiceSource $source,
        string $title,
    ): ChurchServiceMergeProposal {
        $record = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => $source,
            'source_key' => "{$source->value}-artifact-{$title}",
            'service_content' => [
                'summary' => "{$source->value} summary",
                'notices' => [],
                'chapter_markers' => [],
            ],
        ]);
        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $record->id,
            'assertion_key' => 'item-1',
            'source_position' => 1,
            'evidence_kind' => $source === ChurchServiceSource::Livestream
                ? ChurchServiceEvidenceKind::Observed
                : ChurchServiceEvidenceKind::Planned,
            'title' => $title,
            'normalized_title' => mb_strtolower($title),
        ]);

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'trigger_source_record_id' => $record->id,
            'base_canonical_revision' => $service->canonical_revision,
            'base_canonical_hash' => $service->canonical_hash,
            'proposed_items' => [[
                'canonical_identity' => 'custom:'.mb_strtolower($title),
                'position' => 1,
                'type' => 'custom',
                'section_type' => null,
                'source' => $source->value,
                'title' => $title,
                'source_title' => $title,
                'openlp_search_title' => null,
                'song_id' => null,
                'song_canonical_key' => null,
                'scripture_reference' => null,
                'occurrence_state' => $source === ChurchServiceSource::Livestream
                    ? 'observed_only'
                    : 'planned_only',
                'metadata' => [],
            ]],
        ]);
    }
}
