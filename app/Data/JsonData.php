<?php

declare(strict_types=1);

namespace App\Data;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use LogicException;

/**
 * @implements ArrayAccess<int|string, mixed>
 * @implements Arrayable<int|string, mixed>
 */
abstract readonly class JsonData implements Arrayable, ArrayAccess, JsonSerializable
{
    /**
     * @return array<int|string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('JSON data wrappers are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('JSON data wrappers are read-only.');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                $value
            )
        ));
    }
}
