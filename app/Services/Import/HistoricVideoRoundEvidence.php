<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
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

        $manifest = json_decode(
            (string) file_get_contents($reports['manifest_expectation']['path']),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($manifest)
            || ($manifest['manifest_hash'] ?? null) !== $operation->manifest_hashes['historic_video']
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

        foreach (($cover['residues'] ?? null) ?? [] as $residue) {
            if (! is_array($residue) || ! is_string($residue['name'] ?? null)
                || ! is_int($residue['count'] ?? null) || $residue['count'] < 1
                || ! is_string($residue['explanation'] ?? null) || trim($residue['explanation']) === '') {
                throw new RuntimeException('Historic video round evidence contains an unexplained non-zero residue.');
            }
        }

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
}
