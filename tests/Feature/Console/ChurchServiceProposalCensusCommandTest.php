<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceProposalCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_an_empty_census(): void
    {
        $this->artisan('services:proposal-census')
            ->expectsOutputToContain('The census is empty.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_gate_option_fails_until_every_class_is_accounted_for(): void
    {
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        $this->artisan('services:proposal-census --gate')->assertFailed();

        ChurchServiceProposalClassReview::query()->create([
            'class_key' => $classKey,
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'reason' => 'The sources genuinely disagree about order.',
            'seconds_per_decision' => 30,
            'marked_by_user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('No hash-verified, item-level corpus membership is supplied')
            ->assertFailed();
    }

    /**
     * The empty census is the dangerous one: it looks like a converged corpus and is
     * produced just as readily by a corpus nothing has been staged for.
     */
    #[Test]
    public function the_gate_option_fails_on_an_empty_census_over_an_unstaged_corpus(): void
    {
        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('The census is empty.')
            ->expectsOutputToContain('No approved corpus size is recorded')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_without_a_hash_verified_item_level_membership(): void
    {
        $service = ChurchService::factory()->create([
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $this->artisan('services:proposal-census --gate --expected-services=1')
            ->expectsOutputToContain('The census is empty.')
            ->expectsOutputToContain('1 service(s) staged, 1 projected')
            ->expectsOutputToContain('No hash-verified, item-level corpus membership is supplied')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_when_the_staged_corpus_falls_short_of_the_manifest(): void
    {
        $this->proposal();

        $this->artisan('services:proposal-census --gate --expected-services=40')
            ->expectsOutputToContain('Fewer services are staged than the approved manifest declares')
            ->assertFailed();
    }

    #[Test]
    public function it_emits_the_census_and_gate_as_json(): void
    {
        $this->proposal();

        $this->assertSame(0, Artisan::call('services:proposal-census', ['--json' => true]));

        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('custom:welcome', $decoded['classes'][0]['subject']);
        $this->assertFalse($decoded['gate']['passes']);
        $this->assertSame(1, $decoded['gate']['proposal_count']);
    }

    /**
     * The operator-facing half of F3: the corpus line has to say what it is made of,
     * or a reviewer reading "391 services staged, 391 projected" has no way to notice
     * that none of it is OpenLP.
     */
    #[Test]
    public function the_gate_option_fails_and_names_the_unstaged_source_kind(): void
    {
        $this->proposal();
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('Staged services by source: email 1  openlp 0')
            ->expectsOutputToContain('A declared source kind has no staged services at all')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_when_no_source_kinds_are_declared(): void
    {
        $this->proposal();
        config()->set('church.historic_corpus.census_source_kinds', null);

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('No valid source kinds are declared')
            ->assertFailed();
    }

    private function proposal(): ChurchServiceMergeProposal
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-09-01',
            'service' => 'morning',
            'needs_review' => true,
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        config()->set('church.historic_corpus.expected_services', 1);
        // The factory stages Email evidence, so an Email-scoped census is the one this
        // fixture can honestly claim. Declaring `openlp` here would be the F3 defect.
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
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
