<?php

declare(strict_types=1);

namespace Tests\Support;

/** Enumerate captures without decompressing them during PHPUnit discovery. */
class RuledEndingsShards
{
    public const COUNT = 8;

    /** @return array<string, array{string}> */
    public static function fixtures(int $shard): array
    {
        $directory = dirname(__DIR__).'/Fixtures/RuledEndings';
        $durations = json_decode((string) file_get_contents($directory.'/shard-durations.json'), true, flags: JSON_THROW_ON_ERROR);
        $weights = [];

        foreach (glob($directory.'/*.json.gz') ?: [] as $path) {
            // New captures participate automatically, conservatively weighted until measured.
            $weights[$path] = $durations[basename($path, '.json.gz')] ?? max($durations);
        }

        $fixtures = [];
        foreach (self::partition($weights)[$shard] as $path) {
            $fixtures[basename($path, '.json.gz')] = [$path];
        }

        return $fixtures;
    }

    /**
     * Longest captures first, assigned to the lightest shard. Name breaks timing ties so
     * assignment is identical in discovery and in every worker, independent of glob order.
     *
     * @param  array<string, float>  $weights
     * @return list<list<string>>
     */
    public static function partition(array $weights): array
    {
        $paths = array_keys($weights);
        usort($paths, static fn (string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));
        $shards = array_fill(0, self::COUNT, []);
        $loads = array_fill(0, self::COUNT, 0.0);

        foreach ($paths as $path) {
            $shard = array_search(min($loads), $loads, true);
            $shards[$shard][] = $path;
            $loads[$shard] += $weights[$path];
        }

        return $shards;
    }
}
