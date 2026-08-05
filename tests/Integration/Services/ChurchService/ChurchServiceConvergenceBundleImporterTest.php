<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\ChurchServiceReviewSession;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalIdentity;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceConvergenceBundleImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_applies_a_reviewed_manual_revision_and_reruns_as_a_no_op(): void
    {
        [$service, $reviewer, $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $importer = app(ChurchServiceConvergenceBundleImporter::class);

        $plan = $importer->prepareService($bundle);
        $applied = $importer->persistPreparedService($plan, $plan->planHash);

        $this->assertSame('safe_enrichment', $plan->classification);
        $this->assertSame('Reviewed title', $applied->items()->sole()->title);
        $this->assertSame($reviewer->id, $applied->reviewSessions()->sole()->reviewed_by_user_id);

        $secondPlan = $importer->prepareService($bundle);
        $second = $importer->persistPreparedService($secondPlan, $secondPlan->planHash);

        $this->assertSame('already_present', $secondPlan->classification);
        $this->assertSame($applied->canonical_hash, $second->canonical_hash);
        $this->assertSame(1, $second->reviewSessions()->count());
    }

    #[Test]
    public function historic_persistence_can_suppress_domain_reconciliation_without_disabling_model_observers(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        Event::fake([ChurchServiceCanonicalListChanged::class]);
        Queue::fake();

        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);
        $importer->persistPreparedService($plan, $plan->planHash, false);

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
        Queue::assertNothingPushed();
        $this->assertNotNull($service->fresh()->canonical_hash);
    }

    #[Test]
    public function it_reproduces_every_reviewed_proposal_disposition(): void
    {
        [$service, $reviewer, $bundle, $preReviewHash] = $this->reviewedBundle();
        $proposal = $service->mergeProposals()->sole();

        $this->assertSame('accepted', $bundle['services'][0]['review']['proposal_dispositions'][0]['disposition']);

        $this->restoreMachineBase($service, $preReviewHash);
        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);
        $applied = $importer->persistPreparedService($plan, $plan->planHash);

        $this->assertSame('safe_enrichment', $plan->classification);
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposal->fresh()->status);
        $this->assertSame($reviewer->id, $proposal->fresh()->resolved_by_user_id);
        $exportedDisposition = $bundle['services'][0]['review']['proposal_dispositions'][0];
        $storedDisposition = $applied->reviewSessions()->sole()->proposal_dispositions[0];
        $this->assertSame($exportedDisposition['disposition'], $storedDisposition['disposition']);
        $this->assertSame($exportedDisposition['rationale'], $storedDisposition['rationale']);
    }

    #[Test]
    public function it_blocks_when_the_machine_evidence_base_differs(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $service->sourceRecords()->where('source', ChurchServiceSource::Email->value)->update([
            'processing_fingerprint' => ['version' => 999],
        ]);

        $plan = app(ChurchServiceConvergenceBundleImporter::class)->prepareService($bundle);

        $this->assertSame('blocked_difference', $plan->classification);
        $this->assertStringContainsString('machine evidence', $plan->reason);
    }

    #[Test]
    public function it_joins_a_caller_owned_transaction_and_enforces_the_plan_hash(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        try {
            DB::transaction(function () use ($importer, $plan): void {
                $importer->persistPreparedService($plan, $plan->planHash);
                throw new \RuntimeException('rollback after Bundle B');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback after Bundle B', $exception->getMessage());
        }

        $this->assertDatabaseMissing('church_service_review_sessions', ['review_uuid' => 'portable-review']);
        $this->assertDatabaseMissing('church_service_source_records', ['source_key' => 'review:portable-review']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plan hash does not match');
        $importer->persistPreparedService($plan, str_repeat('0', 64));
    }

    #[Test]
    public function rule_dispositions_round_trip_with_per_proposal_verification_intact(): void
    {
        [$service, $reviewer, $bundle, $preReviewHash] = $this->reviewedBundle(proposalCount: 1, withRules: true);
        $exportedRules = $bundle['services'][0]['review']['decision_rules'];

        $this->assertCount(1, $exportedRules);
        $this->assertSame('Authored once against the whole class.', $exportedRules[0]['rationale']);
        $this->assertSame(
            $bundle['services'][0]['review']['proposal_dispositions'][0]['proposal_identity'],
            $exportedRules[0]['proposal_identities'][0],
        );

        $proposal = $service->mergeProposals()->sole();
        $this->restoreMachineBase($service, $preReviewHash);
        ChurchServiceProposalDecisionRule::query()->delete();
        $proposal->forceFill([
            'status' => ChurchServiceProposalStatus::Pending,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'decision_rule_id' => null,
        ])->save();

        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);
        $applied = $importer->persistPreparedService($plan, $plan->planHash);

        $this->assertSame('safe_enrichment', $plan->classification);

        $reproducedRule = ChurchServiceProposalDecisionRule::query()->sole();
        $this->assertSame($exportedRules[0]['class_key'], $reproducedRule->class_key);
        $this->assertSame($exportedRules[0]['rationale'], $reproducedRule->rationale);
        $this->assertSame($reviewer->id, $reproducedRule->reviewed_by_user_id);
        $this->assertSame(
            $reproducedRule->id,
            $proposal->fresh()->decision_rule_id,
            'A rule-dispositioned proposal must still name its authorising act in production.',
        );
        // Key order is not part of the contract — CanonicalJson sorts before hashing.
        $this->assertEquals($exportedRules, $applied->reviewSessions()->sole()->decision_rules);
    }

    #[Test]
    public function a_rule_bundle_still_fails_closed_on_a_missing_production_proposal(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle(proposalCount: 1, withRules: true);

        $this->restoreMachineBase($service, $preReviewHash);
        $service->mergeProposals()->delete();

        $plan = app(ChurchServiceConvergenceBundleImporter::class)->prepareService($bundle);

        $this->assertSame('blocked_difference', $plan->classification);
        $this->assertStringContainsString('proposal identities differ', $plan->reason);
    }

    #[Test]
    public function bundle_b_keeps_every_decision_rule_when_a_service_spans_two_classes(): void
    {
        [, , $bundle] = $this->reviewedBundle(proposalCount: 2, withRules: true);
        $exportedRules = $bundle['services'][0]['review']['decision_rules'];

        $this->assertCount(
            2,
            $exportedRules,
            'A service settled by two authoring acts must carry both, not silently drop them.',
        );
        $this->assertNotSame($exportedRules[0]['class_key'], $exportedRules[1]['class_key']);
        $this->assertCount(2, $bundle['services'][0]['review']['proposal_dispositions']);
    }

    /** @return array{ChurchService, User, array<string, mixed>, string} */
    private function reviewedBundle(int $proposalCount = 1, bool $withRules = false): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
            'canonical_revision' => 0,
            'canonical_hash' => null,
        ]);
        $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
        $normalizer = app(ChurchServiceAssertionNormalizer::class);
        $machineAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Machine title',
        ]], ChurchServiceEvidenceKind::Planned);
        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'email-message',
            inputHash: CanonicalJson::hash($machineAssertions),
            assertions: $machineAssertions,
            processingFingerprint: ['version' => 1],
        ));
        $service = $service->fresh() ?? $service;
        $machineRecord = $service->sourceRecords()
            ->where('source', ChurchServiceSource::Email->value)
            ->firstOrFail();
        $preReviewHash = $service->canonical_hash;
        $manualAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Reviewed title',
        ]], ChurchServiceEvidenceKind::Manual);
        $manual = app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: 'review:portable-review',
            inputHash: CanonicalJson::hash($manualAssertions),
            assertions: $manualAssertions,
            processingFingerprint: ['format' => 'manual-review', 'version' => 1],
            serviceContent: ['summary' => null, 'notices' => [], 'chapter_markers' => []],
            createdByUserId: $reviewer->id,
        ));
        $service = $service->fresh() ?? $service;
        $proposals = collect(range(1, $proposalCount))->map(function (int $index) use ($service, $machineRecord, $preReviewHash, $reviewer, $withRules): ChurchServiceMergeProposal {
            $proposal = ChurchServiceMergeProposal::factory()->create([
                'church_service_id' => $service->id,
                'trigger_source_record_id' => $machineRecord->id,
                'base_canonical_revision' => $service->canonical_revision - 1,
                'base_canonical_hash' => $preReviewHash,
                'status' => ChurchServiceProposalStatus::Accepted,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            if (! $withRules) {
                return $proposal;
            }

            $rule = ChurchServiceProposalDecisionRule::factory()->create([
                'class_key' => hash('sha256', "class-{$index}"),
                'disposition' => 'accepted',
                'proposal_identities' => [app(ChurchServiceProposalIdentity::class)->for($proposal)],
                'rationale' => 'Authored once against the whole class.',
                'reviewed_by_user_id' => $reviewer->id,
            ]);
            $proposal->forceFill(['decision_rule_id' => $rule->id])->save();

            return $proposal;
        });
        $service->forceFill(['reviewed_canonical_revision' => $service->canonical_revision])->saveQuietly();
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'review_uuid' => 'portable-review',
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $preReviewHash,
            'included_proposal_ids' => $proposals->pluck('id')->all(),
            'proposal_dispositions' => $proposals
                ->map(fn (ChurchServiceMergeProposal $proposal): array => [
                    'proposal_id' => $proposal->id,
                    'disposition' => 'accepted',
                    'rationale' => 'The machine proposal was explicitly accepted.',
                ])
                ->all(),
            'manual_source_record_id' => $manual->sourceRecord->id,
            'resulting_canonical_revision' => $service->canonical_revision,
            'resulting_canonical_hash' => $service->canonical_hash,
            'reviewed_by_user_id' => $reviewer->id,
            'completed_at' => now(),
        ]);
        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
        );

        return [$service, $reviewer, $bundle, $preReviewHash];
    }

    private function restoreMachineBase(ChurchService $service, string $preReviewHash): void
    {
        $service->reviewSessions()->delete();
        $manuals = $service->sourceRecords()->where('source', ChurchServiceSource::Manual->value)->get();

        foreach ($manuals as $manual) {
            $manual->assertions()->delete();
            $manual->delete();
        }

        $service->items()->delete();
        $service->forceFill([
            'canonical_revision' => 0,
            'canonical_hash' => null,
            'reviewed_canonical_revision' => null,
        ])->saveQuietly();
        $records = $service->sourceRecords()->with(['assertions', 'assertions.sourceRecord'])->get();
        app(ChurchServiceProjectionPersister::class)->apply(
            $service,
            app(ChurchServiceProjector::class)->project($records),
        );
        $this->assertSame($preReviewHash, $service->fresh()->canonical_hash);
    }
}
