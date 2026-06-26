<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;

/**
 * The request-level memoization seam for SermonViewPresenter.
 *
 * Owns the sermon-id-keyed cache the presenter's accessors share: the
 * cacheKey() → isset-check → compute → store dance, plus the three typed memo
 * stores. Extracted so the presenter's accessors stay thin delegations and the
 * caching strategy lives in one place; behaviour is unchanged (keys still
 * combine the sermon id and updated_at timestamp, and the computed flag still
 * makes null a legitimate cached value).
 */
class SermonPresenterCache
{
    /**
     * General memoization for scalar and nullable values.
     *
     * @var array<string, mixed>
     */
    private array $memoized = [];

    /**
     * Memoization for generated URLs.
     *
     * @var array<string, ?string>
     */
    private array $memoizedUrls = [];

    /**
     * Memoization for presented data arrays and collections.
     *
     * @var array<string, array<string, mixed>|array<int, array<string, mixed>>>
     */
    private array $memoizedPresents = [];

    /**
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

    /**
     * Clear every memo store.
     */
    public function clear(): void
    {
        $this->memoized = [];
        $this->memoizedUrls = [];
        $this->memoizedPresents = [];
        $this->computed = [];
    }

    /**
     * Memoize a per-sermon computation keyed by the sermon's identity + timestamp.
     *
     * The computed flag is what makes a null result a legitimate cached value
     * (rather than a perpetual cache miss), so the flag is set before the value
     * is stored. `$store` names which memo array the result lives in; the
     * literal-string union keeps the dynamic write type-safe.
     *
     * @template TValue
     *
     * @param  'memoized'|'memoizedUrls'|'memoizedPresents'  $store
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    public function remember(Sermon $sermon, string $type, string $store, callable $compute): mixed
    {
        $key = $this->cacheKey($sermon, $type);

        if (isset($this->computed[$key])) {
            return $this->{$store}[$key];
        }

        $this->computed[$key] = true;

        return $this->{$store}[$key] = $compute();
    }

    /**
     * Memoize a collection-level presentation keyed by an arbitrary cache key
     * rather than a single sermon (e.g. the combined ids of a listing).
     *
     * @param  callable(): array<int, array<string, mixed>>  $compute
     * @return array<int, array<string, mixed>>
     */
    public function rememberCollection(string $key, callable $compute): array
    {
        if (isset($this->computed[$key])) {
            /** @var array<int, array<string, mixed>> */
            return $this->memoizedPresents[$key];
        }

        $this->computed[$key] = true;

        return $this->memoizedPresents[$key] = $compute();
    }

    /**
     * Generate a cache key for the given sermon and type.
     *
     * Performance Optimization: Memoizes the sermon's combined ID and timestamp
     * within the request to avoid redundant string concatenations and Carbon
     * object method calls when generating keys for multiple sermon attributes.
     * Uses isset() for faster lookup performance and handles unpersisted models
     * by falling back to object hashes.
     */
    private function cacheKey(Sermon $sermon, string $type): string
    {
        $id = $sermon->id ?? 'u'.spl_object_id($sermon);

        $key = "sermon_key_{$id}";
        if (! isset($this->computed[$key])) {
            $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;
            $this->computed[$key] = true;
            $this->memoized[$key] = "{$id}_{$timestamp}";
        }

        /** @var string */
        $sermonKey = $this->memoized[$key];

        return $type.'_'.$sermonKey;
    }
}
