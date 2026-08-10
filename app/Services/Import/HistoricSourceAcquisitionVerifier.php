<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\CanonicalJson;
use FilesystemIterator;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class HistoricSourceAcquisitionVerifier
{
    /**
     * @param  array<string, mixed>  $custody
     * @return array<string, mixed>
     */
    public function verify(array $custody, string $evidenceRoot, string $workingRoot, string $signingKey): array
    {
        $this->validateCustody($custody, $signingKey);
        $dispositions = $custody['dispositions'] ?? null;
        $copies = $custody['copies'] ?? null;

        if (! is_array($dispositions) || ! is_array($copies)
            || ! is_array($copies['evidence'] ?? null)
            || ! is_array($copies['working'] ?? null)) {
            throw new RuntimeException('Historic source custody copy/disposition bindings are invalid.');
        }

        if (realpath($evidenceRoot) === realpath($workingRoot)) {
            throw new RuntimeException('Evidence and working copies must be independent roots.');
        }

        $evidence = $this->inventory($evidenceRoot, $dispositions);
        $working = $this->inventory($workingRoot, $dispositions);

        foreach (['evidence' => $evidence, 'working' => $working] as $role => $inventory) {
            $expected = $copies[$role]['inventory_hash'] ?? null;

            if (! is_string($expected) || ! hash_equals($expected, $inventory['inventory_hash'])) {
                throw new RuntimeException("Observed {$role} copy inventory does not match its acquired checksum.");
            }
        }

        if (! hash_equals($evidence['content_hash'], $working['content_hash'])) {
            throw new RuntimeException('Evidence and working copies do not contain the same complete path/byte set.');
        }

        $this->validateCapacityPlan($custody['capacity_plan'], $working);

        $report = [
            'format' => 'crockenhill-historic-source-acquisition',
            'version' => 1,
            'batch_key' => $custody['batch_key'],
            'custody_hash' => CanonicalJson::hash(array_diff_key($custody, ['signature' => true])),
            'physical_source' => $custody['physical_source'],
            'capacity_plan' => $custody['capacity_plan'],
            'copies' => [
                'evidence' => $copies['evidence'] + $evidence,
                'working' => $copies['working'] + $working,
            ],
            'malware_scan' => $custody['malware_scan'],
            'retention' => $custody['retention'],
            'verified_at' => now()->utc()->toIso8601String(),
        ];
        $report['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => $custody['signature']['key_id'],
            'digest' => hash_hmac('sha256', CanonicalJson::encode($report), $signingKey),
        ];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $dispositions
     * @return array{inventory_hash:string,content_hash:string,path_count:int,entries:list<array<string, mixed>>}
     */
    public function inventory(string $root, array $dispositions): array
    {
        $resolvedRoot = realpath($root);

        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new RuntimeException("Historic source copy does not exist: {$root}");
        }

        $entries = [];
        $collisions = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $path = $item->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($resolvedRoot) + 1));
            $authority = $dispositions[$relative] ?? null;

            if (! is_array($authority)
                || ! is_string($authority['disposition'] ?? null)
                || trim($authority['disposition']) === ''
                || ! is_array($authority['xattrs'] ?? null)) {
                throw new RuntimeException("Historic source path has no exact disposition/xattr record: {$relative}");
            }

            /** @var array{disposition:string,xattrs:array<string, string>} $authority */
            $collisionKey = mb_strtolower(
                class_exists(Normalizer::class)
                    ? (Normalizer::normalize($relative, Normalizer::FORM_C) ?: $relative)
                    : $relative,
            );

            if (isset($collisions[$collisionKey]) && $collisions[$collisionKey] !== $relative) {
                throw new RuntimeException("Historic source contains a case or Unicode-normalisation collision: {$relative}");
            }

            $collisions[$collisionKey] = $relative;
            $entries[] = $this->entry($item, $relative, $authority);
        }

        ksort($dispositions, SORT_STRING);
        $observed = array_column($entries, 'relative_path');
        sort($observed, SORT_STRING);

        if ($observed !== array_keys($dispositions)) {
            throw new RuntimeException('Historic source disposition artifact contains unobserved paths.');
        }

        usort($entries, static fn (array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);
        $readErrors = array_filter($entries, static fn (array $entry): bool => $entry['read_error'] !== null);

        if ($readErrors !== []) {
            throw new RuntimeException('Historic source inventory encountered one or more read errors.');
        }

        return [
            'inventory_hash' => CanonicalJson::hash($entries),
            'content_hash' => CanonicalJson::hash(array_map(static fn (array $entry): array => [
                'relative_path' => $entry['relative_path'],
                'type' => $entry['type'],
                'link_target' => $entry['link_target'],
                'byte_size' => $entry['byte_size'],
                'sha256' => $entry['sha256'],
            ], $entries)),
            'path_count' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array{disposition:string,xattrs:array<string, string>}  $authority
     * @return array<string, mixed>
     */
    private function entry(SplFileInfo $item, string $relative, array $authority): array
    {
        $type = $item->isLink() ? 'symlink' : ($item->isDir() ? 'directory' : ($item->isFile() ? 'file' : 'other'));
        $stat = lstat($item->getPathname());
        $size = null;
        $hash = null;
        $readError = null;
        $linkTarget = null;

        if ($type === 'symlink') {
            $observedTarget = readlink($item->getPathname());

            if (is_string($observedTarget)) {
                $linkTarget = $observedTarget;
            } else {
                $readError = 'readlink_failed';
            }
        }

        if ($type === 'file') {
            try {
                $size = $item->getSize();
                $hash = hash_file('sha256', $item->getPathname());

                if (! is_string($hash)) {
                    $readError = 'hash_failed';
                }
            } catch (Throwable) {
                $readError = 'read_failed';
            }
        }

        return [
            'relative_path' => $relative,
            'raw_path_base64' => base64_encode($relative),
            'type' => $type,
            'link_target' => $linkTarget,
            'byte_size' => $size,
            'sha256' => $hash,
            'modified_at' => $item->getMTime(),
            'mode' => substr(sprintf('%o', $item->getPerms()), -4),
            'device' => is_array($stat) ? $stat['dev'] : null,
            'inode' => is_array($stat) ? $stat['ino'] : null,
            'hard_link_count' => is_array($stat) ? $stat['nlink'] : null,
            'xattrs' => $authority['xattrs'],
            'disposition' => trim($authority['disposition']),
            'read_error' => $readError,
        ];
    }

    /** @param array<string, mixed> $custody */
    private function validateCustody(array $custody, string $signingKey): void
    {
        if ($signingKey === '') {
            throw new RuntimeException('Historic source evidence signing key is not configured.');
        }

        $expectedKeys = [
            'format', 'version', 'batch_key', 'physical_source', 'copies',
            'capacity_plan', 'malware_scan', 'retention', 'dispositions', 'signature',
        ];
        $actualKeys = array_keys($custody);
        sort($expectedKeys);
        sort($actualKeys);

        if ($expectedKeys !== $actualKeys
            || $custody['format'] !== 'crockenhill-historic-source-custody'
            || $custody['version'] !== 1
            || ! is_string($custody['batch_key'])
            || trim($custody['batch_key']) === '') {
            throw new RuntimeException('Historic source custody artifact has an unsupported or incomplete schema.');
        }

        $source = $custody['physical_source'];

        if (! is_array($source)
            || ! is_string($source['device_identity'] ?? null)
            || ! is_string($source['volume_identity'] ?? null)
            || ! is_string($source['filesystem'] ?? null)
            || ! is_string($source['health_report_sha256'] ?? null)
            || ($source['read_error_count'] ?? null) !== 0
            || ! is_array($source['mount'] ?? null)
            || ($source['mount']['read_only'] ?? null) !== true
            || ($source['mount']['write_probe_failed'] ?? null) !== true
            || ($source['mount']['noexec'] ?? null) !== true
            || ($source['mount']['nosuid'] ?? null) !== true
            || ($source['mount']['nodev'] ?? null) !== true) {
            throw new RuntimeException('Physical source identity, health and proven read-only mount controls are incomplete.');
        }

        $this->exactKeys($source, [
            'device_identity', 'volume_identity', 'filesystem', 'health_report_sha256',
            'read_error_count', 'mount',
        ], 'physical source');
        $mount = $source['mount'];

        $this->exactKeys($mount, [
            'read_only', 'write_probe_failed', 'noexec', 'nosuid', 'nodev',
        ], 'physical source mount');

        $this->hash($source['health_report_sha256'], 'physical source health report');

        $copies = $custody['copies'];

        if (! is_array($copies)
            || ! is_array($copies['evidence'] ?? null)
            || ! is_array($copies['working'] ?? null)
            || ! is_string($copies['evidence']['storage_identity'] ?? null)
            || ! is_string($copies['working']['storage_identity'] ?? null)
            || $copies['evidence']['storage_identity'] === $copies['working']['storage_identity']
            || ($copies['evidence']['protected_read_only'] ?? null) !== true
            || ($copies['working']['protected_read_only'] ?? null) !== true) {
            throw new RuntimeException('Two independently identified, protected source copies are required.');
        }
        $this->exactKeys($copies, ['evidence', 'working'], 'source copies');

        foreach ($copies as $role => $copy) {
            if (! is_array($copy)) {
                throw new RuntimeException("{$role} source copy evidence is invalid.");
            }

            $this->exactKeys($copy, [
                'storage_identity', 'protected_read_only', 'inventory_hash',
            ], "{$role} source copy");
            $this->hash($copy['inventory_hash'], "{$role} source copy inventory");
        }

        $malwareScan = $custody['malware_scan'];

        if (! is_array($malwareScan)
            || ($malwareScan['status'] ?? null) !== 'clean'
            || ! is_string($malwareScan['report_sha256'] ?? null)) {
            throw new RuntimeException('A clean, checksummed malware scan is required before processing.');
        }

        $this->exactKeys($malwareScan, [
            'status', 'engine', 'definitions_at', 'scanned_at', 'report_sha256',
        ], 'malware scan');

        $this->hash($malwareScan['report_sha256'], 'malware scan report');

        $retention = $custody['retention'];

        if (! is_array($retention)
            || ! is_string($retention['owner'] ?? null)
            || trim($retention['owner']) === ''
            || ! is_string($retention['retain_until'] ?? null)
            || ($retention['destruction_requires_acceptance'] ?? null) !== true) {
            throw new RuntimeException('Historic source custody retention owner/window is incomplete.');
        }

        $this->exactKeys($retention, [
            'owner', 'retain_until', 'destruction_requires_acceptance',
        ], 'source retention');

        if (! is_array($custody['dispositions']) || $custody['dispositions'] === []) {
            throw new RuntimeException('Historic source custody has no whole-tree dispositions.');
        }

        if (! is_array($custody['signature'])) {
            throw new RuntimeException('Historic source custody signature is invalid.');
        }

        $this->exactKeys($custody['signature'], ['algorithm', 'key_id', 'digest'], 'custody signature');

        foreach ($custody['dispositions'] as $path => $disposition) {
            if (! is_string($path) || $path === '' || ! is_array($disposition)) {
                throw new RuntimeException('Historic source custody contains an invalid path disposition.');
            }

            $this->exactKeys($disposition, ['disposition', 'xattrs'], "source disposition {$path}");

            if (! is_array($disposition['xattrs'])) {
                throw new RuntimeException("Historic source custody xattrs are invalid for {$path}.");
            }

            foreach ($disposition['xattrs'] as $name => $value) {
                if (! is_string($name) || ! is_string($value)) {
                    throw new RuntimeException("Historic source custody xattrs are invalid for {$path}.");
                }
            }
        }

        $signature = $custody['signature'];
        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($custody, ['signature' => true])),
            $signingKey,
        );

        if (($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('Historic source custody signature is invalid.');
        }
    }

    private function hash(mixed $value, string $label): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("Historic source {$label} must be a SHA-256 digest.");
        }
    }

    /** @param array<string, mixed> $inventory */
    private function validateCapacityPlan(mixed $capacity, array $inventory): void
    {
        if (! is_array($capacity)) {
            throw new RuntimeException('Historic source custody has no accepted capacity plan.');
        }

        $this->exactKeys($capacity, [
            'source_bytes', 'evidence_available_bytes', 'working_available_bytes',
            'temporary_required_bytes', 'staging_required_bytes', 'rollback_required_bytes',
            'approved_contingency_percent', 'accepted', 'planned_by', 'planned_at',
        ], 'source capacity plan');
        $entries = $inventory['entries'] ?? null;

        if (! is_array($entries)) {
            throw new RuntimeException('Historic source inventory has no capacity membership.');
        }

        $observedBytes = array_sum(array_map(
            static fn (array $entry): int => is_int($entry['byte_size']) ? $entry['byte_size'] : 0,
            $entries,
        ));

        foreach ([
            'source_bytes', 'evidence_available_bytes', 'working_available_bytes',
            'temporary_required_bytes', 'staging_required_bytes', 'rollback_required_bytes',
            'approved_contingency_percent',
        ] as $field) {
            if (! is_int($capacity[$field]) || $capacity[$field] < 0) {
                throw new RuntimeException("Historic source capacity plan {$field} must be a non-negative integer.");
            }
        }

        $workingRequirement = $capacity['source_bytes']
            + $capacity['temporary_required_bytes']
            + $capacity['staging_required_bytes']
            + $capacity['rollback_required_bytes'];
        $workingRequirement += (int) ceil(
            $workingRequirement * ($capacity['approved_contingency_percent'] / 100),
        );

        if ($capacity['source_bytes'] !== $observedBytes
            || $capacity['evidence_available_bytes'] < $observedBytes
            || $capacity['working_available_bytes'] < $workingRequirement
            || $capacity['accepted'] !== true
            || ! is_string($capacity['planned_by']) || trim($capacity['planned_by']) === ''
            || ! is_string($capacity['planned_at']) || trim($capacity['planned_at']) === '') {
            throw new RuntimeException('Historic source custody capacity plan is insufficient or unaccepted.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("Historic {$label} has missing or unknown fields.");
        }
    }
}
