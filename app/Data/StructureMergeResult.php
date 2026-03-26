<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;

readonly class StructureMergeResult
{
    /**
     * @param  array{conflicts?:array<int, array<string, mixed>>}  $syncResult
     * @param  list<array<string, mixed>>  $stagedConflicts
     */
    public function __construct(
        public ChurchService $churchService,
        public ChurchServiceItemSource $incomingSource,
        public bool $wasMerged,
        public bool $wasStaged,
        public array $syncResult = [],
        public array $stagedConflicts = [],
        public string $reason = '',
    ) {}

    public function hasConflicts(): bool
    {
        return $this->stagedConflicts !== [];
    }
}
