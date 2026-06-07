<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A single post-deploy / monitoring canary: one representative URL for a public
 * route type, the status it should return, how many times to hit it, and a body
 * marker proving the real response rendered (empty marker = status only).
 */
final readonly class RouteCanary
{
    public function __construct(
        public string $url,
        public int $expectedStatus,
        public int $hits,
        public string $marker,
    ) {}

    /**
     * Tab-separated manifest row consumed by scripts/post-deploy-smoke.sh:
     * `url \t status \t hits \t marker`.
     */
    public function toManifestLine(): string
    {
        return implode("\t", [$this->url, (string) $this->expectedStatus, (string) $this->hits, $this->marker]);
    }
}
