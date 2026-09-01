<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<?ServiceSectionMetadata, ServiceSectionMetadata|array<string, mixed>|string|null>
 */
class ServiceSectionMetadataCast implements CastsAttributes, ComparesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ServiceSectionMetadata
    {
        $payload = $this->decode($value);

        return ServiceSectionMetadata::fromArray($payload);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof ServiceSectionMetadata) {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === $secondValue) {
            return true;
        }

        $first = $this->decode($firstValue);
        $second = $this->decode($secondValue);

        if ($first === null || $second === null) {
            return false;
        }

        return $this->canonicalJson($first) === $this->canonicalJson($second);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->normalise($value), JSON_THROW_ON_ERROR);
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalised = [];

        foreach ($value as $key => $child) {
            $normalised[$key] = $this->normalise($child);
        }

        if (! array_is_list($normalised)) {
            ksort($normalised, SORT_STRING);
        }

        return $normalised;
    }
}
