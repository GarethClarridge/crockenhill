<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultFieldClassifier;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Support\CanonicalJson;
use RuntimeException;
use Throwable;

class ChurchServiceConvergenceAuditor
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceEvidenceSet $evidenceSet,
        /**
         * Required, not optional. As nullable defaults the container never
         * populated them, so every `--media-bundle` audit failed with "Bundle A
         * auditing is not configured" — the one code path they existed for.
         */
        private readonly HistoricProcessingResultBundle $mediaBundles,
        private readonly HistoricProcessingResultBundleImporter $mediaImporter,
        private readonly HistoricProcessingResultInventory $mediaInventory,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>|null  $mediaBundle
     * @return array{
     *     format: string,
     *     version: int,
     *     bundle_hash: string,
     *     passed: bool,
     *     totals: array{services: int, passed: int, failed: int},
     *     services: list<array<string, mixed>>
     * }
     */
    public function audit(array $bundle, ?array $mediaBundle = null): array
    {
        $bundle = $this->bundles->validate($bundle);
        $mediaByIdentity = $this->mediaServices($mediaBundle, $bundle);
        $results = [];

        foreach ($bundle['services'] as $index => $payload) {
            $identity = "{$payload['date']}|{$payload['service']}";
            $results[] = $this->auditService($payload, $mediaByIdentity[$identity] ?? null, $index);
        }

        $failed = collect($results)->where('passed', false)->count();

        return [
            'format' => 'crockenhill-service-convergence-audit',
            'version' => 1,
            'bundle_hash' => $bundle['bundle_hash'],
            'passed' => $failed === 0,
            'totals' => [
                'services' => count($results),
                'passed' => count($results) - $failed,
                'failed' => $failed,
            ],
            'services' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{bundle: array<string, mixed>, index: int}|null  $mediaService
     * @return array<string, mixed>
     */
    private function auditService(array $payload, ?array $mediaService = null, int $serviceIndex = 0): array
    {
        $identity = "{$payload['date']}|{$payload['service']}";
        $matches = ChurchService::query()
            ->with([
                'items.song',
                'sourceRecords',
                'mergeProposals',
                'reviewSessions',
            ])
            ->whereDate('date', $payload['date'])
            ->where('service', $payload['service'])
            ->get();

        if ($matches->count() !== 1) {
            return [
                'identity' => $identity,
                'passed' => false,
                'differences' => [[
                    'path' => 'service',
                    'expected' => 'exactly_one',
                    'actual' => $matches->count(),
                ]],
            ];
        }

        $service = $matches->firstOrFail();
        $actualManifest = $this->manifests->build($service);
        $differences = $this->differences(
            $payload['canonical_manifest'],
            $actualManifest,
            'canonical_manifest',
        );

        $this->compare(
            $differences,
            'resulting_canonical_hash',
            $payload['resulting_canonical_hash'],
            $service->canonical_hash,
        );
        $this->compare(
            $differences,
            'evidence_set_hash',
            $payload['evidence_set_hash'],
            $this->evidenceSet->hash($service->sourceRecords),
        );

        $this->compare(
            $differences,
            'finalization',
            $payload['finalization'],
            $service->canonical_finalization?->value,
        );
        $this->compare(
            $differences,
            'projection_policy',
            $payload['projection_policy'],
            $this->projector->policyFingerprint(),
        );
        $this->compare(
            $differences,
            'projection_policy_version',
            $payload['projection_policy']['version'],
            $service->projection_policy_version,
        );

        $automatic = $payload['finalization'] === ChurchServiceCanonicalFinalization::Automatic->value;

        if ($automatic) {
            // A machine-final service must carry no human decision at all, so the
            // live assertion is that no review ever completed against it — not
            // merely that the bundle omitted one.
            $this->compare(
                $differences,
                'review.completed',
                false,
                $service->reviewSessions->contains(
                    fn (ChurchServiceReviewSession $session): bool => $session->completed_at !== null,
                ),
            );
            $this->compare($differences, 'reviewed_canonical_revision', null, $service->reviewed_canonical_revision);
        } else {
            $review = $service->reviewSessions->firstWhere('review_uuid', $payload['review']['review_uuid']);

            $this->compare(
                $differences,
                'review.review_uuid',
                $payload['review']['review_uuid'],
                $review?->review_uuid,
            );
            $this->compare(
                $differences,
                'review.resulting_canonical_hash',
                $payload['resulting_canonical_hash'],
                $review?->resulting_canonical_hash,
            );
            $this->compare(
                $differences,
                'review.completed',
                true,
                $review instanceof ChurchServiceReviewSession && $review->completed_at !== null,
            );
        }

        $pendingProposals = $service->mergeProposals
            ->whereIn('status', [
                ChurchServiceProposalStatus::Pending,
            ])
            ->map(fn (ChurchServiceMergeProposal $proposal): array => [
                'proposed_hash' => $proposal->proposed_hash,
                'status' => $proposal->status->value,
            ])
            ->values()
            ->all();

        $this->compare($differences, 'pending_proposals', [], $pendingProposals);
        $this->compare($differences, 'needs_review', false, $service->needs_review);
        if (! $automatic) {
            $this->compare(
                $differences,
                'reviewed_canonical_revision',
                $service->canonical_revision,
                $service->reviewed_canonical_revision,
            );
        }

        if ($mediaService !== null) {
            $this->auditMediaGraph($differences, $mediaService, $serviceIndex);
        }

        return [
            'identity' => $identity,
            'passed' => $differences === [],
            'canonical_hash' => $service->canonical_hash,
            'evidence_set_hash' => $this->evidenceSet->hash($service->sourceRecords),
            'differences' => $differences,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array<string, array{bundle: array<string, mixed>, index: int}>
     */
    private function mediaServices(?array $mediaBundle, array $convergenceBundle): array
    {
        if ($mediaBundle === null) {
            return [];
        }

        $mediaBundle = $this->mediaBundles->validate($mediaBundle);

        if (
            $mediaBundle['batch_hash'] !== $convergenceBundle['batch_hash']
            || $mediaBundle['bundle_hash'] !== $convergenceBundle['media_bundle_hash']
            || CanonicalJson::hash($mediaBundle['processing_fingerprint'])
                !== CanonicalJson::hash($convergenceBundle['processing_fingerprint'])
        ) {
            throw new RuntimeException('Bundle A and Bundle B do not share the same batch and processing fingerprint.');
        }

        $services = [];

        foreach ($mediaBundle['services'] as $index => $payload) {
            $identity = "{$payload['date']}|{$payload['service']}";

            if (isset($services[$identity])) {
                throw new RuntimeException("Duplicate Bundle A service identity: {$identity}.");
            }

            $services[$identity] = ['bundle' => $mediaBundle, 'index' => $index];
        }

        $convergenceIdentities = [];

        foreach ($convergenceBundle['services'] as $payload) {
            $identity = "{$payload['date']}|{$payload['service']}";
            $convergenceIdentities[$identity] = true;
        }

        if (array_diff_key($services, $convergenceIdentities) !== []
            || array_diff_key($convergenceIdentities, $services) !== []) {
            throw new RuntimeException('Bundle A and Bundle B do not contain the same service identities.');
        }

        return $services;
    }

    /**
     * A drifted media graph must be reported field by field, not as a single
     * "the classification is not already_present". The classification is the
     * summary an operator cannot act on; the item-level diff is the closeout
     * evidence, so it is produced whether or not the graph still classifies as
     * an exact no-op.
     *
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  &$differences
     * @param  array{bundle: array<string, mixed>, index: int}  $mediaService
     */
    private function auditMediaGraph(array &$differences, array $mediaService, int $serviceIndex): void
    {
        $mediaBundle = $mediaService['bundle'];
        $mediaIndex = $mediaService['index'];
        $expected = $mediaBundle['services'][$mediaIndex]['media_graph'];

        try {
            $plan = $this->mediaImporter->prepareServiceForAudit($mediaBundle, $mediaIndex);
            $run = $plan->existingProcessingLogId === null
                ? null
                : MediaProcessingLog::query()->find($plan->existingProcessingLogId);

            if (! $run instanceof MediaProcessingLog) {
                $differences[] = [
                    'path' => 'media_graph.processing_key',
                    'expected' => $expected['processing_key'],
                    'actual' => null,
                ];

                return;
            }

            $differences = [
                ...$differences,
                ...$this->differences(
                    $this->portableAuditValue($expected),
                    $this->portableAuditValue($this->mediaInventory->build($run)),
                    'media_graph',
                ),
            ];

            if ($plan->classification !== HistoricImportClassification::AlreadyPresent->value) {
                $differences[] = [
                    'path' => 'media_graph.classification',
                    'expected' => HistoricImportClassification::AlreadyPresent->value,
                    'actual' => $plan->classification,
                ];
            }
        } catch (Throwable $exception) {
            $differences[] = [
                'path' => "media_graph.service_{$serviceIndex}",
                'expected' => 'exact',
                'actual' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $differences
     */
    private function compare(
        array &$differences,
        string $path,
        mixed $expected,
        mixed $actual,
    ): void {
        if ($expected === $actual) {
            return;
        }

        $differences[] = compact('path', 'expected', 'actual');
    }

    /**
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    private function differences(mixed $expected, mixed $actual, string $path): array
    {
        if (! is_array($expected) || ! is_array($actual)) {
            return $expected === $actual
                ? []
                : [compact('path', 'expected', 'actual')];
        }

        $differences = [];

        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            $nestedPath = "{$path}.{$key}";

            if (! array_key_exists($key, $expected)) {
                $differences[] = [
                    'path' => $nestedPath,
                    'expected' => null,
                    'actual' => $actual[$key],
                ];

                continue;
            }

            if (! array_key_exists($key, $actual)) {
                $differences[] = [
                    'path' => $nestedPath,
                    'expected' => $expected[$key],
                    'actual' => null,
                ];

                continue;
            }

            array_push(
                $differences,
                ...$this->differences($expected[$key], $actual[$key], $nestedPath),
            );
        }

        return $differences;
    }

    private function portableAuditValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $portable = [];

        foreach ($value as $key => $nested) {
            $portable[$key] = is_string($key) && HistoricProcessingResultFieldClassifier::isPathKey($key)
                ? null
                : $this->portableAuditValue($nested);
        }

        return $portable;
    }
}
