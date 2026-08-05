<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceConvergenceImportPlan;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\Song;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class ChurchServiceConvergenceBundleImporter
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceProjectionPersister $persister,
        private readonly ChurchServiceEvidenceSet $evidenceSet,
        private readonly ChurchServiceProposalIdentity $proposalIdentity,
    ) {}

    /** @param array<string, mixed> $bundle */
    public function prepareService(array $bundle, int $serviceIndex = 0): ChurchServiceConvergenceImportPlan
    {
        $bundle = $this->bundles->validate($bundle);
        $payload = $bundle['services'][$serviceIndex] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException("Convergence service index {$serviceIndex} does not exist.");
        }

        $matches = ChurchService::query()
            ->whereDate('date', $payload['date'])
            ->where('service', $payload['service'])
            ->get();

        if ($matches->count() !== 1) {
            throw new RuntimeException('Convergence requires exactly one production service for the natural identity.');
        }

        $service = $matches->firstOrFail();
        $automatic = $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value;
        $reviewer = $automatic
            ? null
            : $this->resolveReviewer($payload['review']['reviewer_email_hash']);
        $classification = $automatic
            ? $this->classifyAutomatic($service, $payload)
            : $this->classify($service, $payload, $reviewer);
        $planHash = CanonicalJson::hash([
            'bundle_hash' => $bundle['bundle_hash'],
            'service' => [$payload['date'], $payload['service']],
            'classification' => $classification,
            'current_canonical_hash' => $service->canonical_hash,
        ]);

        return new ChurchServiceConvergenceImportPlan(
            classification: $classification['classification'],
            reason: $classification['reason'],
            planHash: $planHash,
            bundleHash: $bundle['bundle_hash'],
            churchService: $service,
            reviewer: $reviewer,
            servicePayload: $payload,
        );
    }

    /**
     * Prepare Bundle B against the exact Livestream revision that Bundle A will
     * ingest, without inserting a source record or projecting canonical rows.
     * This is required for a fresh historic service: production cannot contain
     * the Livestream evidence until Bundle A applies, but the complete operation
     * still has to be preflighted before its first write.
     *
     * `$processingKey` is Bundle A's portable run identity. The preflight has to
     * remap the revision's assertion metadata exactly as the apply will, because
     * the canonical hash is derived from `livestream_processing_id` — projecting
     * the bundle's raw `livestream_service_section_key` instead would produce a
     * hash the apply can never reproduce, and every service carrying a
     * section-anchored assertion would stay blocked forever.
     *
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $livestreamRevision
     */
    public function prepareServiceForHistoricImport(
        array $bundle,
        int $serviceIndex,
        array $livestreamRevision,
        string $processingKey,
    ): ChurchServiceConvergenceImportPlan {
        $plan = $this->prepareService($bundle, $serviceIndex);

        if (! in_array($plan->classification, [
            HistoricImportClassification::BlockedDifference->value,
            HistoricImportClassification::Conflict->value,
        ], true)) {
            return $plan;
        }

        $payload = $plan->servicePayload;

        if (
            $payload['finalization'] === ChurchServiceCanonicalFinalization::Manual->value
            && ! $plan->reviewer instanceof User
        ) {
            return $plan;
        }

        $service = $plan->churchService->fresh([
            'sourceRecords.assertions',
            'reviewSessions',
        ]) ?? $plan->churchService;

        if (
            $payload['finalization'] === ChurchServiceCanonicalFinalization::Manual->value
            && $service->reviewSessions->firstWhere('review_uuid', $payload['review']['review_uuid'])
                instanceof ChurchServiceReviewSession
        ) {
            return $plan;
        }

        if (
            $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value
            && $this->projector->activeManualSourceRecord($service->sourceRecords) instanceof ChurchServiceSourceRecord
        ) {
            return $plan;
        }

        if (
            $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value
            && $payload['projection_policy'] !== $this->projector->policyFingerprint()
        ) {
            return $plan;
        }

        $evidenceDiffers = $this->evidenceSet->hash($service->sourceRecords) !== $payload['evidence_set_hash'];
        $canonicalDiffers = $service->canonical_hash !== $payload['pre_review_hash'];

        if (! $evidenceDiffers && ($payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value || ! $canonicalDiffers)) {
            return $plan;
        }

        $records = $service->sourceRecords->values();
        $records = $this->withProspectiveLivestreamRevision($records, $livestreamRevision, $service, $processingKey);
        $evidenceHash = $this->evidenceSet->hash($records);

        if ($evidenceHash !== $payload['evidence_set_hash']) {
            return $plan;
        }

        $projection = $this->projector->project($records);
        $expectedHash = $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value
            ? $payload['resulting_canonical_hash']
            : $payload['pre_review_hash'];

        if ($projection->hash !== $expectedHash) {
            return $plan;
        }

        if (
            $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value
            && ! $this->projector->hasCompleteAudit($records, $projection)
        ) {
            return $plan;
        }

        $planHash = CanonicalJson::hash([
            'bundle_hash' => $plan->bundleHash,
            'service' => [$payload['date'], $payload['service']],
            'classification' => [
                'classification' => HistoricImportClassification::SafeEnrichment->value,
                'reason' => 'Prospective Bundle A Livestream evidence reproduces the reviewed state.',
                'evidence_set_hash' => $evidenceHash,
                'projection_hash' => $projection->hash,
            ],
            'current_canonical_hash' => $service->canonical_hash,
        ]);

        return new ChurchServiceConvergenceImportPlan(
            classification: HistoricImportClassification::SafeEnrichment->value,
            reason: 'Prospective Bundle A Livestream evidence reproduces the reviewed state.',
            planHash: $planHash,
            bundleHash: $plan->bundleHash,
            churchService: $service,
            reviewer: $plan->reviewer,
            servicePayload: $payload,
        );
    }

    public function persistPreparedService(
        ChurchServiceConvergenceImportPlan $plan,
        string $planHash,
        bool $dispatchEvents = true,
    ): ChurchService {
        if (! hash_equals($plan->planHash, $planHash)) {
            throw new RuntimeException('Convergence import plan hash does not match.');
        }

        if ($plan->classification === HistoricImportClassification::AlreadyPresent->value) {
            return $plan->churchService->fresh() ?? $plan->churchService;
        }

        if ($plan->classification !== HistoricImportClassification::SafeEnrichment->value) {
            throw new RuntimeException("Convergence import is {$plan->classification}: {$plan->reason}");
        }

        $payload = $plan->servicePayload;

        if ($payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value) {
            return $this->persistAutomatic($plan, $dispatchEvents);
        }

        if (! $plan->reviewer instanceof User) {
            throw new RuntimeException("Convergence import is {$plan->classification}: {$plan->reason}");
        }

        $manual = $payload['manual_revision'];
        $expectedRevisionHash = CanonicalJson::hash([
            'assertions' => $manual['assertions'],
            'service_content' => $manual['service_content'],
        ]);

        if (! hash_equals($manual['revision_hash'], $expectedRevisionHash)) {
            throw new RuntimeException('Manual revision hash changed during natural-identity remapping.');
        }

        $assertions = $this->remapAssertions($manual['assertions']);
        $ingestion = $this->ingestSourceRevision->execute(
            $plan->churchService,
            new ChurchServiceSourceRevision(
                source: ChurchServiceSource::Manual,
                sourceKey: $manual['source_key'],
                inputHash: $manual['input_hash'],
                assertions: $assertions,
                processingFingerprint: $manual['processing_fingerprint'],
                serviceContent: $manual['service_content'],
                batchHash: $manual['batch_hash'],
                payloadComplete: $manual['payload_complete'],
                capturedAt: Carbon::parse($manual['captured_at']),
                createdByUserId: $plan->reviewer->id,
            ),
            dispatchEvents: $dispatchEvents,
        );
        $service = $plan->churchService->fresh(['items.song']) ?? $plan->churchService;

        if ($service->canonical_hash !== $payload['resulting_canonical_hash']) {
            throw new RuntimeException('Applied Manual revision did not reproduce the reviewed canonical hash.');
        }

        $review = $this->createReview($service, $ingestion->sourceRecord, $plan->reviewer, $payload);
        $this->createDecisions($review, $payload['review']['decisions'], $service);
        $this->applyProposalDispositions(
            $service,
            $payload['review']['proposal_dispositions'],
            $payload['review']['decision_rules'],
            $plan->reviewer,
        );
        $service->forceFill([
            'reviewed_canonical_revision' => $service->canonical_revision,
            'needs_review' => false,
            'review_reason' => null,
            'source_summary' => ChurchServiceSource::Manual->value,
            'source' => ChurchServiceSource::Manual->value,
        ])->saveQuietly();

        if ($this->manifests->build($service->fresh(['items.song']) ?? $service) !== $payload['canonical_manifest']) {
            throw new RuntimeException('Applied convergence manifest differs from the reviewed local manifest.');
        }

        return $service->fresh() ?? $service;
    }

    /**
     * A machine-final service carries no human decision to replay. The bundle is
     * a claim that this evidence set projects to this canonical state, so the
     * import verifies the claim against production's own evidence and re-runs
     * the projector; it never copies canonical rows across.
     *
     * @param  array<string, mixed>  $payload
     * @return array{classification: 'already_present'|'create'|'safe_enrichment'|'blocked_difference'|'conflict', reason: string}
     */
    private function classifyAutomatic(ChurchService $service, array $payload): array
    {
        if ($this->projector->activeManualSourceRecord($service->sourceRecords) instanceof ChurchServiceSourceRecord) {
            return [
                'classification' => HistoricImportClassification::Conflict->value,
                'reason' => 'Production holds a Manual revision, so this service is not machine-final there.',
            ];
        }

        if ($this->evidenceSet->hash($service->sourceRecords) !== $payload['evidence_set_hash']) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Production machine evidence differs from the exported evidence set.',
            ];
        }

        if ($payload['projection_policy'] !== $this->projector->policyFingerprint()) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Production projection policy differs from the policy that produced this bundle.',
            ];
        }

        $projection = $this->projector->project($service->sourceRecords);

        if ($projection->hash !== $payload['resulting_canonical_hash']) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Re-projecting production evidence does not reproduce the exported canonical hash.',
            ];
        }

        if (! $this->projector->hasCompleteAudit($service->sourceRecords, $projection)) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Re-projecting production evidence does not yield a complete projection audit.',
            ];
        }

        $alreadyPresent = $service->canonical_hash === $payload['resulting_canonical_hash']
            && $service->canonical_finalization === ChurchServiceCanonicalFinalization::Automatic
            && $service->projection_policy_version === $payload['projection_policy']['version']
            && $this->manifests->build($service) === $payload['canonical_manifest'];

        return $alreadyPresent
            ? [
                'classification' => HistoricImportClassification::AlreadyPresent->value,
                'reason' => 'The exact machine-final convergence is already present.',
            ]
            : [
                'classification' => HistoricImportClassification::SafeEnrichment->value,
                'reason' => 'Production evidence reproduces the exported projection and is ready to persist.',
            ];
    }

    private function persistAutomatic(
        ChurchServiceConvergenceImportPlan $plan,
        bool $dispatchEvents,
    ): ChurchService {
        $payload = $plan->servicePayload;
        $service = $plan->churchService;
        $projection = $this->projector->project($service->sourceRecords);

        $this->persister->apply($service, $projection, $projection->sourceSummary, $dispatchEvents);
        $service = $service->fresh(['items.song']) ?? $service;

        if ($service->canonical_hash !== $payload['resulting_canonical_hash']) {
            throw new RuntimeException('Re-projected machine evidence did not reproduce the exported canonical hash.');
        }

        if ($this->manifests->build($service) !== $payload['canonical_manifest']) {
            throw new RuntimeException('Applied convergence manifest differs from the exported machine-final manifest.');
        }

        return $service->fresh() ?? $service;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{classification: 'already_present'|'create'|'safe_enrichment'|'blocked_difference'|'conflict', reason: string}
     */
    private function classify(ChurchService $service, array $payload, ?User $reviewer): array
    {
        $existingReview = $service->reviewSessions()
            ->where('review_uuid', $payload['review']['review_uuid'])
            ->first();

        if ($existingReview instanceof ChurchServiceReviewSession) {
            return $service->canonical_hash === $payload['resulting_canonical_hash']
                && $this->manifests->build($service) === $payload['canonical_manifest']
                ? [
                    'classification' => HistoricImportClassification::AlreadyPresent->value,
                    'reason' => 'The exact reviewed convergence is already present.',
                ]
                : [
                    'classification' => HistoricImportClassification::Conflict->value,
                    'reason' => 'Review UUID exists with different canonical content.',
                ];
        }

        if (! $reviewer instanceof User) {
            return [
                'classification' => HistoricImportClassification::Conflict->value,
                'reason' => 'Reviewer email hash does not resolve uniquely.',
            ];
        }

        if ($this->evidenceSet->hash($service->sourceRecords) !== $payload['evidence_set_hash']) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Production machine evidence differs from the reviewed evidence set.',
            ];
        }

        if ($service->canonical_hash !== $payload['pre_review_hash']) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Production pre-review canonical hash differs from local.',
            ];
        }

        $proposalClassification = $this->classifyProposalDispositions($service, $payload['review']['proposal_dispositions']);

        if ($proposalClassification !== null) {
            return $proposalClassification;
        }

        return [
            'classification' => HistoricImportClassification::SafeEnrichment->value,
            'reason' => 'Reviewed Manual revision is ready to apply.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dispositions
     * @return array{classification: 'blocked_difference', reason: string}|null
     */
    private function classifyProposalDispositions(ChurchService $service, array $dispositions): ?array
    {
        $expected = collect($dispositions)
            ->map(fn (array $disposition): string => (string) $disposition['proposal_identity'])
            ->sort()
            ->values()
            ->all();
        $productionProposals = $service->mergeProposals()
            ->with('triggerSourceRecord')
            ->where('status', '!=', ChurchServiceProposalStatus::Stale->value)
            ->get();
        $actual = $productionProposals
            ->map(fn (ChurchServiceMergeProposal $proposal): string => $this->proposalIdentity->for($proposal))
            ->sort()
            ->values()
            ->all();

        if ($expected !== $actual) {
            return [
                'classification' => HistoricImportClassification::BlockedDifference->value,
                'reason' => 'Production proposal identities differ from the reviewed proposal set.',
            ];
        }

        $payloadByIdentity = collect($dispositions)->keyBy(
            fn (array $disposition): string => (string) $disposition['proposal_identity'],
        );

        foreach ($productionProposals as $proposal) {
            $identity = $this->proposalIdentity->for($proposal);
            $disposition = $payloadByIdentity->get($identity);

            if (! is_array($disposition)) {
                return [
                    'classification' => HistoricImportClassification::BlockedDifference->value,
                    'reason' => 'A reviewed proposal disposition could not be resolved in production.',
                ];
            }

            if ($proposal->status !== ChurchServiceProposalStatus::Pending
                && $proposal->status->value !== $disposition['disposition']) {
                return [
                    'classification' => HistoricImportClassification::BlockedDifference->value,
                    'reason' => 'Production already contains a different proposal disposition.',
                ];
            }
        }

        return null;
    }

    private function resolveReviewer(string $emailHash): ?User
    {
        $matches = User::query()->get(['id', 'email'])->filter(
            fn (User $user): bool => hash_equals($emailHash, hash('sha256', mb_strtolower(trim($user->email)))),
        );

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @param  array<string, mixed>  $payload
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function withProspectiveLivestreamRevision(
        Collection $records,
        array $payload,
        ChurchService $service,
        string $processingKey,
    ): Collection {
        $sourceKey = $payload['source_key'] ?? null;
        $revisionHash = $payload['revision_hash'] ?? null;

        if (! is_string($sourceKey) || ! is_string($revisionHash)) {
            return $records;
        }

        $existing = $records->first(
            fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Livestream
                && $record->source_key === $sourceKey
                && $record->revision_hash === $revisionHash,
        );

        if ($existing instanceof ChurchServiceSourceRecord) {
            return $records;
        }

        $predecessor = $records
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Livestream
                && $record->source_key === $sourceKey)
            ->sortByDesc('id')
            ->first();
        $record = new ChurchServiceSourceRecord;
        $record->forceFill([
            'id' => -1,
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Livestream->value,
            'source_key' => $sourceKey,
            'revision_hash' => $revisionHash,
            'input_hash' => $payload['input_hash'],
            'supersedes_id' => $predecessor?->id,
            'batch_hash' => $payload['batch_hash'],
            'processing_fingerprint' => $payload['processing_fingerprint'],
            'service_content' => $payload['service_content'],
            'payload_complete' => $payload['payload_complete'],
            'captured_at' => isset($payload['captured_at']) ? Carbon::parse($payload['captured_at']) : null,
        ]);
        $record->exists = true;
        $assertions = [];

        foreach ($payload['assertions'] as $assertionPayload) {
            if (! is_array($assertionPayload)) {
                continue;
            }

            $assertion = new ChurchServiceItemAssertion;
            $assertion->forceFill([
                ...$assertionPayload,
                'metadata' => $this->prospectiveAssertionMetadata($assertionPayload, $processingKey),
                'source_record_id' => -1,
            ]);
            $assertion->exists = true;
            $assertion->setRelation('sourceRecord', $record);
            $assertions[] = $assertion;
        }

        $record->setRelation('assertions', collect($assertions));

        return $records->push($record)->values();
    }

    /**
     * Mirror the metadata remapping ConvergeHistoricChurchService performs when
     * it ingests the revision for real: the portable section key becomes the
     * run's processing identity. The local section id it also writes is
     * deliberately absent — the canonical hash strips it, so a preflight that
     * cannot know it yet still projects the hash the apply will produce.
     *
     * @param  array<string, mixed>  $assertion
     * @return array<string, mixed>|null
     */
    private function prospectiveAssertionMetadata(array $assertion, string $processingKey): ?array
    {
        $metadata = is_array($assertion['metadata'] ?? null) ? $assertion['metadata'] : [];

        if (array_key_exists('livestream_service_section_key', $metadata)) {
            unset($metadata['livestream_service_section_key']);
            $metadata['livestream_processing_id'] = $processingKey;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * @param  list<array<string, mixed>>  $assertions
     * @return list<array<string, mixed>>
     */
    private function remapAssertions(array $assertions): array
    {
        return array_map(function (array $assertion): array {
            $canonicalKey = $assertion['song_canonical_key'] ?? null;
            $assertion['song_id'] = is_string($canonicalKey)
                ? Song::query()->where('canonical_key', $canonicalKey)->value('id')
                : null;

            if (is_string($canonicalKey) && $assertion['song_id'] === null) {
                throw new RuntimeException("Manual assertion song does not exist: {$canonicalKey}.");
            }

            return $assertion;
        }, $assertions);
    }

    /** @param array<string, mixed> $payload */
    private function createReview(
        ChurchService $service,
        ChurchServiceSourceRecord $manual,
        User $reviewer,
        array $payload,
    ): ChurchServiceReviewSession {
        $proposalDispositions = $payload['review']['proposal_dispositions'];
        $includedProposalIds = $service->mergeProposals()
            ->with('triggerSourceRecord')
            ->where('status', '!=', ChurchServiceProposalStatus::Stale->value)
            ->get()
            ->filter(function (ChurchServiceMergeProposal $proposal) use ($proposalDispositions): bool {
                $identity = $this->proposalIdentity->for($proposal);

                foreach ($proposalDispositions as $disposition) {
                    if (is_array($disposition) && $disposition['proposal_identity'] === $identity) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->values()
            ->all();

        return $service->reviewSessions()->create([
            'review_uuid' => $payload['review']['review_uuid'],
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $payload['pre_review_hash'],
            'included_proposal_ids' => $includedProposalIds,
            'proposal_dispositions' => $proposalDispositions,
            'decision_rules' => $payload['review']['decision_rules'],
            'service_field_decisions' => $payload['review']['service_field_decisions'],
            'manual_source_record_id' => $manual->id,
            'resulting_canonical_revision' => $service->canonical_revision,
            'resulting_canonical_hash' => $service->canonical_hash,
            'reviewed_by_user_id' => $reviewer->id,
            'completed_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $decisions */
    private function createDecisions(
        ChurchServiceReviewSession $review,
        array $decisions,
        ChurchService $service,
    ): void {
        foreach ($decisions as $decision) {
            $assertion = $this->resolveAssertion($service, $decision['selected_assertion_identity'] ?? null);
            $songId = is_string($decision['song_canonical_key'] ?? null)
                ? Song::query()->where('canonical_key', $decision['song_canonical_key'])->value('id')
                : null;

            $review->decisions()->create([
                'selected_assertion_id' => $assertion?->id,
                'included' => $decision['included'],
                'final_position' => $decision['final_position'],
                'custom_value' => $decision['custom_value'],
                'song_id' => $songId,
                'song_canonical_key' => $decision['song_canonical_key'],
                'scripture_reference' => $decision['scripture_reference'],
                'occurrence_decision' => $decision['occurrence_decision'],
                'rationale' => $decision['rationale'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $dispositions
     * @param  list<array<string, mixed>>  $decisionRules
     */
    private function applyProposalDispositions(
        ChurchService $service,
        array $dispositions,
        array $decisionRules,
        User $reviewer,
    ): void {
        $proposals = $service->mergeProposals()
            ->with('triggerSourceRecord')
            ->where('status', '!=', ChurchServiceProposalStatus::Stale->value)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ChurchServiceMergeProposal $proposal): string => $this->proposalIdentity->for($proposal));
        $ruleIdsByIdentity = $this->reproduceDecisionRules($decisionRules, $reviewer);

        foreach ($dispositions as $disposition) {
            $identity = (string) $disposition['proposal_identity'];
            $proposal = $proposals->get($identity);

            if (! $proposal instanceof ChurchServiceMergeProposal) {
                throw new RuntimeException('Reviewed convergence proposal identity does not resolve in production.');
            }

            $proposal->forceFill([
                'status' => $disposition['disposition'],
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'decision_rule_id' => $ruleIdsByIdentity[$identity] ?? null,
            ])->save();
        }
    }

    /**
     * Rebuilds the authoring acts in production so a rule-dispositioned proposal can
     * still answer which act settled it, and returns the rule each portable proposal
     * identity belongs to.
     *
     * @param  list<array<string, mixed>>  $decisionRules
     * @return array<string, int>
     */
    private function reproduceDecisionRules(array $decisionRules, User $reviewer): array
    {
        $ruleIdsByIdentity = [];

        foreach ($decisionRules as $payload) {
            $rule = ChurchServiceProposalDecisionRule::query()->firstOrCreate(
                [
                    'class_key' => $payload['class_key'],
                    'disposition' => $payload['disposition'],
                    'rationale' => $payload['rationale'],
                    'reviewed_by_user_id' => $reviewer->id,
                ],
                [
                    'match_tier' => $payload['match_tier'] ?? null,
                    'proposal_identities' => $payload['proposal_identities'],
                    'applied_at' => now(),
                ],
            );

            foreach ($payload['proposal_identities'] as $identity) {
                if (is_string($identity)) {
                    $ruleIdsByIdentity[$identity] = (int) $rule->getKey();
                }
            }
        }

        return $ruleIdsByIdentity;
    }

    private function resolveAssertion(ChurchService $service, mixed $identity): ?ChurchServiceItemAssertion
    {
        if (! is_string($identity)) {
            return null;
        }

        $parts = explode(':', $identity, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('Reviewed assertion identity is invalid.');
        }

        [$revisionHash, $assertionKey] = $parts;

        return ChurchServiceItemAssertion::query()
            ->where('assertion_key', $assertionKey)
            ->whereHas('sourceRecord', fn ($query) => $query
                ->whereBelongsTo($service)
                ->where('revision_hash', $revisionHash))
            ->firstOrFail();
    }
}
