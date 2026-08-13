<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What one recovery artifact actually is, read from the artifact itself.
 *
 * HIR5's finding was that recovery evidence named digests nobody reproduced:
 * `str_repeat('1', 64)` satisfied the mandatory recovery gate because the gate
 * checked digest *syntax*. Every field here is recomputed from bytes, and the
 * evidence's matching fields are claims that must equal them.
 *
 * The path is deliberately absent. A verification path is an observation input
 * — where this host happened to find the artifact during one verification — and
 * persisting it into the retained artifact would turn a local detail into
 * portable authority that a later reader could mistake for custody.
 * `failureDomain` and `fileIdentity` are what survive, because they are what the
 * gate reasons about: two artifacts that are one file, or two copies that one
 * disk loss takes together.
 *
 * Delete alongside the recovery verifier once the acceptance and rollback
 * retention windows have closed (G9/WP10).
 */
final readonly class HistoricRecoveryArtifactObservation
{
    /**
     * @param  string  $path  where this artifact was read from during *this*
     *                        verification. Available so a verifier that needs an
     *                        artifact's content — a row manifest — can re-read
     *                        it, and deliberately omitted from {@see toArray()}.
     */
    public function __construct(
        public string $artifactId,
        public int $byteSize,
        public string $sha256,
        public string $fileIdentity,
        public string $failureDomain,
        public string $path,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'byte_size' => $this->byteSize,
            'sha256' => $this->sha256,
            'file_identity' => $this->fileIdentity,
            'failure_domain' => $this->failureDomain,
        ];
    }
}
