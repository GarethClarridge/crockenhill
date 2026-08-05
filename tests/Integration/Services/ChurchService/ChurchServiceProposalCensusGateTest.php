<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The §9.4.6 gate has to distinguish "the corpus projected with no proposals left"
 * from "nothing has been staged or projected yet". Both produce an empty census, so
 * the class list alone cannot tell them apart and the gate requires independent
 * corpus-completeness evidence before it will pass.
 */
class ChurchServiceProposalCensusGateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_empty_census_does_not_pass_without_corpus_evidence(): void
    {
        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('expected_corpus_size_unapproved', $result['corpus_blockers']);
    }

    #[Test]
    public function an_empty_census_passes_once_the_whole_corpus_is_staged_and_projected(): void
    {
        $this->stageAndProject(3);
        config()->set('church.historic_corpus.expected_services', 3);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertSame([], $result['corpus_blockers']);
        $this->assertTrue($result['passes']);
        $this->assertSame(3, $result['corpus']['expected_services']);
        $this->assertSame(3, $result['corpus']['staged_services']);
        $this->assertSame(3, $result['corpus']['projected_services']);
    }

    #[Test]
    public function the_gate_holds_when_fewer_services_are_staged_than_the_manifest_approved(): void
    {
        $this->stageAndProject(2);
        config()->set('church.historic_corpus.expected_services', 5);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('staged_below_expected', $result['corpus_blockers']);
        $this->assertSame(3, $result['corpus']['unstaged_services']);
    }

    #[Test]
    public function the_gate_holds_when_more_services_are_staged_than_the_manifest_approved(): void
    {
        $this->stageAndProject(4);
        config()->set('church.historic_corpus.expected_services', 3);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('staged_above_expected', $result['corpus_blockers']);
    }

    #[Test]
    public function the_gate_holds_when_a_staged_service_is_not_projected_at_the_current_policy_version(): void
    {
        $this->stageAndProject(3);
        config()->set('church.historic_corpus.expected_services', 3);
        ChurchService::query()->orderBy('id')->limit(1)->update([
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION - 1,
        ]);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('projection_incomplete', $result['corpus_blockers']);
        $this->assertSame(2, $result['corpus']['projected_services']);
        $this->assertSame(1, $result['corpus']['stale_projection_services']);
    }

    #[Test]
    public function a_staged_service_that_was_never_projected_holds_the_gate(): void
    {
        $this->stageAndProject(2);
        config()->set('church.historic_corpus.expected_services', 2);
        ChurchService::query()->orderBy('id')->limit(1)->update(['projection_policy_version' => null]);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('projection_incomplete', $result['corpus_blockers']);
        $this->assertSame(1, $result['corpus']['stale_projection_services']);
    }

    /**
     * Corpus evidence is independent of the census, so it is built from staged source
     * revisions and recorded projection state rather than from proposals.
     */
    private function stageAndProject(int $count): void
    {
        foreach (range(1, $count) as $offset) {
            $service = ChurchService::factory()->create([
                'date' => now()->subWeeks($offset)->toDateString(),
                'service' => $offset % 2 === 0 ? 'evening' : 'morning',
                'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
            ]);
            ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        }
    }
}
