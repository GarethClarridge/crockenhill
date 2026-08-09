<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Immutable bytes read from a manifest-authorised source file.
 */
readonly class VerifiedSourceSnapshot
{
    /**
     * @param  array{device:int|string,inode:int|string,size:int}  $fileIdentity
     */
    public function __construct(
        public string $relativePath,
        public string $approvedSha256,
        public string $observedSha256,
        public int $byteSize,
        public string $contents,
        public array $fileIdentity,
    ) {}
}
