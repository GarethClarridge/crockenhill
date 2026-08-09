<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use App\Services\ChurchService\ChurchServiceProposalRuleService;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ChurchServiceProposalRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function census_keys_are_stable_and_a_rule_only_resolves_its_enumerated_proposals(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $proposals = $this->classOfProposals(3);

        $census = app(ChurchServiceProposalCensus::class);
        $loadedProposals = ChurchServiceMergeProposal::query()
            ->with(['churchService', 'triggerSourceRecord'])
            ->whereIn('id', $proposals->pluck('id'))
            ->get();
        $classes = $census->fromProposals($loadedProposals->shuffle()->values());

        $this->assertCount(1, $classes);
        $class = $classes[0];
        $this->assertSame(3, $class['occurrence_count']);
        $this->assertSame(3, $class['service_count']);
        $this->assertSame('custom:welcome', $class['subject']);
        $this->assertSame(
            $class['class_key'],
            $census->classKey($proposals->firstOrFail()),
        );

        $rule = app(ChurchServiceProposalRuleService::class)->apply(
            $class['class_key'],
            [$proposals[0]->id, $proposals[1]->id],
            'accepted',
            'The normalized title and semantic type are equivalent.',
            $reviewer->id,
        );

        $this->assertSame($class['class_key'], $rule->class_key);
        $this->assertSame(2, $rule->proposals()->count());
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposals[0]->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposals[1]->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $proposals[2]->fresh()->status);
        $this->assertSame($rule->id, $proposals[0]->fresh()->decision_rule_id);
        $this->assertSame($rule->id, $proposals[1]->fresh()->decision_rule_id);
        $this->assertNull($proposals[2]->fresh()->decision_rule_id);
        $this->assertNull($proposals[2]->fresh()->resolved_by_user_id);
    }

    #[Test]
    public function a_rule_settles_every_service_it_touched(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $proposals = $this->classOfProposals(2);
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposals->firstOrFail());

        app(ChurchServiceProposalRuleService::class)->apply(
            $classKey,
            $proposals->pluck('id')->all(),
            'accepted',
            'Casing variant of the same title.',
            $reviewer->id,
        );

        foreach ($proposals as $proposal) {
            $service = $proposal->churchService->fresh();

            $this->assertFalse(
                $service->needs_review,
                'A service whose every proposal was dispositioned by rule must leave the inbox.',
            );
            $this->assertNull($service->review_reason);
            $this->assertNotNull(
                $service->canonical_finalization,
                'A settled service must declare who finalised it, or it can never be exported.',
            );
            $this->assertNotNull($service->reviewed_canonical_revision);

            $review = $service->reviewSessions()->sole();
            $this->assertNotNull($review->completed_at);
            $this->assertSame([$proposal->id], $review->included_proposal_ids);
            $this->assertSame('accepted', $review->proposal_dispositions[0]['disposition']);
        }
    }

    #[Test]
    public function a_partially_ruled_service_stays_in_the_inbox(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ]);
        $ruled = $this->proposalFor($service);
        $untouched = $this->proposalFor($service);
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($ruled);

        app(ChurchServiceProposalRuleService::class)->apply(
            $classKey,
            [$ruled->id],
            'accepted',
            'Only this one is a casing variant.',
            $reviewer->id,
        );

        $service->refresh();
        $this->assertTrue($service->needs_review);
        $this->assertNull($service->canonical_finalization);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $untouched->fresh()->status);
        $this->assertNull($service->reviewSessions()->sole()->completed_at);
    }

    #[Test]
    public function a_proposal_added_to_a_class_after_a_rule_is_not_retroactively_dispositioned(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $existing = $this->classOfProposals(1)->firstOrFail();
        $census = app(ChurchServiceProposalCensus::class);
        $classKey = $census->classKey($existing);

        app(ChurchServiceProposalRuleService::class)->apply(
            $classKey,
            [$existing->id],
            'accepted',
            'Casing variant of the same title.',
            $reviewer->id,
        );

        $late = $this->classOfProposals(1, startingDay: 20)->firstOrFail();

        $this->assertSame(
            $classKey,
            $census->classKey($late),
            'The late proposal must land in the same class for this test to mean anything.',
        );
        $this->assertSame(ChurchServiceProposalStatus::Pending, $late->fresh()->status);
        $this->assertNull($late->fresh()->decision_rule_id);
        $this->assertNull($late->fresh()->resolved_by_user_id);
        $this->assertTrue($late->churchService->fresh()->needs_review);
    }

    #[Test]
    public function a_matcher_improvement_shrinks_the_census_without_resolving_what_it_did_not_match(): void
    {
        $settled = $this->classOfProposals(3);
        $remaining = $this->classOfProposals(2, subject: 'custom:notices', startingDay: 20);
        $census = app(ChurchServiceProposalCensus::class);

        $this->assertCount(2, $census->build());

        // A matcher improvement re-projects and supersedes the proposals it now
        // settles. Superseded proposals stay auditable but are no longer active.
        ChurchServiceMergeProposal::query()
            ->whereIn('id', $settled->pluck('id'))
            ->update(['status' => ChurchServiceProposalStatus::Stale->value]);

        $classes = $census->build();

        $this->assertCount(1, $classes);
        $this->assertSame('custom:notices', $classes[0]['subject']);
        $this->assertSame(2, $classes[0]['occurrence_count']);

        foreach ($remaining as $proposal) {
            $this->assertSame(
                ChurchServiceProposalStatus::Pending,
                $proposal->fresh()->status,
                'A matcher improvement must never silently resolve a proposal it did not match.',
            );
            $this->assertNull($proposal->fresh()->resolved_by_user_id);
        }
    }

    #[Test]
    public function a_rule_refuses_proposals_from_outside_its_declared_class(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $inClass = $this->classOfProposals(1)->firstOrFail();
        $outsider = $this->classOfProposals(1, subject: 'custom:offering', startingDay: 20)->firstOrFail();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($inClass);

        try {
            app(ChurchServiceProposalRuleService::class)->apply(
                $classKey,
                [$inClass->id, $outsider->id],
                'accepted',
                'Attempting to smuggle in an unrelated proposal.',
                $reviewer->id,
            );

            $this->fail('A rule must refuse a proposal from another class.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('may only enumerate proposals from its declared class', $exception->getMessage());
        }

        $this->assertSame(ChurchServiceProposalStatus::Pending, $inClass->fresh()->status);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $outsider->fresh()->status);
        $this->assertDatabaseCount('church_service_proposal_decision_rules', 0);
    }

    #[Test]
    public function a_rule_cannot_author_a_replacement_value_across_a_class(): void
    {
        $reviewer = User::factory()->create(['is_admin' => true]);
        $proposal = $this->classOfProposals(1)->firstOrFail();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may only accept or reject');

        app(ChurchServiceProposalRuleService::class)->apply(
            $classKey,
            [$proposal->id],
            'replaced',
            'There is no single replacement value for a whole class.',
            $reviewer->id,
        );
    }

    #[Test]
    public function the_gate_holds_until_every_class_is_accounted_for(): void
    {
        $marker = User::factory()->create(['is_admin' => true]);
        $automated = $this->classOfProposals(2);
        $irreducible = $this->classOfProposals(1, subject: 'custom:notices', startingDay: 20);
        $census = app(ChurchServiceProposalCensus::class);
        $gate = app(ChurchServiceProposalCensusGate::class);

        $result = $gate->evaluate($census->build(), $this->completeCorpusEvidence());
        $this->assertFalse($result['passes']);
        $this->assertCount(2, $result['unclassified']);

        ChurchServiceProposalClassReview::query()->create([
            'class_key' => $census->classKey($automated->firstOrFail()),
            'status' => ChurchServiceProposalClassReview::AUTOMATED,
            'reason' => 'A tier-2 normalisation change removes this class.',
            'marked_by_user_id' => $marker->id,
        ]);
        ChurchServiceProposalClassReview::query()->create([
            'class_key' => $census->classKey($irreducible->firstOrFail()),
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'reason' => 'The sources genuinely disagree about order.',
            'seconds_per_decision' => 45,
            'marked_by_user_id' => $marker->id,
        ]);

        $result = $gate->evaluate($census->build(), $this->completeCorpusEvidence());

        $this->assertTrue($result['passes']);
        $this->assertSame([], $result['unclassified']);
        $this->assertSame(1, $result['residual_decisions']);
        $this->assertSame(45, $result['residual_seconds']);
    }

    #[Test]
    public function the_gate_rejects_an_irreducible_class_a_matcher_change_would_settle(): void
    {
        $marker = User::factory()->create(['is_admin' => true]);
        $proposal = $this->classOfProposals(1, withCandidate: true)->firstOrFail();
        $census = app(ChurchServiceProposalCensus::class);

        ChurchServiceProposalClassReview::query()->create([
            'class_key' => $census->classKey($proposal),
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'reason' => 'Claimed irreducible despite a recorded candidate.',
            'seconds_per_decision' => 20,
            'marked_by_user_id' => $marker->id,
        ]);

        $result = app(ChurchServiceProposalCensusGate::class)
            ->evaluate($census->build(), $this->completeCorpusEvidence());

        $this->assertFalse($result['passes']);
        $this->assertCount(1, $result['irreducible_with_candidates']);
    }

    /** @return Collection<int, ChurchServiceMergeProposal> */
    private function classOfProposals(
        int $count,
        string $subject = 'custom:welcome',
        int $startingDay = 1,
        bool $withCandidate = false,
    ): Collection {
        return collect(range(0, $count - 1))->map(function (int $index) use ($subject, $startingDay, $withCandidate): ChurchServiceMergeProposal {
            $service = ChurchService::factory()->create([
                'date' => sprintf('2026-09-%02d', $startingDay + $index),
                'service' => 'morning',
                'needs_review' => true,
                'review_reason' => 'projection_requires_review',
                'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
            ]);
            ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);

            return $this->proposalFor($service, $subject, $withCandidate);
        });
    }

    /**
     * These tests are about class accounting, so the corpus around them is set up
     * complete: every service carrying a proposal is staged and projected at the
     * current policy version, and the approved manifest count matches.
     *
     * @return array<string, mixed>
     */
    /**
     * A corpus that reconciles on every axis the gate checks, so these tests turn only
     * on class accounting. The declared census scope is read from what is actually
     * staged rather than hard-coded: this helper's job is "complete", and a fixed list
     * would silently stop being that if the fixture's sources changed.
     */
    private function completeCorpusEvidence(): array
    {
        ChurchServiceSourceRecord::query()->whereNull('batch_hash')->update(['batch_hash' => 'batch-test']);
        config()->set('church.historic_corpus.expected_services', ChurchService::query()->count());
        config()->set('church.historic_corpus.census_source_kinds', ChurchServiceSourceRecord::query()
            ->distinct()
            ->pluck('source')
            ->map(static fn (ChurchServiceSource $source): string => $source->value)
            ->implode(','));

        return app(ChurchServiceCorpusCompleteness::class)->evidence($this->membership());
    }

    /** @return array<string, mixed> */
    private function membership(): array
    {
        $items = ChurchServiceSourceRecord::query()->with('churchService')->get()
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
            ])->all();
        $membership = [
            'format' => 'crockenhill-historic-corpus-membership',
            'version' => 1,
            'items' => $items,
        ];
        $membership['membership_hash'] = CanonicalJson::hash($membership);

        return $membership;
    }

    private function proposalFor(
        ChurchService $service,
        string $subject = 'custom:welcome',
        bool $withCandidate = false,
    ): ChurchServiceMergeProposal {
        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 2]],
            'conflicts' => [array_filter([
                'kind' => 'ambiguous_repeat_match',
                'canonical_identity' => $subject,
                'candidate_identities' => $withCandidate ? [$subject] : null,
            ])],
            'proposed_items' => [[
                'canonical_identity' => $subject,
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
            ]],
        ]);
    }
}
