<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosApprovedCorpus;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Email\OosApprovedCorpusTest;

/**
 * The §9.4.6 gate has to distinguish "the corpus projected with no proposals left"
 * from "nothing has been staged or projected yet". Both produce an empty census, so
 * the class list alone cannot tell them apart and the gate requires independent
 * corpus-completeness evidence before it will pass.
 */
class ChurchServiceProposalCensusGateTest extends TestCase
{
    use RefreshDatabase;

    private const EmailBatchHash = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

    #[Test]
    public function an_empty_census_does_not_pass_without_corpus_evidence(): void
    {
        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('expected_corpus_size_unapproved', $result['corpus_blockers']);
        $this->assertContains('expectation_unapproved', $result['corpus_blockers']);
    }

    /**
     * F1. Membership certification proves that everything in the set it was handed
     * staged correctly — but its only producer read that set out of the staged
     * database, so a manifest entry that held rather than imported was absent from
     * both sides and certification passed over the gap. The gate now refuses to
     * treat a self-derived membership as a completeness claim.
     */
    #[Test]
    public function a_certified_membership_alone_no_longer_certifies_completeness(): void
    {
        $this->stageAndProject(3, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 3);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $records = ChurchServiceSourceRecord::query()->with('churchService')->get();
        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence($this->membership($records)),
        );

        $this->assertSame([], $result['corpus']['membership']['blockers']);
        $this->assertTrue($result['corpus']['membership']['approved']);
        $this->assertFalse($result['passes']);
        $this->assertSame(['expectation_unapproved'], $result['corpus_blockers']);
    }

    /**
     * The manifest names an entry that never reached the database. Nothing in the
     * staged corpus can be inconsistent, because the entry simply is not there.
     */
    #[Test]
    public function the_gate_holds_when_an_approved_entry_never_staged(): void
    {
        $this->stageAndProject(2, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $expectation = $this->expectation();
        $expectation['approved_sources'][] = [
            'item_key' => '2015-12-20-am',
            'origin' => '<oos-2015-12-20-am-00000000@crockenhill.local>',
            'source_key' => '<oos-2015-12-20-am-00000000@crockenhill.local>|morning:2015-12-20',
            'input_hash' => str_repeat('c', 64),
            'identity' => ['date' => '2015-12-20', 'service' => 'morning'],
            'content_scope' => 'full',
        ];
        unset($expectation['expectation_hash']);
        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(
                $this->membership(ChurchServiceSourceRecord::query()->with('churchService')->get()),
                null,
                $expectation,
            ),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('expectation_mismatch', $result['corpus_blockers']);
        $this->assertSame(['2015-12-20-am'], array_column($result['corpus']['expectation']['unstaged_sources'], 'item_key'));
    }

    /**
     * The corpus size stops being a number an operator types. It is counted from the
     * manifest's own identities, and the configured scalar is not consulted at all
     * while an expectation is present.
     */
    #[Test]
    public function the_approved_corpus_size_is_produced_from_the_manifest_not_configuration(): void
    {
        $this->stageAndProject(3, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.expected_services', 999);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate([], $this->evidence());

        $this->assertSame(3, $result['corpus']['expected_services']);
        $this->assertSame('manifest_expectation', $result['corpus']['expected_services_source']);
        $this->assertSame([], $result['corpus_blockers']);
    }

    /**
     * One approved email legitimately stages both that Sunday's morning and evening
     * orders, so a correct corpus stages more services than the manifest names
     * identities. Enforcing the scalar comparison alongside the expectation would
     * fail exactly that correct corpus, so the expectation replaces it rather than
     * joining it.
     */
    #[Test]
    public function an_approved_expectation_replaces_the_scalar_count_comparison(): void
    {
        $this->stageAndProject(2, ChurchServiceSource::Email);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $expectation = $this->expectation();

        /**
         * The extra order has to come from the entry approved for *that same date*,
         * because `service_beyond_manifest` widens an entry's service and never its
         * date. Selected by identity rather than by position, so the assertion does
         * not depend on the order the staged revisions come back in.
         */
        $morning = now()->subWeeks(1)->toDateString();
        $origin = collect($expectation['approved_sources'])
            ->firstOrFail(static fn (array $source): bool => $source['identity']['date'] === $morning)['origin'];

        $beyondManifest = ChurchService::factory()->create([
            'date' => $morning,
            'service' => 'evening',
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $beyondManifest->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => self::EmailBatchHash,
            'source_key' => $origin.'|evening:'.$morning,
        ]);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(
                $this->membership(ChurchServiceSourceRecord::query()->with('churchService')->get()),
                null,
                $expectation,
            ),
        );

        $this->assertSame(3, $result['corpus']['staged_services']);
        $this->assertSame(2, $result['corpus']['expected_services']);
        $this->assertNotContains('staged_above_expected', $result['corpus_blockers']);
        $this->assertCount(1, $result['corpus']['expectation']['explained_beyond_manifest']);
        $this->assertSame([], $result['corpus_blockers']);
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

    /**
     * An incomplete payload is retained as evidence and deliberately never projected
     * ({@see InboundEmailImportService::retainPlanEvidence()} passes
     * `project: false`), because a partial order cannot establish canonical membership. Reporting
     * that as a *stale* projection asks for something that can never become true: on the
     * 2026-08-15 email staging round it flagged 157 of 618 approved source items and was the only
     * blocker left standing between a complete corpus and a certified census.
     */
    #[Test]
    public function an_evidence_only_source_item_is_not_reported_as_a_stale_projection(): void
    {
        $this->stageAndProject(2);
        config()->set('church.historic_corpus.expected_services', 2);

        $evidenceOnly = ChurchService::query()->orderBy('id')->firstOrFail();
        $evidenceOnly->forceFill(['projection_policy_version' => null])->save();
        $evidenceOnly->sourceRecords()->update(['payload_complete' => false]);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertNotContains('source_item_projection_stale', $result['corpus']['membership']['blockers']);
        $this->assertSame([], $result['corpus']['membership']['blockers']);
        $this->assertTrue($result['corpus']['membership']['approved']);
        $this->assertSame(0, $result['corpus']['stale_projection_services']);

        $resultWithoutMembership = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            app(ChurchServiceCorpusCompleteness::class)->evidence(),
        );

        $this->assertNotContains('projection_incomplete', $resultWithoutMembership['corpus_blockers']);
    }

    /**
     * The relaxation above is scoped to the payload that declares itself partial. A *complete*
     * payload that has not been projected is still the actionable case the check exists for.
     */
    #[Test]
    public function a_complete_payload_that_was_never_projected_still_holds_the_gate(): void
    {
        $this->stageAndProject(2);
        config()->set('church.historic_corpus.expected_services', 2);

        $unprojected = ChurchService::query()->orderBy('id')->firstOrFail();
        $unprojected->forceFill(['projection_policy_version' => null])->save();
        $unprojected->sourceRecords()->update(['payload_complete' => true]);

        $result = app(ChurchServiceProposalCensusGate::class)->evaluate(
            [],
            $this->evidence(),
        );

        $this->assertFalse($result['passes']);
        $this->assertContains('source_item_projection_stale', $result['corpus']['membership']['blockers']);
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
                'batch_hash' => ($source ?? ChurchServiceSource::Email) === ChurchServiceSource::Email
                    ? self::EmailBatchHash
                    : 'batch-'.$source->value,
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
            null,
            $this->expectation(),
        );
    }

    /**
     * The manifest-derived statement of what should be staged. Built here from the
     * staged email revisions so these tests exercise the gate's wiring; that the
     * *producer* derives the same keys from the manifest alone is
     * {@see OosApprovedCorpusTest}'s subject.
     *
     * @return array<string, mixed>|null
     */
    private function expectation(?Collection $records = null): ?array
    {
        $records ??= ChurchServiceSourceRecord::query()
            ->where('source', ChurchServiceSource::Email)
            ->where('batch_hash', self::EmailBatchHash)
            ->with('churchService')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $expectation = [
            'format' => OosApprovedCorpus::Format,
            'version' => OosApprovedCorpus::Version,
            'source' => OosApprovedCorpus::Source,
            'batch_key' => 'oos-curated-test',
            'batch_hash' => self::EmailBatchHash,
            'manifest_hash' => str_repeat('f', 64),
            'approved_sources' => $records
                ->map(static fn (ChurchServiceSourceRecord $record): array => [
                    'item_key' => $record->source_key,
                    'origin' => explode('|', $record->source_key, 2)[0],
                    'source_key' => $record->source_key,
                    'input_hash' => (string) $record->input_hash,
                    'identity' => [
                        'date' => $record->churchService->date->toDateString(),
                        'service' => $record->churchService->service->value,
                    ],
                    'content_scope' => 'full',
                ])
                ->values()
                ->all(),
        ];
        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        return $expectation;
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
