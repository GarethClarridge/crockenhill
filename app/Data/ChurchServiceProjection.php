<?php

declare(strict_types=1);

namespace App\Data;

readonly class ChurchServiceProjection
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $serviceContent
     * @param  array<string, array<string, mixed>>  $fieldDecisions  Keyed by source, source key, revision hash and assertion key
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array{format: string, version: int}  $policyFingerprint
     */
    public function __construct(
        public array $policyFingerprint,
        public array $items,
        public array $serviceContent,
        public string $sourceSummary,
        public string $hash,
        public array $fieldDecisions = [],
        public array $conflicts = [],
    ) {}
}
