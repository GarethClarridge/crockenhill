<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Support\CanonicalJson;
use JsonException;
use RuntimeException;

class HistoricProcessingResultBundle
{
    public const FORMAT = 'crockenhill-historic-processing-result';

    public const VERSION = 2;

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    public function make(string $batchHash, array $fingerprint, array $services): array
    {
        $services = array_map(
            fn (array $service): array => [
                ...$service,
                'assets' => $this->normalizeAssets($service['assets'] ?? []),
            ],
            $services,
        );
        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'batch_hash' => $batchHash,
            'processing_fingerprint' => $fingerprint,
            'services' => $services,
        ];
        $bundle['bundle_hash'] = CanonicalJson::hash($bundle);

        return $this->validate($bundle);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Historic processing result bundle must be a JSON object.');
        }

        return $this->validate($decoded);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function validate(array $bundle): array
    {
        $this->requireExactKeys($bundle, [
            'format',
            'version',
            'batch_hash',
            'processing_fingerprint',
            'services',
            'bundle_hash',
        ], 'bundle');

        if ($bundle['format'] !== self::FORMAT || $bundle['version'] !== self::VERSION) {
            throw new RuntimeException('Unsupported historic processing result bundle format or version.');
        }

        $this->requireSha256($bundle['batch_hash'], 'batch_hash');
        $this->requireSha256($bundle['bundle_hash'], 'bundle_hash');

        if (! is_array($bundle['processing_fingerprint']) || $bundle['processing_fingerprint'] === []) {
            throw new RuntimeException('processing_fingerprint must be a non-empty object.');
        }

        if (! is_array($bundle['services']) || ! array_is_list($bundle['services']) || $bundle['services'] === []) {
            throw new RuntimeException('services must be a non-empty list.');
        }

        $expectedHash = CanonicalJson::hash(array_diff_key($bundle, ['bundle_hash' => true]));

        if (! hash_equals($expectedHash, $bundle['bundle_hash'])) {
            throw new RuntimeException('Historic processing result bundle hash does not match its payload.');
        }

        $identities = [];
        $processingKeys = [];

        foreach ($bundle['services'] as $index => $service) {
            if (! is_array($service)) {
                throw new RuntimeException("services.{$index} must be an object.");
            }

            $this->validateService($service, $index);
            $identity = "{$service['date']}|{$service['service']}";
            $processingKey = $service['media_graph']['processing_key'];

            if (isset($identities[$identity])) {
                throw new RuntimeException("Duplicate natural service identity: {$identity}.");
            }

            if (isset($processingKeys[$processingKey])) {
                throw new RuntimeException("Duplicate processing identity: {$processingKey}.");
            }

            $identities[$identity] = true;
            $processingKeys[$processingKey] = true;
        }

        $this->guardPortable($bundle);

        return $bundle;
    }

    /** @param array<string, mixed> $service */
    private function validateService(array $service, int $index): void
    {
        $this->requireExactKeys($service, [
            'date',
            'service',
            'source_manifest_hash',
            'evidence_set_hash',
            'pre_review_hash',
            'media_graph',
            'livestream_source_revision',
            'assets',
        ], "services.{$index}");

        if (! is_string($service['date']) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $service['date']) !== 1) {
            throw new RuntimeException("services.{$index}.date is invalid.");
        }

        if (! is_string($service['service']) || $service['service'] === '') {
            throw new RuntimeException("services.{$index}.service is invalid.");
        }

        foreach (['source_manifest_hash', 'evidence_set_hash', 'pre_review_hash'] as $hash) {
            $this->requireSha256($service[$hash], "services.{$index}.{$hash}");
        }

        if (! is_array($service['media_graph']) || ! is_string($service['media_graph']['processing_key'] ?? null)) {
            throw new RuntimeException("services.{$index}.media_graph is incomplete.");
        }

        if (! is_array($service['livestream_source_revision'])) {
            throw new RuntimeException("services.{$index}.livestream_source_revision must be an object.");
        }

        if (! is_array($service['assets']) || ! array_is_list($service['assets'])) {
            throw new RuntimeException("services.{$index}.assets must be a list.");
        }

        $this->validateAssets($service['assets'], $index);
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function requireExactKeys(array $value, array $keys, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("{$path} has missing or unknown keys.");
        }
    }

    private function requireSha256(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("{$path} must be a lowercase SHA-256.");
        }
    }

    /**
     * @param  list<mixed>  $assets
     */
    private function validateAssets(array $assets, int $serviceIndex): void
    {
        $roles = [];
        $contentKeys = [];

        foreach ($assets as $assetIndex => $asset) {
            if (! is_array($asset)) {
                throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex} must be an object.");
            }

            $this->requireExactKeys(
                $asset,
                ['path', 'size', 'sha256', 'kind', 'roles'],
                "services.{$serviceIndex}.assets.{$assetIndex}",
            );

            if (
                ! is_string($asset['path'])
                || $asset['path'] === ''
                || str_starts_with($asset['path'], '/')
                || str_contains($asset['path'], '../')
                || str_contains($asset['path'], '\\')
            ) {
                throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex}.path is unsafe.");
            }

            if (! is_int($asset['size']) || $asset['size'] < 0) {
                throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex}.size is invalid.");
            }

            $this->requireSha256($asset['sha256'], "services.{$serviceIndex}.assets.{$assetIndex}.sha256");

            if (! is_string($asset['kind']) || $asset['kind'] === '') {
                throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex}.kind is invalid.");
            }

            if (! is_array($asset['roles']) || ! array_is_list($asset['roles']) || $asset['roles'] === []) {
                throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex}.roles must be a non-empty list.");
            }

            $contentKey = "{$asset['size']}:{$asset['sha256']}";

            if (isset($contentKeys[$contentKey])) {
                throw new RuntimeException("services.{$serviceIndex}.assets contains duplicate physical content.");
            }

            $contentKeys[$contentKey] = true;

            foreach ($asset['roles'] as $role) {
                if (! is_string($role) || $role === '') {
                    throw new RuntimeException("services.{$serviceIndex}.assets.{$assetIndex}.roles contains an invalid role.");
                }

                if (isset($roles[$role])) {
                    throw new RuntimeException("services.{$serviceIndex}.assets contains duplicate role {$role}.");
                }

                $roles[$role] = true;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>
     */
    private function normalizeAssets(array $assets): array
    {
        $normalized = [];

        foreach ($assets as $asset) {
            $roles = is_array($asset['roles'] ?? null)
                ? array_values(array_filter($asset['roles'], 'is_string'))
                : [is_string($asset['role'] ?? null) ? $asset['role'] : ''];

            $path = is_string($asset['path'] ?? null) ? $asset['path'] : '';
            $size = is_int($asset['size'] ?? null) ? $asset['size'] : -1;
            $sha256 = is_string($asset['sha256'] ?? null) ? $asset['sha256'] : '';
            $kind = is_string($asset['kind'] ?? null) && $asset['kind'] !== ''
                ? $asset['kind']
                : 'unknown';
            $contentKey = "{$size}:{$sha256}";

            if (! isset($normalized[$contentKey])) {
                $normalized[$contentKey] = [
                    'path' => $path,
                    'size' => $size,
                    'sha256' => $sha256,
                    'kind' => $kind,
                    'roles' => [],
                ];
            }

            if ($path !== '' && $path < $normalized[$contentKey]['path']) {
                $normalized[$contentKey]['path'] = $path;
            }

            $normalized[$contentKey]['roles'] = array_values(array_unique([
                ...$normalized[$contentKey]['roles'],
                ...$roles,
            ]));
            sort($normalized[$contentKey]['roles']);

            if ($normalized[$contentKey]['kind'] !== $kind) {
                $normalized[$contentKey]['kind'] = 'mixed';
            }
        }

        usort($normalized, fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $normalized;
    }

    private function guardPortable(mixed $value, string $path = 'bundle'): void
    {
        if (! is_array($value)) {
            if (is_string($value) && (
                str_starts_with($value, '/')
                || str_starts_with($value, 'file://')
                || preg_match('/\A[A-Za-z]:[\\\\\\/]/', $value) === 1
            )) {
                throw new RuntimeException("{$path} contains an unsafe local path.");
            }

            return;
        }

        foreach ($value as $key => $nested) {
            $nestedPath = "{$path}.{$key}";

            if (
                is_string($key)
                && ! in_array($key, ['processing_id', 'source_segment_keys', 'source_assertion_hashes'], true)
                && HistoricProcessingResultFieldClassifier::isIdentityKey($key)
                && ! ($key === 'id' && str_contains($path, 'thumbnail_candidates'))
            ) {
                throw new RuntimeException("{$nestedPath} contains a local database identity.");
            }

            if (is_string($key) && preg_match('/(secret|token|queue|job|attempt|retry)/i', $key) === 1) {
                throw new RuntimeException("{$nestedPath} contains secret or runtime state.");
            }

            if (
                is_string($key)
                && HistoricProcessingResultFieldClassifier::isPathKey($key)
                && ! $this->isAllowedPathKey($key, $path)
            ) {
                throw new RuntimeException("{$nestedPath} contains an unclassified path field.");
            }

            if (
                is_string($key)
                && $this->isAllowedPathKey($key, $path)
                && is_string($nested)
                && (
                    str_starts_with($nested, '/')
                    || str_starts_with($nested, 'file://')
                    || preg_match('/\A[A-Za-z]:[\\\\\/]/', $nested) === 1
                    || str_contains($nested, '../')
                    || str_contains($nested, '\\')
                )
            ) {
                throw new RuntimeException("{$nestedPath} contains an unsafe local path.");
            }

            $this->guardPortable($nested, $nestedPath);
        }
    }

    private function isAllowedPathKey(string $key, string $parentPath): bool
    {
        if ($key !== 'path') {
            return in_array($key, HistoricProcessingResultAssetRole::allowedPathFields(), true);
        }

        return preg_match('/\Abundle\.services\.\d+\.assets\.\d+\z/', $parentPath) === 1
            || preg_match('/\Abundle\.services\.\d+\.media_graph\.metadata\.service_artifacts\.\d+\z/', $parentPath) === 1;
    }
}
