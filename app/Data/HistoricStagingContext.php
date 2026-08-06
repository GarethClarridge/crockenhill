<?php

declare(strict_types=1);

namespace App\Data;

use RuntimeException;

final readonly class HistoricStagingContext
{
    /**
     * @param  array{driver:string,bucket:?string,root_fingerprint:string,prefix_fingerprint:string}  $storageIdentity
     */
    public function __construct(
        public string $manifestHash,
        public string $planHash,
        public string $stagingDisk,
        public string $batchRoot,
        public array $storageIdentity,
    ) {
        $this->assertHash($manifestHash, 'manifest hash');
        $this->assertHash($planHash, 'plan hash');

        if ($stagingDisk === '' || $batchRoot === '') {
            throw new RuntimeException('Historic staging context requires a disk and batch root.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'staging_disk' => $this->stagingDisk,
            'batch_root' => $this->batchRoot,
            'storage_identity' => $this->storageIdentity,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        $identity = $payload['storage_identity'] ?? null;

        if (! is_array($identity)) {
            throw new RuntimeException('Historic staging context has no storage identity.');
        }

        $driver = $identity['driver'] ?? null;
        $bucket = $identity['bucket'] ?? null;
        $rootFingerprint = $identity['root_fingerprint'] ?? null;
        $prefixFingerprint = $identity['prefix_fingerprint'] ?? null;

        if (
            ! is_string($driver)
            || (! is_string($bucket) && $bucket !== null)
            || ! is_string($rootFingerprint)
            || ! is_string($prefixFingerprint)
        ) {
            throw new RuntimeException('Historic staging context has an invalid storage identity.');
        }

        $manifestHash = $payload['manifest_hash'] ?? null;
        $planHash = $payload['plan_hash'] ?? null;
        $stagingDisk = $payload['staging_disk'] ?? null;
        $batchRoot = $payload['batch_root'] ?? null;

        if (! is_string($manifestHash) || ! is_string($planHash) || ! is_string($stagingDisk) || ! is_string($batchRoot)) {
            throw new RuntimeException('Historic staging context is incomplete.');
        }

        return new self(
            manifestHash: $manifestHash,
            planHash: $planHash,
            stagingDisk: $stagingDisk,
            batchRoot: $batchRoot,
            storageIdentity: [
                'driver' => $driver,
                'bucket' => $bucket,
                'root_fingerprint' => $rootFingerprint,
                'prefix_fingerprint' => $prefixFingerprint,
            ],
        );
    }

    private function assertHash(string $hash, string $description): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException("Historic staging context has an invalid {$description}.");
        }
    }
}
