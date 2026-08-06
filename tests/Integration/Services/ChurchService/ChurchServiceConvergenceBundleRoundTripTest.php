<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ChurchServiceProposalIdentity;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G3/PR11. Every other Bundle B test rewinds the *same* service to its
 * pre-review state and re-imports, so the reviewer, the assertions, the
 * proposals and the decision rules all keep the primary keys they were exported
 * under. Local-id coupling in any of those resolutions is invisible under that
 * setup.
 *
 * This exports a reviewed bundle, destroys the database it came from, rebuilds
 * an equivalent machine base on deliberately shifted primary keys, and applies
 * the bundle to it. §9.3 requires production to resolve the reviewer by
 * approved email hash and every proposal by portable identity; this is where
 * that stops being an assertion in a document. §13.5 step 12 is the only other
 * place it would surface — against production.
 */
class ChurchServiceConvergenceBundleRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private const int PRIMARY_KEY_SHIFT = 5000;

    private const string REVIEWER_EMAIL = 'reviewer@example.com';

    /** Pinned so both sides of the round trip produce the same portable proposal identity. */
    private const string PROPOSED_HASH = 'aa11bb22cc33dd44ee55ff6600778899aa11bb22cc33dd44ee55ff6600778899';

    private const array SHIFTED_TABLES = [
        'church_services',
        'church_service_items',
        'church_service_source_records',
        'church_service_item_assertions',
        'church_service_merge_proposals',
        'church_service_review_sessions',
        'church_service_proposal_decision_rules',
        'users',
    ];

    #[Test]
    public function a_reviewed_bundle_applies_exactly_in_a_different_pk_database(): void
    {
        [$bundle, $reviewedHash, $sourceIds] = $this->exportReviewedBundle();

        $this->tearDownSourceDatabase();
        $this->shiftPrimaryKeys();
        $this->buildMachineBase();

        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        $this->assertSame('safe_enrichment', $plan->classification, $plan->reason);

        $applied = $importer->persistPreparedService($plan, $plan->planHash);

        /**
         * Exact finalisation. The canonical hash is the whole point of the
         * bundle: if any part of the projection folded in a local id, applying
         * the same decision to a differently keyed graph would produce a
         * different result here.
         */
        $this->assertSame($reviewedHash, $applied->canonical_hash);
        $this->assertSame('Reviewed title', $applied->items()->sole()->title);

        $this->assertPrimaryKeysActuallyMoved($sourceIds);

        $reviewer = User::query()->where('email', self::REVIEWER_EMAIL)->sole();
        $proposal = $applied->mergeProposals()->sole();

        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposal->status);
        $this->assertSame(
            $reviewer->id,
            $proposal->resolved_by_user_id,
            'The reviewer must be resolved by approved email hash, not by a copied local id.',
        );
        $this->assertNotNull($proposal->resolved_at);

        $session = $applied->reviewSessions()->sole();

        $this->assertSame($reviewer->id, $session->reviewed_by_user_id);
        $this->assertSame(
            $bundle['services'][0]['review']['proposal_dispositions'][0]['disposition'],
            $session->proposal_dispositions[0]['disposition'],
        );
        $this->assertSame(
            $bundle['services'][0]['review']['proposal_dispositions'][0]['rationale'],
            $session->proposal_dispositions[0]['rationale'],
        );
        $this->assertSame(
            [$proposal->id],
            $session->included_proposal_ids,
            'The review session must name the production proposal, not the exported one.',
        );
    }

    /**
     * The identities are portable, so a rule authored once must reproduce
     * against production rows it has never seen — and still carry its own
     * rationale rather than the per-proposal one.
     */
    #[Test]
    public function a_rule_disposition_reproduces_against_shifted_primary_keys(): void
    {
        [$bundle, , $sourceIds] = $this->exportReviewedBundle(withRule: true);

        $this->tearDownSourceDatabase();
        $this->shiftPrimaryKeys();
        $this->buildMachineBase();

        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);
        $applied = $importer->persistPreparedService($plan, $plan->planHash);
        $proposal = $applied->mergeProposals()->sole();

        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposal->status);
        $this->assertNotNull($proposal->decision_rule_id);

        $rule = ChurchServiceProposalDecisionRule::query()->findOrFail($proposal->decision_rule_id);

        $this->assertSame('Authored once against the whole class.', $rule->rationale);
        $this->assertSame(
            User::query()->where('email', self::REVIEWER_EMAIL)->sole()->id,
            $rule->reviewed_by_user_id,
        );
        $this->assertPrimaryKeysActuallyMoved($sourceIds);
    }

    /**
     * The per-proposal check must still be the unit of verification. A
     * production graph missing one of the reviewed proposals fails closed
     * rather than applying the rest — §9.3.
     */
    #[Test]
    public function a_proposal_absent_from_the_different_pk_database_fails_closed(): void
    {
        [$bundle] = $this->exportReviewedBundle();

        $this->tearDownSourceDatabase();
        $this->shiftPrimaryKeys();
        $this->buildMachineBase();

        ChurchServiceMergeProposal::query()->delete();

        $plan = app(ChurchServiceConvergenceBundleImporter::class)->prepareService($bundle);

        $this->assertSame('blocked_difference', $plan->classification);
        $this->assertStringContainsString('proposal', $plan->reason);
    }

    /**
     * Build the reviewed source service and export it, returning the bundle,
     * the canonical hash the review produced, and the primary keys it used.
     *
     * @return array{array<string, mixed>, string, array<string, int>}
     */
    private function exportReviewedBundle(bool $withRule = false): array
    {
        ['service' => $service, 'reviewer' => $reviewer, 'proposal' => $proposal] = $this->buildMachineBase();

        $normalizer = app(ChurchServiceAssertionNormalizer::class);
        $manualAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Reviewed title',
        ]], ChurchServiceEvidenceKind::Manual);
        $preReviewHash = $service->canonical_hash;
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

        if ($withRule) {
            $rule = ChurchServiceProposalDecisionRule::factory()->create([
                'class_key' => hash('sha256', 'round-trip-class'),
                'disposition' => 'accepted',
                'proposal_identities' => [app(ChurchServiceProposalIdentity::class)->for($proposal)],
                'rationale' => 'Authored once against the whole class.',
                'reviewed_by_user_id' => $reviewer->id,
            ]);
            $proposal->forceFill(['decision_rule_id' => $rule->id])->save();
        }

        $proposal->forceFill([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $reviewer->id,
            'resolved_at' => now(),
        ])->save();

        $service->forceFill(['reviewed_canonical_revision' => $service->canonical_revision])->saveQuietly();
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'review_uuid' => 'portable-review',
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $preReviewHash,
            'included_proposal_ids' => [$proposal->id],
            'proposal_dispositions' => [[
                'proposal_id' => $proposal->id,
                'disposition' => 'accepted',
                'rationale' => 'The machine proposal was explicitly accepted.',
            ]],
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

        return [$bundle, (string) $service->canonical_hash, $this->currentIds()];
    }

    /**
     * The machine-only state both sides of the round trip share: a service, the
     * approved reviewer, one Email revision and the proposal it triggered.
     * Building production from the same helper is deliberate — the only
     * difference between the two graphs is then the primary keys.
     *
     * @return array{service: ChurchService, reviewer: User, proposal: ChurchServiceMergeProposal}
     */
    private function buildMachineBase(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
            'canonical_revision' => 0,
            'canonical_hash' => null,
        ]);
        $reviewer = User::factory()->create(['email' => self::REVIEWER_EMAIL]);
        $machineAssertions = app(ChurchServiceAssertionNormalizer::class)->normalize([[
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

        $proposal = ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'trigger_source_record_id' => $machineRecord->id,
            'base_canonical_revision' => $service->canonical_revision,
            'base_canonical_hash' => $service->canonical_hash,
            'proposed_hash' => self::PROPOSED_HASH,
            'status' => ChurchServiceProposalStatus::Pending,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ]);

        return ['service' => $service, 'reviewer' => $reviewer, 'proposal' => $proposal];
    }

    /**
     * Remove the database the bundle was exported from. The bundle is now the
     * only surviving description of the decision, which is exactly its role.
     */
    private function tearDownSourceDatabase(): void
    {
        ChurchServiceMergeProposal::query()->update(['decision_rule_id' => null]);
        ChurchServiceProposalDecisionRule::query()->delete();
        ChurchServiceReviewSession::query()->delete();
        ChurchServiceMergeProposal::query()->delete();
        ChurchServiceItemAssertion::query()->delete();
        ChurchServiceSourceRecord::query()->delete();
        ChurchService::query()->each(fn (ChurchService $service) => $service->items()->delete());
        ChurchService::query()->delete();
        User::query()->delete();
    }

    private function shiftPrimaryKeys(): void
    {
        foreach (self::SHIFTED_TABLES as $table) {
            $next = ((int) DB::table($table)->max('id')) + self::PRIMARY_KEY_SHIFT;
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
        }
    }

    /** @return array<string, int> */
    private function currentIds(): array
    {
        $ids = [];

        foreach (self::SHIFTED_TABLES as $table) {
            $ids[$table] = (int) DB::table($table)->max('id');
        }

        return $ids;
    }

    /**
     * Prove the production graph really is differently keyed, or the equality
     * assertions above are being made against the ids they were exported under.
     *
     * @param  array<string, int>  $sourceIds
     */
    private function assertPrimaryKeysActuallyMoved(array $sourceIds): void
    {
        foreach (['church_services', 'church_service_source_records', 'church_service_merge_proposals', 'users'] as $table) {
            $minimum = DB::table($table)->min('id');

            $this->assertNotNull($minimum, "Production must have rebuilt {$table}.");
            $this->assertGreaterThan(
                $sourceIds[$table],
                (int) $minimum,
                "Every production {$table} row must sit above the ids the bundle was exported under.",
            );
        }
    }
}
