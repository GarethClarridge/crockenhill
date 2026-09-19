<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use JsonException;
use RuntimeException;

final class HistoricVideoRoundCoverBuilder
{
    /**
     * @param  array<string, string>  $reportPaths
     * @param  list<string>  $acceptedHoldsPaths
     * @return array<string, mixed>
     */
    public function build(
        HistoricImportOperation $operation,
        array $reportPaths,
        array $acceptedHoldsPaths,
        string $round,
        string $commit,
        ?string $backupReceipt,
        string $reviewedBy,
        string $reviewedAt,
        ?string $signingKey = null,
        string $keyId = 'historic-import-evidence',
    ): array {
        if (array_keys($reportPaths) !== HistoricVideoRoundEvidence::ReportKeys) {
            throw new RuntimeException('Historic video round cover requires the exact ordered report set.');
        }

        $reports = [];
        foreach ($reportPaths as $key => $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Historic video round {$key} report is missing.");
            }

            $reports[$key] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        }

        $membership = $this->read($reportPaths['membership_census'], 'membership census');
        $ledger = $this->read($reportPaths['operation_ledger'], 'operation ledger');
        $performance = $this->read($reportPaths['cost_duration'], 'cost/duration');
        $holdItems = [];
        foreach ($acceptedHoldsPaths as $acceptedHoldsPath) {
            $holds = $this->read($acceptedHoldsPath, 'accepted holds');
            foreach (($holds['items'] ?? null) ?? [] as $hold) {
                if (is_array($hold) && is_string($hold['item_key'] ?? null)) {
                    $holdItems[$hold['item_key']] = [
                        ...$hold,
                        'evidence_reference' => [
                            'path' => $acceptedHoldsPath,
                            'sha256' => hash_file('sha256', $acceptedHoldsPath),
                        ],
                    ];
                }
            }
        }
        $items = [];

        foreach (($membership['items'] ?? null) ?? [] as $item) {
            if (! is_array($item) || ! is_string($item['item_key'] ?? null)) {
                throw new RuntimeException('Historic video round membership census contains an invalid item.');
            }

            $disposition = match ($item['disposition'] ?? null) {
                'complete' => 'completed',
                'excluded' => 'excluded',
                'unresolved' => 'accepted_hold',
                default => throw new RuntimeException("Historic video round membership item {$item['item_key']} has no closeout disposition."),
            };
            $hold = $holdItems[$item['item_key']] ?? null;

            if ($disposition === 'accepted_hold'
                && (! is_array($hold)
                    || ! is_string($hold['reason'] ?? null)
                    || trim($hold['reason']) === ''
                    || ! is_array($hold['evidence_reference'] ?? null))) {
                throw new RuntimeException("Historic video round accepted hold {$item['item_key']} has no evidence entry.");
            }

            $coverItem = [
                'item_key' => $item['item_key'],
                'disposition' => $disposition,
                'reason' => $disposition === 'accepted_hold' ? ($hold['reason'] ?? null) : ($item['reason'] ?? null),
            ];

            if ($disposition === 'accepted_hold') {
                $coverItem['evidence_reference'] = $hold['evidence_reference'] ?? null;
            }

            $items[] = $coverItem;
        }

        $residues = array_values(array_filter([
            $this->residue('accepted_holds', data_get($membership, 'counts.unresolved'), 'membership_census', 'Each unresolved census identity is bound to reviewed accepted-hold evidence.'),
            $this->residue('failed_nested_jobs', data_get($ledger, 'nested_jobs.failed_settled'), 'operation_ledger', 'Every failed nested job is settled and individually explained in the operation ledger.'),
            $this->residue('missing_timing_runs', data_get($performance, 'all_runs.runs_missing_timing_count'), 'cost_duration', 'Runs without timing evidence are enumerated in the performance report.'),
            $this->residue('retried_runs', data_get($performance, 'all_runs.retried_run_count'), 'cost_duration', 'Retried runs are enumerated in the performance report.'),
        ]));
        $blockers = [];

        if (! is_string($backupReceipt) || trim($backupReceipt) === '') {
            $blockers[] = 'backup_receipt_missing';
        }

        if (! is_string($signingKey) || $signingKey === '') {
            $blockers[] = 'signing_key_missing';
        }

        $cover = [
            'format' => HistoricVideoRoundEvidence::Format,
            'version' => HistoricVideoRoundEvidence::Version,
            'round' => $round,
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'batch_key' => $operation->batch_key,
            'commit' => $commit,
            'target_fingerprint' => $operation->target_fingerprint,
            'runtime_fingerprint' => $operation->runtime_fingerprint,
            'manifest_hash' => $operation->manifest_hashes['historic_video'] ?? null,
            'plan_hash' => $operation->plan_hash,
            'backup_receipt' => $backupReceipt,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt,
            'reports' => $reports,
            'items' => $items,
            'residues' => $residues,
            'signing' => [
                'state' => $blockers === [] ? 'signed' : 'not_signable',
                'blockers' => $blockers,
            ],
        ];

        if ($blockers === []) {
            if (! is_string($signingKey)) {
                throw new RuntimeException('Historic video round signing key state is inconsistent.');
            }

            $cover['signature'] = [
                'algorithm' => 'hmac-sha256',
                'key_id' => $keyId,
                'digest' => hash_hmac('sha256', CanonicalJson::encode($cover), $signingKey),
            ];
        }

        return $cover;
    }

    /** @return array<string, mixed>|null */
    private function residue(string $name, mixed $count, string $sourceReport, string $explanation): ?array
    {
        if (! is_int($count) || $count < 1) {
            return null;
        }

        return [
            'name' => $name,
            'count' => $count,
            'source_report' => $sourceReport,
            'explanation' => $explanation,
        ];
    }

    /** @return array<string, mixed> */
    private function read(string $path, string $label): array
    {
        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Historic video round {$label} is invalid JSON.", previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException("Historic video round {$label} must be an object.");
        }

        return $payload;
    }
}
