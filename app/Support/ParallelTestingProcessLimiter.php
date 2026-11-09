<?php

declare(strict_types=1);

namespace App\Support;

use Fidry\CpuCoreCounter\CpuCoreCounter;

/**
 * Caps `artisan test --parallel` when no process count is given. On the 10-core Docker host, 8 workers
 * ran the suite in 11:01 to 4's 13:28 (11-09); more contend on shared file I/O. CI's 4-vCPU runners
 * keep one worker per core.
 */
class ParallelTestingProcessLimiter
{
    private const DEFAULT_PROCESSES = 8;

    /**
     * @param  array<int, string>  $argv
     * @param  int|null  $cores  The machine's cores; counted when not given
     * @return array<int, string>
     */
    public static function apply(array $argv, ?int $cores = null): array
    {
        if (! self::shouldLimit($argv)) {
            return $argv;
        }

        $argv[] = '--processes='.min(self::DEFAULT_PROCESSES, max(1, $cores ?? self::cores()));

        return $argv;
    }

    /** ParaTest's own count (a dev dependency, present wherever tests run in parallel). */
    private static function cores(): int
    {
        return class_exists(CpuCoreCounter::class) ? (new CpuCoreCounter)->getCount() : self::DEFAULT_PROCESSES;
    }

    /**
     * @param  array<int, string>  $argv
     */
    private static function shouldLimit(array $argv): bool
    {
        return self::commandName($argv) === 'test'
            && self::hasParallelFlag($argv)
            && ! self::hasExplicitProcessCount($argv);
    }

    /**
     * @param  array<int, string>  $argv
     */
    private static function commandName(array $argv): ?string
    {
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '' || str_starts_with($argument, '-')) {
                continue;
            }

            return $argument;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $argv
     */
    private static function hasParallelFlag(array $argv): bool
    {
        return in_array('--parallel', $argv, true) || in_array('-p', $argv, true);
    }

    /**
     * @param  array<int, string>  $argv
     */
    private static function hasExplicitProcessCount(array $argv): bool
    {
        foreach ($argv as $index => $argument) {
            if (str_starts_with($argument, '--processes=')) {
                return true;
            }

            if ($argument === '--processes') {
                return isset($argv[$index + 1]) && $argv[$index + 1] !== '';
            }
        }

        return false;
    }
}
