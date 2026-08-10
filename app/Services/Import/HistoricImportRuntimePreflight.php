<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use RuntimeException;

class HistoricImportRuntimePreflight
{
    /**
     * @var list<string>
     */
    private const ExactKeys = [
        'format',
        'commit',
        'image_digest',
        'package_lock_sha256',
        'schema',
        'database',
        'storage',
        'providers',
        'binaries',
        'prompts',
        'algorithms',
        'queues',
        'resources',
        'clock',
        'outbound_probe',
    ];

    /**
     * Validate complete, already-observed runtime evidence and return the exact
     * fingerprint bound into the operation identity. Collection stays outside
     * this class so a verifier can obtain it independently from the operator.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function fingerprint(array $evidence): string
    {
        $keys = array_keys($evidence);
        sort($keys, SORT_STRING);
        $expected = self::ExactKeys;
        sort($expected, SORT_STRING);

        if ($keys !== $expected || ($evidence['format'] ?? null) !== 'crockenhill.historic-runtime.v1') {
            throw new RuntimeException('Historic runtime evidence must contain the exact v1 contract.');
        }

        if (! is_string($evidence['commit']) || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $evidence['commit']) !== 1) {
            throw new RuntimeException('Historic runtime commit identity is invalid.');
        }

        $this->assertHash($evidence['package_lock_sha256'] ?? null, 'package_lock_sha256');

        if (! is_string($evidence['image_digest'])
            || preg_match('/\A[^@\s]+@sha256:[0-9a-f]{64}\z/', $evidence['image_digest']) !== 1) {
            throw new RuntimeException('Historic runtime image must be pinned by SHA-256 digest.');
        }

        $this->assertNonEmptyMap($evidence, 'schema');
        $this->assertNonEmptyMap($evidence, 'database');
        $this->assertStorage($evidence['storage']);
        $this->assertProviders($evidence['providers']);
        $this->assertBinaries($evidence['binaries']);

        foreach (['prompts', 'algorithms'] as $section) {
            $values = $evidence[$section] ?? null;

            if (! is_array($values) || $values === []) {
                throw new RuntimeException("Historic runtime {$section} evidence is missing.");
            }

            foreach ($values as $name => $hash) {
                if (! is_string($name) || $name === '') {
                    throw new RuntimeException("Historic runtime {$section} identity is invalid.");
                }

                $this->assertHash($hash, "{$section}.{$name}");
            }
        }

        $this->assertPositiveMap($evidence, 'queues');
        $this->assertPositiveMap($evidence, 'resources');

        $clock = $evidence['clock'] ?? null;
        if (! is_array($clock) || ! is_int($clock['offset_ms'] ?? null) || abs($clock['offset_ms']) > 1_000) {
            throw new RuntimeException('Historic runtime clock offset exceeds one second.');
        }

        $probe = $evidence['outbound_probe'] ?? null;
        if (! is_array($probe) || ($probe['ok'] ?? null) !== true || ! is_string($probe['observed_at'] ?? null)) {
            throw new RuntimeException('Historic runtime outbound provider probe did not pass.');
        }

        return CanonicalJson::hash($evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function assertOperationBinding(HistoricImportOperation $operation, array $evidence): void
    {
        if ($operation->notification_mode !== 'external_disabled') {
            throw new RuntimeException('Historic operation is not bound to notification isolation.');
        }

        if (! hash_equals($operation->target_fingerprint, app(HistoricImportTargetFingerprint::class)->hash())) {
            throw new RuntimeException('Historic import resolved target differs from the accepted operation binding.');
        }

        if (! hash_equals($operation->runtime_fingerprint, $this->fingerprint($evidence))) {
            throw new RuntimeException('Historic runtime evidence differs from the accepted operation binding.');
        }
    }

    /** @param array<string, mixed> $evidence */
    public function assertCheckpointBinding(HistoricImportCheckpoint $checkpoint, array $evidence): void
    {
        $operation = $checkpoint->operation;

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('Historic checkpoint has no owning operation.');
        }

        $this->assertOperationBinding($operation, $evidence);

        if ($checkpoint->runtime_fingerprint !== null
            && ! hash_equals($checkpoint->runtime_fingerprint, $operation->runtime_fingerprint)) {
            throw new RuntimeException('Historic checkpoint runtime binding changed after admission.');
        }
    }

    private function assertHash(mixed $hash, string $name): void
    {
        if (! is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException("Historic runtime {$name} hash is invalid.");
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertNonEmptyMap(array $evidence, string $section): void
    {
        if (! is_array($evidence[$section] ?? null) || $evidence[$section] === []) {
            throw new RuntimeException("Historic runtime {$section} evidence is missing.");
        }
    }

    private function assertProviders(mixed $providers): void
    {
        if (! is_array($providers) || $providers === []) {
            throw new RuntimeException('Historic runtime provider evidence is missing.');
        }

        foreach ($providers as $stage => $provider) {
            if (! is_string($stage) || ! is_array($provider)
                || ! is_string($provider['service'] ?? null)
                || in_array(strtolower($provider['service']), ['', 'mock', 'fake'], true)
                || ! is_string($provider['model'] ?? null)
                || $provider['model'] === ''
                || ($provider['credential_present'] ?? null) !== true
                || ($provider['connectivity_verified'] ?? null) !== true) {
                throw new RuntimeException('Historic runtime requires exact non-mock provider, model, credential and connectivity evidence.');
            }
        }
    }

    private function assertStorage(mixed $storage): void
    {
        if (! is_array($storage) || $storage === []
            || ($storage['encryption_at_rest_verified'] ?? null) !== true
            || ($storage['encryption_in_transit_verified'] ?? null) !== true) {
            throw new RuntimeException('Historic runtime storage must prove encryption at rest and in transit.');
        }
    }

    private function assertBinaries(mixed $binaries): void
    {
        if (! is_array($binaries) || array_keys($binaries) !== ['ffmpeg', 'ffprobe']) {
            throw new RuntimeException('Historic runtime must identify exactly FFmpeg and ffprobe.');
        }

        foreach ($binaries as $name => $binary) {
            if (! is_array($binary) || ! is_string($binary['version'] ?? null) || $binary['version'] === '') {
                throw new RuntimeException("Historic runtime {$name} version is missing.");
            }

            $this->assertHash($binary['sha256'] ?? null, "{$name} binary");

            if (! is_array($binary['arguments'] ?? null)) {
                throw new RuntimeException("Historic runtime {$name} arguments are missing.");
            }
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertPositiveMap(array $evidence, string $section): void
    {
        $values = $evidence[$section] ?? null;

        if (! is_array($values) || $values === []) {
            throw new RuntimeException("Historic runtime {$section} evidence is missing.");
        }

        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException("Historic runtime {$section} values must be positive integers.");
            }
        }
    }
}
