<?php

declare(strict_types=1);

namespace App\Data;

readonly class ChurchServiceProjection
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $serviceContent
     * @param  list<array<string, mixed>>  $fieldDecisions
     * @param  list<array<string, mixed>>  $conflicts
     */
    public function __construct(
        public array $items,
        public array $serviceContent,
        public string $sourceSummary,
        public string $hash,
        public array $fieldDecisions = [],
        public array $conflicts = [],
    ) {}
}
