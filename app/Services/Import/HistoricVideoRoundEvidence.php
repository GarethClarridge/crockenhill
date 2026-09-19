<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use JsonException;
use RuntimeException;

final class HistoricVideoRoundEvidence
{
    public const Format = 'crockenhill-historic-video-round-evidence';

    public const Version = 1;

    /** @var list<string> */
    public const ReportKeys = [
        'video_status',
        'manifest_expectation',
        'membership_census',
        'asset_audit',
        'scripture_settlement',
        'operation_ledger',
        'cost_duration',
    ];

    public function __construct(
        private readonly HistoricVideoRoundFailedJobs $failedJobs,
    ) {}

    /** @param array<string, mixed> $cover
     * @return array<string, mixed>
     */
    public function verify(array $cover, HistoricImportOperation $operation): array
    {
        if (($cover['format'] ?? null) !== self::Format || ($cover['version'] ?? null) !== self::Version) {
            throw new RuntimeException('Historic video round evidence format is unsupported.');
        }

        $bindings = [
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'batch_key' => $operation->batch_key,
            'target_fingerprint' => $operation->target_fingerprint,
            'runtime_fingerprint' => $operation->runtime_fingerprint,
            'manifest_hash' => $operation->manifest_hashes['historic_video'] ?? null,
            'plan_hash' => $operation->plan_hash,
        ];

        foreach ($bindings as $field => $expected) {
            if (! is_string($expected) || ($cover[$field] ?? null) !== $expected) {
                throw new RuntimeException("Historic video round evidence {$field} does not match the operation.");
            }
        }

        foreach (['round', 'commit', 'backup_receipt', 'reviewed_by', 'reviewed_at'] as $field) {
            if (! is_string($cover[$field] ?? null) || trim($cover[$field]) === '') {
                throw new RuntimeException("Historic video round evidence {$field} is missing.");
            }
        }

        $reports = $cover['reports'] ?? null;
        if (! is_array($reports) || array_keys($reports) !== self::ReportKeys) {
            throw new RuntimeException('Historic video round evidence does not name the exact required reports.');
        }

        foreach (self::ReportKeys as $key) {
            $report = $reports[$key];
            $path = is_array($report) ? ($report['path'] ?? null) : null;
            $digest = is_array($report) ? ($report['sha256'] ?? null) : null;

            if (! is_string($path) || ! is_file($path) || ! is_readable($path)
                || ! is_string($digest) || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
                || ! hash_equals($digest, (string) hash_file('sha256', $path))) {
                throw new RuntimeException("Historic video round evidence {$key} report digest does not match its reviewed file.");
            }
        }

        $items = $cover['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('Historic video round evidence has no exact item dispositions.');
        }

        $itemKeys = [];
        foreach ($items as $item) {
            $itemKey = is_array($item) ? ($item['item_key'] ?? null) : null;
            $disposition = is_array($item) ? ($item['disposition'] ?? null) : null;
            $reason = is_array($item) ? ($item['reason'] ?? null) : null;

            if (! is_string($itemKey) || trim($itemKey) === '' || isset($itemKeys[$itemKey])
                || ! in_array($disposition, ['completed', 'excluded', 'accepted_hold'], true)
                || ! is_string($reason) || trim($reason) === '') {
                throw new RuntimeException('Historic video round evidence item dispositions are not exact and explained.');
            }

            $itemKeys[$itemKey] = true;
        }

        $manifest = $this->readReport($reports, 'manifest_expectation');

        if (($manifest['manifest_hash'] ?? null) !== $operation->manifest_hashes['historic_video']
            || ($manifest['plan_hash'] ?? null) !== $operation->plan_hash) {
            throw new RuntimeException('Historic video round manifest expectation does not match the operation binding.');
        }

        $expectedItemKeys = [];

        foreach (array_merge(
            is_array($manifest['items'] ?? null) ? $manifest['items'] : [],
            is_array($manifest['exclusions'] ?? null) ? $manifest['exclusions'] : [],
        ) as $item) {
            $itemKey = is_array($item) ? ($item['item_key'] ?? null) : null;

            if (! is_string($itemKey) || $itemKey === '' || isset($expectedItemKeys[$itemKey])) {
                throw new RuntimeException('Historic video round manifest expectation membership is invalid.');
            }

            $expectedItemKeys[$itemKey] = true;
        }

        $actualKeys = array_keys($itemKeys);
        $expectedKeys = array_keys($expectedItemKeys);
        sort($actualKeys);
        sort($expectedKeys);

        if ($expectedItemKeys === [] || $actualKeys !== $expectedKeys) {
            throw new RuntimeException('Historic video round evidence item membership does not exactly match the manifest expectation.');
        }

        $this->assertSemanticEvidence($cover, $reports, $manifest, $operation);

        $signature = $cover['signature'] ?? null;
        $signingKey = (string) config('media-processing.historic_import.evidence_signing_key');
        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($cover, ['signature' => true])),
            $signingKey,
        );

        if ($signingKey === '' || ! is_array($signature)
            || ($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('Historic video round evidence signature is invalid.');
        }

        return $cover;
    }

    /**
     * @param  array<string, mixed>  $cover
     * @param  array<string, mixed>  $reports
     * @param  array<string, mixed>  $manifest
     */
    private function assertSemanticEvidence(
        array $cover,
        array $reports,
        array $manifest,
        HistoricImportOperation $operation,
    ): void
    {
        $membership = $this->readReport($reports, 'membership_census');
        $assets = $this->readReport($reports, 'asset_audit');
        $scripture = $this->readReport($reports, 'scripture_settlement');
        $ledger = $this->readReport($reports, 'operation_ledger');
        $performance = $this->readReport($reports, 'cost_duration');
        $status = $this->readReport($reports, 'video_status');

        $this->assertReportBinding($membership, $cover, 'membership census');
        $this->assertReportBinding($status, $cover, 'video status');

        if (($status['open_runs'] ?? null) !== 0 || ($status['historic_queue_depth'] ?? null) !== 0) {
            throw new RuntimeException('Historic video round evidence still has open runs or queued historic jobs.');
        }

        if (($assets['missing_asset_references'] ?? null) !== 0) {
            throw new RuntimeException('Historic video round evidence has missing asset references.');
        }

        if (($scripture['public_exposure'] ?? null) !== 0) {
            throw new RuntimeException('Historic video round evidence has public Scripture-hold exposure.');
        }

        if (($ledger['live_jobs'] ?? null) !== 0
            || ($ledger['external_notifications_sent'] ?? null) !== 0
            || data_get($ledger, 'nested_jobs.unsettled') !== 0) {
            throw new RuntimeException('Historic video round evidence has live jobs, external notifications or unsettled nested jobs.');
        }

        $membershipItems = $this->itemsByKey($membership['items'] ?? null, 'membership census');
        $coverItems = $this->itemsByKey($cover['items'] ?? null, 'cover');
        $manifestExclusions = $this->itemsByKey($manifest['exclusions'] ?? [], 'manifest exclusions');
        $counts = ['completed' => 0, 'excluded' => 0, 'accepted_hold' => 0];

        foreach ($coverItems as $itemKey => $item) {
            $membershipItem = $membershipItems[$itemKey] ?? null;
            $disposition = $item['disposition'] ?? null;

            if (! is_array($membershipItem)) {
                throw new RuntimeException("Historic video round evidence item {$itemKey} is absent from the membership census.");
            }

            if ($disposition === 'completed' && ($membershipItem['disposition'] ?? null) !== 'complete') {
                throw new RuntimeException("Historic video round evidence item {$itemKey} is not completed in the membership census.");
            }

            if ($disposition === 'excluded' && ($membershipItem['disposition'] ?? null) !== 'excluded') {
                throw new RuntimeException("Historic video round evidence item {$itemKey} is not excluded in the membership census.");
            }

            if ($disposition === 'excluded' && ($item['reason'] ?? null) !== ($membershipItem['reason'] ?? null)) {
                throw new RuntimeException("Historic video round exclusion {$itemKey} disagrees with the membership census.");
            }

            if ($disposition === 'accepted_hold') {
                $reference = $item['evidence_reference'] ?? null;
                if (($membershipItem['disposition'] ?? null) !== 'unresolved'
                    || ! is_array($reference)
                    || ! is_string($reference['path'] ?? null)
                    || ! is_string($reference['sha256'] ?? null)
                    || ! is_file($reference['path'])
                    || ! is_readable($reference['path'])
                    || ! hash_equals($reference['sha256'], (string) hash_file('sha256', $reference['path']))
                    || ! $this->holdEvidenceNamesItem($reference['path'], $itemKey, $cover)) {
                    throw new RuntimeException("Historic video round accepted hold {$itemKey} lacks its bound evidence reference.");
                }
            }

            if (isset($manifestExclusions[$itemKey])) {
                $manifestReason = $manifestExclusions[$itemKey]['exclusion_reason'] ?? null;
                if ($disposition !== 'excluded' || ! is_string($manifestReason) || ($item['reason'] ?? null) !== $manifestReason) {
                    throw new RuntimeException("Historic video round exclusion {$itemKey} disagrees with the bound manifest.");
                }
            }

            $counts[$disposition]++;
        }

        $expectedCounts = [
            'completed' => data_get($membership, 'counts.complete'),
            'excluded' => data_get($membership, 'counts.excluded'),
            'accepted_hold' => data_get($membership, 'counts.unresolved'),
        ];

        if ($counts !== $expectedCounts) {
            throw new RuntimeException('Historic video round cover disposition counts disagree with the membership census.');
        }

        $failedJobs = $ledger['failed_nested_jobs'] ?? null;
        if (! is_array($failedJobs) || ! array_is_list($failedJobs)
            || count($failedJobs) !== data_get($ledger, 'nested_jobs.failed_settled')) {
            throw new RuntimeException('Historic video round failed nested-job residue is incomplete.');
        }

        foreach ($failedJobs as $failedJob) {
            if (! is_array($failedJob) || ! is_string($failedJob['explanation'] ?? null) || trim($failedJob['explanation']) === '') {
                throw new RuntimeException('Historic video round failed nested job is unexplained.');
            }
        }

        $this->failedJobs->assertSuperseded($operation, $failedJobs);

        $expectedResidues = array_filter([
            'accepted_holds' => ['count' => $counts['accepted_hold'], 'source_report' => 'membership_census'],
            'failed_nested_jobs' => ['count' => data_get($ledger, 'nested_jobs.failed_settled'), 'source_report' => 'operation_ledger'],
            'missing_timing_runs' => ['count' => data_get($performance, 'all_runs.runs_missing_timing_count'), 'source_report' => 'cost_duration'],
            'retried_runs' => ['count' => data_get($performance, 'all_runs.retried_run_count'), 'source_report' => 'cost_duration'],
        ], static fn (array $residue): bool => is_int($residue['count']) && $residue['count'] > 0);
        $actualResidues = [];

        foreach (($cover['residues'] ?? null) ?? [] as $residue) {
            if (! is_array($residue) || ! is_string($residue['name'] ?? null)
                || ! is_int($residue['count'] ?? null) || $residue['count'] < 1
                || ! is_string($residue['source_report'] ?? null)
                || ! is_string($residue['explanation'] ?? null) || trim($residue['explanation']) === '') {
                throw new RuntimeException('Historic video round evidence contains an unexplained non-zero residue.');
            }

            $actualResidues[$residue['name']] = [
                'count' => $residue['count'],
                'source_report' => $residue['source_report'],
            ];
        }

        if ($actualResidues !== $expectedResidues) {
            throw new RuntimeException('Historic video round residues disagree with their source reports.');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $cover
     */
    private function assertReportBinding(array $report, array $cover, string $label): void
    {
        foreach (['operation_id', 'manifest_hash', 'plan_hash'] as $field) {
            if (($report[$field] ?? null) !== ($cover[$field] ?? null)) {
                throw new RuntimeException("Historic video round {$label} {$field} binding is invalid.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $reports
     * @return array<string, mixed>
     */
    private function readReport(array $reports, string $key): array
    {
        try {
            $report = json_decode((string) file_get_contents($reports[$key]['path']), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Historic video round {$key} report is invalid JSON.", previous: $exception);
        }

        if (! is_array($report)) {
            throw new RuntimeException("Historic video round {$key} report must be an object.");
        }

        return $report;
    }

    /** @return array<string, array<string, mixed>> */
    private function itemsByKey(mixed $items, string $label): array
    {
        if (! is_array($items)) {
            throw new RuntimeException("Historic video round {$label} items are missing.");
        }

        $keyed = [];
        foreach ($items as $item) {
            $itemKey = is_array($item) ? ($item['item_key'] ?? null) : null;
            if (! is_string($itemKey) || $itemKey === '' || isset($keyed[$itemKey])) {
                throw new RuntimeException("Historic video round {$label} item membership is invalid.");
            }
            $keyed[$itemKey] = $item;
        }

        return $keyed;
    }

    /** @param array<string, mixed> $cover */
    private function holdEvidenceNamesItem(string $path, string $itemKey, array $cover): bool
    {
        try {
            $evidence = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($evidence)
            || ($evidence['operation_id'] ?? null) !== ($cover['operation_id'] ?? null)
            || ($evidence['manifest_hash'] ?? null) !== ($cover['manifest_hash'] ?? null)
            || ($evidence['plan_hash'] ?? null) !== ($cover['plan_hash'] ?? null)) {
            return false;
        }

        foreach (($evidence['items'] ?? null) ?? [] as $item) {
            if (is_array($item) && ($item['item_key'] ?? null) === $itemKey
                && ($item['disposition'] ?? null) === 'accepted_hold'
                && is_string($item['reason'] ?? null) && trim($item['reason']) !== '') {
                return true;
            }
        }

        return false;
    }
}
