<?php

declare(strict_types=1);

namespace App\Support;

class ParallelTestingProcessLimiter
{
    private const DEFAULT_PROCESSES = 4;

    /**
     * @param  array<int, string>  $argv
     * @return array<int, string>
     */
    public static function apply(array $argv): array
    {
        if (! self::shouldLimit($argv)) {
            return $argv;
        }

        $argv[] = '--processes='.self::DEFAULT_PROCESSES;

        return $argv;
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
