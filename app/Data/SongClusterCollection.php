<?php

declare(strict_types=1);

namespace App\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SongCluster>
 */
final class SongClusterCollection extends JsonData implements Countable, IteratorAggregate
{
    /**
     * @param  list<SongCluster>  $items
     */
    public function __construct(
        public readonly array $items,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $cluster) {
            $parsed = SongCluster::fromArray($cluster);

            if ($parsed instanceof SongCluster) {
                $items[] = $parsed;
            }
        }

        return new self($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, SongCluster>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (SongCluster $cluster): array => $cluster->toArray(),
            $this->items
        );
    }
}
