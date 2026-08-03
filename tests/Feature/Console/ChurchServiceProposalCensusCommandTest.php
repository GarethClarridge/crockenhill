<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\User;
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
            ->expectsOutputToContain('Gate passes')
            ->assertSuccessful();
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

    private function proposal(): ChurchServiceMergeProposal
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-09-01',
            'service' => 'morning',
            'needs_review' => true,
        ]);

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
