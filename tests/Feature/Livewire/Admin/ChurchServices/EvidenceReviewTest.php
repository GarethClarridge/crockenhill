<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceCanonicalFinalization;
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
use App\Queries\AdminAttentionCounts;
use App\Queries\ReviewInboxQuery;
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
    public function it_shows_the_recorded_match_explanation_and_never_invents_one(): void
    {
        $service = ChurchService::factory()->create();
        $proposal = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $record = $proposal->triggerSourceRecord;
        $proposal->forceFill([
            'field_decisions' => [
                "{$record->revision_hash}:item-1" => [
                    'assertion_key' => 'item-1',
                    'source' => 'email',
                    'source_key' => $record->source_key,
                    'canonical_identity' => 'custom:email item#1',
                    'match_method' => 'normalized_title',
                    'selected_fields' => ['title'],
                    'explanation' => 'Matched by normalised title and occurrence order only.',
                ],
            ],
            'conflicts' => [[
                'kind' => 'ambiguous_repeat_match',
                'reason' => 'Sources disagree about how many times this item occurred.',
            ]],
        ])->save();

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Matched by normalised title and occurrence order only.')
            ->assertSee('Ambiguous repeat match')
            ->assertSee('Sources disagree about how many times this item occurred.')
            ->assertDontSee('Matched deterministically by identity and source authority.');
    }

    #[Test]
    public function an_assertion_with_no_recorded_decision_is_shown_as_unexplained(): void
    {
        $service = ChurchService::factory()->create();
        $this->createProposal($service, ChurchServiceSource::Email, 'Email item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('No recorded match explanation — check this assertion yourself.')
            ->assertDontSee('Matched deterministically by identity and source authority.');
    }

    #[Test]
    public function partial_review_keeps_the_service_in_the_attention_inbox(): void
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
                message: 'Selected evidence saved. Remaining proposals still need review.',
            );

        $this->assertSame(ChurchServiceProposalStatus::Rejected, $email->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $openLp->fresh()->status);
        $service->refresh();
        $this->assertTrue($service->needs_review);
        $this->assertNull($service->canonical_finalization);
        $this->assertNull($service->reviewed_canonical_revision);
        $this->assertDatabaseCount('church_service_merge_proposals', 2);
        $this->assertDatabaseCount('church_service_source_records', 3);
        $this->assertDatabaseCount('church_service_review_sessions', 1);
        $review = $service->reviewSessions()->firstOrFail();
        $this->assertSame([$email->id], $review->included_proposal_ids);
        $this->assertNull($review->completed_at);
        $this->assertSame(1, app(AdminAttentionCounts::class)->counts()['pending_merges']);
        $this->assertSame(2, app(ReviewInboxQuery::class)->build()['counts']['services']);
    }

    #[Test]
    public function selected_proposal_without_an_explicit_resolution_fails_closed_and_remains_pending(): void
    {
        $service = ChurchService::factory()->create();
        $proposal = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors()
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Every selected proposal needs an explicit accept, reject or replace decision.',
            );

        $service->refresh();
        $proposal->refresh();
        $this->assertSame(ChurchServiceProposalStatus::Pending, $proposal->status);
        $this->assertNull($proposal->resolved_by_user_id);
        $this->assertNull($proposal->resolved_at);
        $this->assertTrue($service->needs_review);
        $this->assertDatabaseCount('church_service_review_sessions', 0);
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
            ->set("proposalResolutions.{$email->id}", 'accepted')
            ->set("proposalResolutions.{$openLp->id}", 'accepted')
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
    public function excluded_manual_items_are_recorded_as_explicit_review_decisions(): void
    {
        $service = ChurchService::factory()->create();
        $proposal = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set('evidenceReviewItems.0.included', false)
            ->set('evidenceReviewItems.0.rationale', 'This source item was a duplicate.')
            ->set("proposalResolutions.{$proposal->id}", 'accepted')
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors();

        $review = $service->reviewSessions()->with('decisions')->sole();
        $decision = $review->decisions->sole();

        $this->assertFalse($decision->included);
        $this->assertNull($decision->final_position);
        $this->assertSame('This source item was a duplicate.', $decision->rationale);
        $this->assertCount(0, $review->manualSourceRecord->assertions);
    }

    #[Test]
    public function a_completed_review_enumerates_prior_active_proposal_decisions(): void
    {
        $service = ChurchService::factory()->create();
        $resolved = $this->createProposal($service, ChurchServiceSource::Email, 'Already resolved');
        $pending = $this->createProposal($service, ChurchServiceSource::OpenLp, 'Still pending');
        $resolved->forceFill([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $this->admin->id,
            'resolved_at' => now(),
        ])->save();

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("proposalResolutions.{$pending->id}", 'rejected')
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors();

        $review = $service->reviewSessions()->sole();

        $this->assertSame(
            [$resolved->id, $pending->id],
            collect($review->included_proposal_ids)->sort()->values()->all(),
        );
        $this->assertSame(
            ['accepted', 'rejected'],
            collect($review->proposal_dispositions)->sortBy('proposal_id')->pluck('disposition')->values()->all(),
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

    #[Test]
    public function a_service_with_no_proposals_can_still_record_its_manual_revision(): void
    {
        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Existing item',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set('evidenceReviewItems.0.title', 'Reviewed without any proposal')
            ->call('reviewSelectedEvidence')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $service->refresh();
        $this->assertFalse($service->needs_review);
        $this->assertSame(ChurchServiceCanonicalFinalization::Manual, $service->canonical_finalization);

        $review = $service->reviewSessions()->sole();
        $this->assertSame([], $review->included_proposal_ids);
        $this->assertSame([], $review->proposal_dispositions);
        $this->assertNotNull($review->completed_at);
    }

    private function createProposal(
        ChurchService $service,
        ChurchServiceSource $source,
        string $title,
    ): ChurchServiceMergeProposal {
        $service->forceFill([
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ])->saveQuietly();

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
