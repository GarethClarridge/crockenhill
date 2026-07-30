<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;

class ChurchServiceConvergenceAuditor
{
    public function __construct(
        private readonly ChurchServiceConvergenceBundle $bundles,
        private readonly ChurchServiceCanonicalManifest $manifests,
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
            $this->evidenceSetHash($service),
        );

        $review = $service->reviewSessions
            ->firstWhere('review_uuid', $payload['review']['review_uuid']);

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
        $this->compare(
            $differences,
            'reviewed_canonical_revision',
            $service->canonical_revision,
            $service->reviewed_canonical_revision,
        );

        return [
            'identity' => $identity,
            'passed' => $differences === [],
            'canonical_hash' => $service->canonical_hash,
            'evidence_set_hash' => $this->evidenceSetHash($service),
            'differences' => $differences,
        ];
    }

    private function evidenceSetHash(ChurchService $service): string
    {
        return CanonicalJson::hash($service->sourceRecords
            ->where('source', '!=', ChurchServiceSource::Manual)
            ->sortBy('revision_hash')
            ->map(fn (ChurchServiceSourceRecord $record): array => [
                'source' => $record->source->value,
                'source_key' => $record->source_key,
                'revision_hash' => $record->revision_hash,
                'processing_fingerprint' => $record->processing_fingerprint,
            ])
            ->values()
            ->all());
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
