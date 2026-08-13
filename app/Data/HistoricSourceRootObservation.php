<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What the filesystem actually says about one source copy's root.
 *
 * Every field here is observed. The custody artifact's matching fields are
 * *claims*, and HIR4's finding was that nobody compared the two: two writable
 * sibling folders on one disk, with different `storage_identity` strings, were
 * accepted as two independent protected copies. A single filesystem loss would
 * have taken both.
 *
 * `failureDomain` is deliberately not the path. Two roots can have different
 * canonical paths, different declared storage identities and still share one
 * mount, one device and one failure mode — which is exactly the fixture the
 * review found passing.
 */
final readonly class HistoricSourceRootObservation
{
    /**
     * @param  list<string>  $mountOptions
     */
    public function __construct(
        public string $root,
        public string $canonicalPath,
        public string $platform,
        public string $inspectorVersion,
        public string $deviceIdentity,
        public string $mountPoint,
        public string $mountSource,
        public string $filesystemType,
        public array $mountOptions,
        public bool $readOnly,
        public bool $writeProbeFailed,
    ) {}

    /**
     * The thing two copies must not share.
     *
     * Mount source and device together: the same physical store reached through
     * two paths, or two bind mounts of one filesystem, both collapse to one
     * domain here, which is the point.
     */
    public function failureDomain(): string
    {
        return hash('sha256', $this->mountSource.'|'.$this->deviceIdentity);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'inspector_version' => $this->inspectorVersion,
            'canonical_path_sha256' => hash('sha256', $this->canonicalPath),
            'device_identity' => $this->deviceIdentity,
            'mount_point_sha256' => hash('sha256', $this->mountPoint),
            'mount_source_sha256' => hash('sha256', $this->mountSource),
            'failure_domain' => $this->failureDomain(),
            'filesystem_type' => $this->filesystemType,
            'mount_options' => $this->mountOptions,
            'read_only' => $this->readOnly,
            'write_probe_failed' => $this->writeProbeFailed,
        ];
    }
}
