<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceCanonicalFinalization;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewDecision;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use RuntimeException;

class ChurchServiceConvergenceBundleExporter
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceEvidenceSet $evidenceSet,
    ) {}

    /**
     * @param  list<int>  $serviceIds
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    public function export(
        array $serviceIds,
        string $batchHash,
        string $mediaBundleHash,
        array $fingerprint,
    ): array {
        $services = ChurchService::query()
            ->with([
                'items.song',
                'sourceRecords.assertions.sourceRecord',
                'reviewSessions.decisions.selectedAssertion.sourceRecord',
                'reviewSessions.decisions.song',
                'reviewSessions.reviewedBy',
                'mergeProposals.triggerSourceRecord',
            ])
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        if ($serviceIds === [] || $services->count() !== count(array_unique($serviceIds))) {
            throw new RuntimeException('Every selected convergence service must exist exactly once.');
        }

        $payloads = [];

        foreach (array_values(array_unique($serviceIds)) as $serviceId) {
            $service = $services->get($serviceId);

            if (! $service instanceof ChurchService) {
                throw new RuntimeException("Convergence service {$serviceId} could not be loaded.");
            }

            $payloads[] = $this->service($service);
        }

        return $this->bundles->make($batchHash, $mediaBundleHash, $fingerprint, $payloads);
    }

    /** @return array<string, mixed> */
    private function service(ChurchService $service): array
    {
        $sourceRecords = $service->sourceRecords;
        $projection = $this->projector->project($sourceRecords);
        $review = $service->reviewSessions
            ->whereNotNull('completed_at')
            ->sortByDesc('completed_at')
            ->first();
        $manual = $this->projector->activeManualSourceRecord($sourceRecords);

        $common = [
            'date' => $service->date->toDateString(),
            'service' => $service->service->value,
            'evidence_set_hash' => $this->evidenceSet->hash($sourceRecords),
            'resulting_canonical_hash' => $service->canonical_hash,
            'projection_policy' => $projection->policyFingerprint,
            'canonical_manifest' => $this->manifests->build($service),
        ];

        if ($service->canonical_finalization === ChurchServiceCanonicalFinalization::Automatic) {
            if (
                $manual instanceof ChurchServiceSourceRecord
                || $service->needs_review
                || $this->hasUnresolvedProposals($service)
                || blank($service->canonical_hash)
                || $service->canonical_hash !== $projection->hash
                || $service->projection_policy_version !== $projection->policyFingerprint['version']
                || ! $this->projector->hasCompleteAudit($sourceRecords, $projection)
            ) {
                throw new RuntimeException("Service {$service->date->toDateString()} {$service->service->value} is not conflict-free and machine-final.");
            }

            return [
                ...$common,
                'finalization' => ChurchServiceCanonicalFinalization::Automatic->value,
                'pre_review_hash' => $service->canonical_hash,
                'manual_revision' => null,
                'review' => null,
            ];
        }

        if ($service->canonical_finalization === null) {
            throw new RuntimeException(
                "Service {$service->date->toDateString()} {$service->service->value} has no recorded finalisation. "
                .'Re-project it so its canonical state declares whether a machine or a person settled it.'
            );
        }

        if (
            ! $review instanceof ChurchServiceReviewSession
            || ! $manual instanceof ChurchServiceSourceRecord
            || $review->manual_source_record_id !== $manual->id
            || $service->reviewed_canonical_revision === null
            || blank($service->canonical_hash)
        ) {
            throw new RuntimeException("Service {$service->date->toDateString()} {$service->service->value} has no complete final review.");
        }

        return [
            ...$common,
            'finalization' => ChurchServiceCanonicalFinalization::Manual->value,
            'pre_review_hash' => $review->base_canonical_hash,
            'manual_revision' => $this->manualRevision($manual),
            'review' => $this->review($review),
        ];
    }

    private function hasUnresolvedProposals(ChurchService $service): bool
    {
        return $service->mergeProposals->contains(
            fn (ChurchServiceMergeProposal $proposal): bool => in_array($proposal->status->value, ['pending', 'stale'], true),
        );
    }

    /** @return array<string, mixed> */
    private function manualRevision(ChurchServiceSourceRecord $record): array
    {
        $assertions = $record->assertions->sortBy('assertion_key')->map(
            fn (ChurchServiceItemAssertion $assertion): array => [
                'assertion_key' => $assertion->assertion_key,
                'source_position' => $assertion->source_position,
                'evidence_kind' => $assertion->evidence_kind->value,
                'type' => $assertion->type,
                'section_type' => $assertion->section_type?->value,
                'title' => $assertion->title,
                'source_title' => $assertion->source_title,
                'normalized_title' => $assertion->normalized_title,
                'song_canonical_key' => $assertion->song_canonical_key,
                'scripture_reference' => $assertion->scripture_reference,
                'normalized_scripture_key' => $assertion->normalized_scripture_key,
                'start_seconds' => $assertion->start_seconds,
                'end_seconds' => $assertion->end_seconds,
                'confidence' => $assertion->confidence === null ? null : (float) $assertion->confidence,
                'metadata' => $this->portableAssertionMetadata($assertion->metadata),
            ],
        )->values()->all();

        return [
            'source_key' => $record->source_key,
            'revision_hash' => $record->revision_hash,
            'input_hash' => $record->input_hash,
            'batch_hash' => $record->batch_hash,
            'processing_fingerprint' => $record->processing_fingerprint,
            'service_content' => $record->service_content,
            'payload_complete' => $record->payload_complete,
            'captured_at' => $record->captured_at?->toISOString(),
            'assertions' => $assertions,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function portableAssertionMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        unset($metadata['livestream_service_section_id'], $metadata['oos_item_id']);

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function review(ChurchServiceReviewSession $review): array
    {
        $reviewerEmail = $review->reviewedBy?->email;

        if (! is_string($reviewerEmail)) {
            throw new RuntimeException('Completed convergence review has no reviewer email identity.');
        }

        return [
            'review_uuid' => $review->review_uuid,
            'reviewer_email_hash' => hash('sha256', mb_strtolower(trim($reviewerEmail))),
            'service_field_decisions' => $review->service_field_decisions,
            'decisions' => $review->decisions->sortBy('final_position')->map(fn (ChurchServiceReviewDecision $decision): array => [
                'selected_assertion_identity' => $decision->selectedAssertion === null
                    ? null
                    : $decision->selectedAssertion->sourceRecord->revision_hash.':'.$decision->selectedAssertion->assertion_key,
                'included' => $decision->included,
                'final_position' => $decision->final_position,
                'custom_value' => $decision->custom_value,
                'song_canonical_key' => $decision->song_canonical_key ?? $decision->song?->canonical_key,
                'scripture_reference' => $decision->scripture_reference,
                'occurrence_decision' => $decision->occurrence_decision,
                'rationale' => $decision->rationale,
            ])->values()->all(),
        ];
    }
}
