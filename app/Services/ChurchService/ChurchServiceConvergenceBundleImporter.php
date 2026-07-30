<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceConvergenceImportPlan;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\Song;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use RuntimeException;

class ChurchServiceConvergenceBundleImporter
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
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
        $reviewer = $this->resolveReviewer($payload['review']['reviewer_email_hash']);
        $classification = $this->classify($service, $payload, $reviewer);
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

    public function persistPreparedService(
        ChurchServiceConvergenceImportPlan $plan,
        string $planHash,
    ): ChurchService {
        if (! hash_equals($plan->planHash, $planHash)) {
            throw new RuntimeException('Convergence import plan hash does not match.');
        }

        if ($plan->classification === 'already_present') {
            return $plan->churchService->fresh() ?? $plan->churchService;
        }

        if ($plan->classification !== 'apply' || ! $plan->reviewer instanceof User) {
            throw new RuntimeException("Convergence import is {$plan->classification}: {$plan->reason}");
        }

        $payload = $plan->servicePayload;
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
        );
        $service = $plan->churchService->fresh(['items.song']) ?? $plan->churchService;

        if ($service->canonical_hash !== $payload['resulting_canonical_hash']) {
            throw new RuntimeException('Applied Manual revision did not reproduce the reviewed canonical hash.');
        }

        $review = $this->createReview($service, $ingestion->sourceRecord, $plan->reviewer, $payload);
        $this->createDecisions($review, $payload['review']['decisions'], $service);
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
     * @param  array<string, mixed>  $payload
     * @return array{classification: 'already_present'|'apply'|'blocked_difference'|'conflict', reason: string}
     */
    private function classify(ChurchService $service, array $payload, ?User $reviewer): array
    {
        $existingReview = $service->reviewSessions()
            ->where('review_uuid', $payload['review']['review_uuid'])
            ->first();

        if ($existingReview instanceof ChurchServiceReviewSession) {
            return $service->canonical_hash === $payload['resulting_canonical_hash']
                && $this->manifests->build($service) === $payload['canonical_manifest']
                ? ['classification' => 'already_present', 'reason' => 'The exact reviewed convergence is already present.']
                : ['classification' => 'conflict', 'reason' => 'Review UUID exists with different canonical content.'];
        }

        if (! $reviewer instanceof User) {
            return ['classification' => 'conflict', 'reason' => 'Reviewer email hash does not resolve uniquely.'];
        }

        if ($this->evidenceSetHash($service) !== $payload['evidence_set_hash']) {
            return ['classification' => 'blocked_difference', 'reason' => 'Production machine evidence differs from the reviewed evidence set.'];
        }

        if ($service->canonical_hash !== $payload['pre_review_hash']) {
            return ['classification' => 'blocked_difference', 'reason' => 'Production pre-review canonical hash differs from local.'];
        }

        return ['classification' => 'apply', 'reason' => 'Reviewed Manual revision is ready to apply.'];
    }

    private function resolveReviewer(string $emailHash): ?User
    {
        $matches = User::query()->get(['id', 'email'])->filter(
            fn (User $user): bool => hash_equals($emailHash, hash('sha256', mb_strtolower(trim($user->email)))),
        );

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function evidenceSetHash(ChurchService $service): string
    {
        $records = $service->sourceRecords()
            ->where('source', '!=', ChurchServiceSource::Manual->value)
            ->orderBy('revision_hash')
            ->get();

        return CanonicalJson::hash($records->map(fn (ChurchServiceSourceRecord $record): array => [
            'source' => $record->source->value,
            'source_key' => $record->source_key,
            'revision_hash' => $record->revision_hash,
            'processing_fingerprint' => $record->processing_fingerprint,
        ])->all());
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
        return $service->reviewSessions()->create([
            'review_uuid' => $payload['review']['review_uuid'],
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $payload['pre_review_hash'],
            'included_proposal_ids' => [],
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

    private function resolveAssertion(ChurchService $service, mixed $identity): ?ChurchServiceItemAssertion
    {
        if (! is_string($identity)) {
            return null;
        }

        [$revisionHash, $assertionKey] = explode(':', $identity, 2);

        return ChurchServiceItemAssertion::query()
            ->where('assertion_key', $assertionKey)
            ->whereHas('sourceRecord', fn ($query) => $query
                ->whereBelongsTo($service)
                ->where('revision_hash', $revisionHash))
            ->firstOrFail();
    }
}
