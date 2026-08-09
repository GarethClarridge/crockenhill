<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('expected_corpus_size_unapproved', $result['corpus_blockers']);
    }

    #[Test]
    public function an_empty_census_passes_once_the_whole_corpus_is_staged_and_projected(): void
    {
        $this->stageAndProject(3, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 3);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
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
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('membership_mismatch', $result['corpus_blockers']);
        $this->assertContains('source_item_projection_stale', $result['corpus']['membership']['blockers']);
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
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('membership_mismatch', $result['corpus_blockers']);
        $this->assertContains('source_item_projection_stale', $result['corpus']['membership']['blockers']);
        $this->assertSame(1, $result['corpus']['stale_projection_services']);
    }

    #[Test]
    public function the_gate_holds_until_the_census_declares_which_source_kinds_it_covers(): void
    {
        $this->stageAndProject(3, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 3);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('census_source_kinds_undeclared', $result['corpus_blockers']);
    }

    /**
     * F3, and the reason this gate exists at all. Email staging is drive-free and
     * OpenLP staging is not, so "every service is staged and projected" becomes true
     * of an Email-only corpus long before the Email x OpenLP population §9.4.2 wants
     * converged has been generated at all. Counting distinct services cannot see the
     * difference; counting them per source kind can.
     */
    #[Test]
    public function an_email_only_corpus_cannot_claim_a_census_declared_over_email_and_openlp(): void
    {
        $this->stageAndProject(3, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 3);
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('declared_source_kind_unstaged', $result['corpus_blockers']);
        $this->assertSame(['openlp'], $result['corpus']['unstaged_source_kinds']);
        $this->assertSame(['email' => 3], $result['corpus']['staged_services_by_source']);
    }

    #[Test]
    public function the_gate_passes_once_every_declared_source_kind_is_staged(): void
    {
        $this->stageAndProject(2, ChurchServiceSource::Email);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => ChurchService::query()->orderBy('id')->value('id'),
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => 'batch-openlp',
        ]);
        config()->set('church.historic_corpus.expected_services', 2);
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertSame([], $result['corpus_blockers']);
        $this->assertTrue($result['passes']);
        $this->assertSame(['email' => 2, 'openlp' => 1], $result['corpus']['staged_services_by_source']);
    }

    #[Test]
    public function an_unapproved_openlp_item_cannot_be_hidden_by_matching_global_service_counts(): void
    {
        $this->stageAndProject(2, ChurchServiceSource::Email);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => ChurchService::query()->orderBy('id')->value('id'),
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => 'batch-openlp',
        ]);
        config()->set('church.historic_corpus.expected_services', 2);
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $membership = $this->membership(
            ChurchServiceSourceRecord::query()->where('source', ChurchServiceSource::Email)->with('churchService')->get(),
        );
        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence($membership),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('membership_source_kind_unapproved', $result['corpus_blockers']);
    }

    /**
     * A service carrying two kinds is one staged service, not two. The per-kind counts
     * are distinct services per kind, so they deliberately do not sum to the total.
     */
    #[Test]
    public function per_source_counts_are_distinct_services_and_do_not_double_count(): void
    {
        $this->stageAndProject(1, ChurchServiceSource::Email);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => ChurchService::query()->orderBy('id')->value('id'),
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => 'batch-openlp',
        ]);

        $evidence = $this->evidence();

        $this->assertSame(1, $evidence['staged_services']);
        $this->assertSame(['email' => 1, 'openlp' => 1], $evidence['staged_services_by_source']);
    }

    #[Test]
    public function an_unrecognised_declared_source_kind_is_rejected_rather_than_ignored(): void
    {
        $this->stageAndProject(1, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 1);
        config()->set('church.historic_corpus.census_source_kinds', 'email,carrier-pigeon');

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('census_source_kinds_undeclared', $result['corpus_blockers']);
    }

    /**
     * Corpus evidence is independent of the census, so it is built from staged source
     * revisions and recorded projection state rather than from proposals.
     */
    private function stageAndProject(int $count, ?ChurchServiceSource $source = null): void
    {
        foreach (range(1, $count) as $offset) {
            $service = ChurchService::factory()->create([
                'date' => now()->subWeeks($offset)->toDateString(),
                'service' => $offset % 2 === 0 ? 'evening' : 'morning',
                'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
            ]);
            ChurchServiceSourceRecord::factory()->create([
                'church_service_id' => $service->id,
                ...($source instanceof ChurchServiceSource ? ['source' => $source] : []),
                'batch_hash' => 'batch-'.($source?->value ?? ChurchServiceSource::Email->value),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        $records = ChurchServiceSourceRecord::query()
            ->with('churchService')
            ->get();

        return app(ChurchServiceCorpusCompleteness::class)->evidence(
            $records->isEmpty() ? null : $this->membership($records),
        );
    }

    /** @param Collection<int, ChurchServiceSourceRecord> $records */
    private function membership(Collection $records): array
    {
        $items = $records
            ->map(static fn (ChurchServiceSourceRecord $record): array => [
                'source' => $record->source->value,
                'batch_hash' => $record->batch_hash,
                'source_key' => $record->source_key,
                'input_hash' => $record->input_hash,
                'processing_fingerprint' => $record->processing_fingerprint,
                'identity' => [
                    'date' => $record->churchService->date->toDateString(),
                    'service' => $record->churchService->service->value,
                ],
            ])
            ->all();

        $membership = [
            'format' => 'crockenhill-historic-corpus-membership',
            'version' => 1,
            'items' => $items,
        ];
        $membership['membership_hash'] = CanonicalJson::hash($membership);

        return $membership;
    }
}
