<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<?SongClusterCollection, SongClusterCollection|array<int, array<string, mixed>>|string|null>
 */
class SongClusterCollectionCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?SongClusterCollection
    {
        $payload = $this->decode($value);

        return SongClusterCollection::fromArray($payload);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof SongClusterCollection) {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : null;
    }
}
