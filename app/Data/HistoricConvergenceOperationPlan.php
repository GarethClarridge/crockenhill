<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;

readonly class HistoricConvergenceOperationPlan
{
    /**
     * `planHash` is the approval token and binds the expiry, so an expired
     * approval can never be replayed. `contentHash` is the same operation
     * without its expiry: it is recorded in the ledger so a retained record
     * shows whether a resumed run covered the same operation content as the
     * original, which the token alone cannot say once the expiry has moved.
     *
     * @param  array<string, mixed>  $processingFingerprint
     * @param  array<string, mixed>  $storageIdentity
     * @param  list<array<string, mixed>>  $services
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public string $operationId,
        public string $planHash,
        public string $contentHash,
        public string $batchHash,
        public string $mediaBundleHash,
        public string $convergenceBundleHash,
        public array $processingFingerprint,
        public array $storageIdentity,
        public DateTimeImmutable $expiresAt,
        public array $services,
        public array $summary,
    ) {}

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable);
    }
}
