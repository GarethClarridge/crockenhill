<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceProposalStatus;
use App\Livewire\Admin\ChurchServices\ReviewChurchServiceProposalQueue;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewChurchServiceProposalQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_an_admin_can_apply_a_cross_service_proposal_rule(): void
    {
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);
        $user = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($user)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->set('selectedClassKey', $classKey)
            ->set('selectedProposalIds', [$proposal->id])
            ->call('applyDecisionRule')
            ->assertForbidden();
    }

    #[Test]
    public function only_an_admin_can_record_a_class_standing(): void
    {
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);
        $user = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($user)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->set('markClassKey', $classKey)
            ->set('markReason', 'Trying to mark without authorisation.')
            ->call('markClass')
            ->assertForbidden();
    }

    #[Test]
    public function it_applies_a_rule_to_the_explicitly_selected_class_members(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->call('selectClass', $classKey)
            ->set('disposition', 'rejected')
            ->set('rationale', 'This proposal is not supported by the corroborating source.')
            ->call('applyDecisionRule')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertSame(ChurchServiceProposalStatus::Rejected, $proposal->fresh()->status);
        $this->assertDatabaseHas('church_service_proposal_decision_rules', [
            'class_key' => $classKey,
            'reviewed_by_user_id' => $admin->id,
            'disposition' => 'rejected',
        ]);
    }

    #[Test]
    public function a_reviewer_can_leave_one_member_out_of_the_rule(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $keep = $this->proposal();
        $outlier = $this->proposal(day: 2);
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($keep);

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->call('selectClass', $classKey)
            ->assertSet('selectedProposalIds', [$keep->id, $outlier->id])
            ->call('toggleProposal', $classKey, $outlier->id)
            ->assertSet('selectedProposalIds', [$keep->id])
            ->set('rationale', 'The outlier is a genuine second song, not a casing variant.')
            ->call('applyDecisionRule')
            ->assertHasNoErrors();

        $this->assertSame(ChurchServiceProposalStatus::Accepted, $keep->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $outlier->fresh()->status);
    }

    #[Test]
    public function a_forged_proposal_from_another_class_is_refused(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $inClass = $this->proposal();
        $outsider = $this->proposal(day: 2, subject: 'custom:offering');
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($inClass);

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->set('selectedClassKey', $classKey)
            ->set('selectedProposalIds', [$inClass->id, $outsider->id])
            ->set('rationale', 'Forged selection spanning two classes.')
            ->call('applyDecisionRule')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame(ChurchServiceProposalStatus::Pending, $inClass->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $outsider->fresh()->status);
        $this->assertDatabaseCount('church_service_proposal_decision_rules', 0);
    }

    #[Test]
    public function an_irreducible_class_needs_a_measured_per_decision_time(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->call('startMarkingClass', $classKey)
            ->set('markStatus', ChurchServiceProposalClassReview::IRREDUCIBLE)
            ->set('markReason', 'The sources genuinely disagree about order.')
            ->call('markClass')
            ->assertHasErrors('markSecondsPerDecision');

        $this->assertDatabaseCount('church_service_proposal_class_reviews', 0);

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->call('startMarkingClass', $classKey)
            ->set('markStatus', ChurchServiceProposalClassReview::IRREDUCIBLE)
            ->set('markReason', 'The sources genuinely disagree about order.')
            ->set('markSecondsPerDecision', 90)
            ->call('markClass')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('church_service_proposal_class_reviews', [
            'class_key' => $classKey,
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'seconds_per_decision' => 90,
            'marked_by_user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function the_queue_shows_the_class_subject_and_its_affected_services(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->proposal();

        Livewire::actingAs($admin)
            ->test(ReviewChurchServiceProposalQueue::class)
            ->assertSee('custom:welcome')
            ->assertSee('1 Sep 2026')
            ->assertSee('Unaccounted');
    }

    private function proposal(int $day = 1, string $subject = 'custom:welcome'): ChurchServiceMergeProposal
    {
        $service = ChurchService::factory()->create([
            'date' => sprintf('2026-09-%02d', $day),
            'service' => 'morning',
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ]);

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 2]],
            'conflicts' => [['kind' => 'ambiguous_repeat_match', 'canonical_identity' => $subject]],
            'proposed_items' => [[
                'canonical_identity' => $subject,
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
            ]],
        ]);
    }
}
