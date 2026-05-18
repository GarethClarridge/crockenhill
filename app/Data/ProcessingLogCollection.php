<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Collection;

readonly class ProcessingLogCollection
{
    /**
     * @param  Collection<int, ProcessingLogEntry>  $entries
     * @param  array<string, mixed>|null  $summary
     */
    public function __construct(
        public Collection $entries,
        public int $totalCount,
        public ?string $nextCursor = null,
        public ?array $summary = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entries' => $this->entries->map(fn (ProcessingLogEntry $entry) => $entry->toArray())->toArray(),
            'total_count' => $this->totalCount,
            'next_cursor' => $this->nextCursor,
            'summary' => $this->summary,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->entries->isEmpty();
    }

    public function count(): int
    {
        return $this->entries->count();
    }

    public static function empty(): self
    {
        return new self(
            entries: collect(),
            totalCount: 0
        );
    }
}
