<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Support\CanonicalJson;
use RuntimeException;

class ChurchServiceConvergenceBundle
{
    public const FORMAT = 'crockenhill-service-convergence';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    public function make(
        string $batchHash,
        string $mediaBundleHash,
        array $fingerprint,
        array $services,
    ): array {
        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'batch_hash' => $batchHash,
            'media_bundle_hash' => $mediaBundleHash,
            'processing_fingerprint' => $fingerprint,
            'services' => $services,
        ];
        $bundle['bundle_hash'] = CanonicalJson::hash($bundle);

        return $this->validate($bundle);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function validate(array $bundle): array
    {
        $expectedKeys = [
            'format', 'version', 'batch_hash', 'media_bundle_hash',
            'processing_fingerprint', 'services', 'bundle_hash',
        ];
        $actualKeys = array_keys($bundle);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('Convergence bundle has missing or unknown envelope keys.');
        }

        if ($bundle['format'] !== self::FORMAT || $bundle['version'] !== self::VERSION) {
            throw new RuntimeException('Unsupported convergence bundle format or version.');
        }

        foreach (['batch_hash', 'media_bundle_hash', 'bundle_hash'] as $field) {
            $this->requireHash($bundle[$field], $field);
        }

        if (! is_array($bundle['processing_fingerprint']) || $bundle['processing_fingerprint'] === []) {
            throw new RuntimeException('Convergence bundle processing fingerprint is missing.');
        }

        if (! is_array($bundle['services']) || ! array_is_list($bundle['services']) || $bundle['services'] === []) {
            throw new RuntimeException('Convergence bundle services must be a non-empty list.');
        }

        $expectedHash = CanonicalJson::hash(array_diff_key($bundle, ['bundle_hash' => true]));

        if (! hash_equals($expectedHash, $bundle['bundle_hash'])) {
            throw new RuntimeException('Convergence bundle hash does not match its payload.');
        }

        $identities = [];

        foreach ($bundle['services'] as $index => $service) {
            if (! is_array($service)) {
                throw new RuntimeException("Convergence service {$index} must be an object.");
            }

            foreach ([
                'date', 'service', 'evidence_set_hash', 'pre_review_hash',
                'resulting_canonical_hash', 'manual_revision', 'review',
                'canonical_manifest',
            ] as $field) {
                if (! array_key_exists($field, $service)) {
                    throw new RuntimeException("Convergence service {$index} is missing {$field}.");
                }
            }

            $identity = "{$service['date']}|{$service['service']}";

            if (isset($identities[$identity])) {
                throw new RuntimeException("Duplicate convergence service identity {$identity}.");
            }

            $identities[$identity] = true;
            $this->requireHash($service['evidence_set_hash'], "services.{$index}.evidence_set_hash");
            $this->requireHash($service['pre_review_hash'], "services.{$index}.pre_review_hash");
            $this->requireHash($service['resulting_canonical_hash'], "services.{$index}.resulting_canonical_hash");
        }

        $this->guardPortable($bundle);

        return $bundle;
    }

    private function requireHash(mixed $value, string $field): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("{$field} must be a lowercase SHA-256.");
        }
    }

    private function guardPortable(mixed $value, string $path = 'bundle'): void
    {
        if (! is_array($value)) {
            if (is_string($value) && (str_starts_with($value, '/') || str_starts_with($value, 'file://'))) {
                throw new RuntimeException("{$path} contains a local path.");
            }

            return;
        }

        foreach ($value as $key => $nested) {
            $nestedPath = "{$path}.{$key}";

            if (
                is_string($key)
                && ! in_array($key, ['source_assertion_hashes'], true)
                && preg_match('/(^|_)(id|ids)$/i', $key) === 1
            ) {
                throw new RuntimeException("{$nestedPath} contains a local database identity.");
            }

            if (is_string($key) && preg_match('/(secret|token|path|queue|job|attempt|retry)/i', $key) === 1) {
                throw new RuntimeException("{$nestedPath} contains forbidden runtime or path data.");
            }

            $this->guardPortable($nested, $nestedPath);
        }
    }
}
