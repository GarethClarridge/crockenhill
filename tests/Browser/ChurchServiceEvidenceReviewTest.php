<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ChurchServiceEvidenceReviewTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_keyboard_review_supports_accept_reject_and_custom_values(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $service = ChurchService::factory()->create();
        $email = $this->createProposal($service, ChurchServiceSource::Email, 'Email item');
        $openLp = $this->createProposal($service, ChurchServiceSource::OpenLp, 'OpenLP item');

        $this->browse(function (Browser $browser) use ($admin, $service, $email): void {
            $browser->loginAs($admin)
                ->visit("/admin/services/{$service->id}")
                ->waitFor('#evidence-review')
                ->scrollIntoView('@final-manual-title-0')
                ->click('@final-manual-title-0')
                ->keys('@final-manual-title-0', ['{control}', 'a'])
                ->assertFocused('@final-manual-title-0')
                ->select("@proposal-decision-{$email->id}", 'rejected')
                ->clear('@final-manual-title-0')
                ->type('@final-manual-title-0', 'Keyboard reviewed title')
                ->click('@complete-evidence-review')
                ->acceptDialog()
                ->waitForText('Selected evidence reviewed');
        });

        $this->assertSame(ChurchServiceProposalStatus::Rejected, $email->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $openLp->fresh()->status);
        $this->assertSame('Keyboard reviewed title', $service->items()->firstOrFail()->title);
    }

    public function test_a_concurrent_proposal_disables_submission_and_warns_the_reviewer(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $service = ChurchService::factory()->create();
        $this->createProposal($service, ChurchServiceSource::Email, 'Email item');

        $this->browse(function (Browser $browser) use ($admin, $service): void {
            $browser->loginAs($admin)
                ->visit("/admin/services/{$service->id}")
                ->waitFor('#evidence-review');

            $this->createProposal($service, ChurchServiceSource::OpenLp, 'Late OpenLP item');

            $browser->script('window.Livewire.all()[0].$wire.$refresh()');

            $browser->waitForText('New evidence arrived after this screen loaded.')
                ->assertAttribute('@complete-evidence-review', 'disabled', 'true');
        });
    }

    private function createProposal(
        ChurchService $service,
        ChurchServiceSource $source,
        string $title,
    ): ChurchServiceMergeProposal {
        $record = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => $source,
            'source_key' => "{$source->value}-{$title}",
        ]);
        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $record->id,
            'assertion_key' => 'item-1',
            'source_position' => 1,
            'evidence_kind' => ChurchServiceEvidenceKind::Planned,
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
                'occurrence_state' => 'planned_only',
                'metadata' => [],
            ]],
        ]);
    }
}
