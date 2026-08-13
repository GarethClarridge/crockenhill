<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What a release attempt knows about one object at a public destination.
 *
 * `receipt` is whatever the provider hands back to identify the exact bytes it
 * stored — an ETag on Spaces, a content hash locally. `versionId` is null
 * wherever the store has no immutable version to name, which on production
 * Spaces is always: HIR-D1 measured `PutObject` returning a null `VersionId`
 * because bucket versioning is disabled and stays disabled.
 *
 * A null `versionId` is precisely why compensation cannot delete: without one
 * there is no way to prove the object still holds the bytes this attempt wrote.
 */
final readonly class HistoricReleaseObject
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public string $sha256,
        public ?string $receipt = null,
        public ?string $versionId = null,
        public bool $createdByThisAttempt = false,
    ) {}

    public function matches(int $size, string $sha256): bool
    {
        return $this->size === $size && hash_equals($this->sha256, $sha256);
    }
}
