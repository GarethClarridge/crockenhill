<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;

class ChurchServiceConvergenceAuditor
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceEvidenceSet $evidenceSet,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     format: string,
     *     version: int,
     *     bundle_hash: string,
     *     passed: bool,
     *     totals: array{services: int, passed: int, failed: int},
     *     services: list<array<string, mixed>>
     * }
     */
    public function audit(array $bundle): array
    {
        $bundle = $this->bundles->validate($bundle);
        $results = [];

        foreach ($bundle['services'] as $payload) {
            $results[] = $this->auditService($payload);
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
     * @return array<string, mixed>
     */
    private function auditService(array $payload): array
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
                ChurchServiceProposalStatus::Stale,
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

        return [
            'identity' => $identity,
            'passed' => $differences === [],
            'canonical_hash' => $service->canonical_hash,
            'evidence_set_hash' => $this->evidenceSet->hash($service->sourceRecords),
            'differences' => $differences,
        ];
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
}
