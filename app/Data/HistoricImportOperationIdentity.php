<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\CanonicalJson;
use RuntimeException;

final readonly class HistoricImportOperationIdentity
{
    /**
     * @param  array<string, string>  $manifestHashes
     */
    public function __construct(
        public string $operationId,
        public string $bindingHash,
        public string $batchKey,
        public array $manifestHashes,
        public string $planHash,
        public string $targetFingerprint,
    ) {
        if (preg_match('/\Ahistoric-[0-9a-f]{32}\z/', $operationId) !== 1) {
            throw new RuntimeException('Historic import operation id is invalid.');
        }

        $this->assertHash($bindingHash, 'binding hash');
        $this->assertHash($planHash, 'plan hash');
        $this->assertHash($targetFingerprint, 'target fingerprint');

        if ($batchKey === '' || $manifestHashes === []) {
            throw new RuntimeException('Historic import identity requires a batch key and manifest hashes.');
        }

        foreach ($manifestHashes as $source => $hash) {
            if ($source === '') {
                throw new RuntimeException('Historic import identity contains an empty source kind.');
            }

            $this->assertHash($hash, "{$source} manifest hash");
        }

        if ($this->canonicalManifestHashes($manifestHashes) !== $manifestHashes) {
            throw new RuntimeException('Historic import manifest hashes must be keyed in canonical order.');
        }

        if (! hash_equals($this->calculatedBindingHash(), $bindingHash)) {
            throw new RuntimeException('Historic import binding hash does not match its immutable context.');
        }

        if (! hash_equals('historic-'.substr($bindingHash, 0, 32), $operationId)) {
            throw new RuntimeException('Historic import operation id does not match its binding hash.');
        }
    }

    /** @param array<string, string> $manifestHashes */
    public static function fromBindings(
        string $batchKey,
        array $manifestHashes,
        string $planHash,
        string $targetFingerprint,
    ): self {
        $manifestHashes = self::sortManifestHashes($manifestHashes);
        $bindingHash = CanonicalJson::hash([
            'contract_version' => 1,
            'batch_key' => $batchKey,
            'manifest_hashes' => $manifestHashes,
            'plan_hash' => $planHash,
            'target_fingerprint' => $targetFingerprint,
        ]);

        return new self(
            operationId: 'historic-'.substr($bindingHash, 0, 32),
            bindingHash: $bindingHash,
            batchKey: $batchKey,
            manifestHashes: $manifestHashes,
            planHash: $planHash,
            targetFingerprint: $targetFingerprint,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'binding_hash' => $this->bindingHash,
            'batch_key' => $this->batchKey,
            'manifest_hashes' => $this->manifestHashes,
            'plan_hash' => $this->planHash,
            'target_fingerprint' => $this->targetFingerprint,
        ];
    }

    private function calculatedBindingHash(): string
    {
        return CanonicalJson::hash([
            'contract_version' => 1,
            'batch_key' => $this->batchKey,
            'manifest_hashes' => $this->manifestHashes,
            'plan_hash' => $this->planHash,
            'target_fingerprint' => $this->targetFingerprint,
        ]);
    }

    /**
     * @param  array<string, string>  $hashes
     * @return array<string, string>
     */
    private static function sortManifestHashes(array $hashes): array
    {
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param  array<string, string>  $hashes
     * @return array<string, string>
     */
    private function canonicalManifestHashes(array $hashes): array
    {
        return self::sortManifestHashes($hashes);
    }

    private function assertHash(string $hash, string $description): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException("Historic import {$description} is invalid.");
        }
    }
}
