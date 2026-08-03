<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ChurchServiceProposalQueueTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_a_class_rule_settles_every_service_it_names(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $first = $this->proposal('2026-09-01');
        $second = $this->proposal('2026-09-08');
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($first);

        $this->browse(function (Browser $browser) use ($admin, $classKey): void {
            $browser->loginAs($admin)
                ->visit('/admin/services/proposals')
                ->waitForText('Evidence proposal queue')
                ->assertSee('custom:welcome')
                ->assertSee('2 proposals across 2 services')
                ->click("@select-class-{$classKey}")
                ->waitForText('2 of 2 proposals selected')
                ->click('@apply-decision-rule')
                ->waitForText('Rationale')
                ->assertSee('The rationale field is required.')
                ->type('@rationale', 'Both are casing variants of the same title.')
                ->click('@apply-decision-rule')
                ->waitForText('Decision rule applied');
        });

        $this->assertSame(ChurchServiceProposalStatus::Accepted, $first->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $second->fresh()->status);
        $this->assertFalse($first->churchService->fresh()->needs_review);
        $this->assertFalse($second->churchService->fresh()->needs_review);
    }

    public function test_recording_an_irreducible_class_requires_a_measured_time(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $proposal = $this->proposal('2026-09-01');
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        $this->browse(function (Browser $browser) use ($admin, $classKey): void {
            $browser->loginAs($admin)
                ->visit('/admin/services/proposals')
                ->waitForText('Evidence proposal queue')
                ->assertSee('Unaccounted')
                ->click("@mark-class-{$classKey}")
                ->waitForText('Record this class\'s standing')
                ->select('@markStatus', ChurchServiceProposalClassReview::IRREDUCIBLE)
                ->waitFor('@markSecondsPerDecision')
                ->type('@markReason', 'The sources genuinely disagree about order.')
                ->click('@save-class-standing')
                ->waitForText('measured seconds per decision field is required')
                ->type('@markSecondsPerDecision', '45')
                ->click('@save-class-standing')
                ->waitForText('Proposal class recorded')
                ->assertSee('Irreducible');
        });

        $this->assertDatabaseHas('church_service_proposal_class_reviews', [
            'class_key' => $classKey,
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'seconds_per_decision' => 45,
        ]);
    }

    private function proposal(string $date): ChurchServiceMergeProposal
    {
        $service = ChurchService::factory()->create([
            'date' => $date,
            'service' => 'morning',
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ]);
        $record = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Email,
            'source_key' => "email-{$date}",
        ]);
        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $record->id,
            'assertion_key' => 'item-1',
            'source_position' => 1,
            'evidence_kind' => ChurchServiceEvidenceKind::Planned,
            'title' => 'Welcome',
            'normalized_title' => 'welcome',
        ]);

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'trigger_source_record_id' => $record->id,
            'field_decisions' => [['match_tier' => 2]],
            'conflicts' => [['kind' => 'ambiguous_repeat_match', 'canonical_identity' => 'custom:welcome']],
            'proposed_items' => [[
                'canonical_identity' => 'custom:welcome',
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
            ]],
        ]);
    }
}
