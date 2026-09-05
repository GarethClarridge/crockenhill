<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Throwable;

/**
 * Is the historic staging volume present, and does it still accept writes?
 *
 * On 2026-09-05 a five-second mains outage took the staging drive off the bus
 * mid-run. The workers kept consuming: every job resolved a path under a
 * directory that no longer existed, failed in seconds, and marked its run
 * failed. 371 runs died in 35 minutes — not because the outage was long, but
 * because nothing stood between a dead mount and the queue. Recovering them
 * costs roughly sixteen hours of reprocessing.
 *
 * The probe therefore answers the only question that matters before a worker
 * takes historic work: can this process write to the staging volume right now?
 *
 * Why a real write rather than `is_writable()`: permission bits are metadata,
 * and metadata is exactly what survives a volume going away. A stale bind mount
 * and a volume remounted read-only both keep bits that say "writable" while
 * refusing every write. Only a write tells the truth.
 *
 * Why the result is cached for a few seconds: a worker loop runs roughly once a
 * second per worker, and six workers probing a spinning disk every iteration is
 * its own load. The cache is deliberately short — the cost of acting on a stale
 * "reachable" reading is one failed job, which is what the guard already
 * accepts as unavoidable for work already in flight.
 */
final class HistoricStagingReachability
{
    /**
     * How long a probe result stands before the volume is asked again.
     */
    private const ProbeTtlSeconds = 5;

    /**
     * Written and immediately removed at the staging root. A fixed name is
     * rewritten in place rather than accumulating one file per probe, and the
     * leading dot keeps it out of any listing the pipeline walks.
     */
    private const ProbeFilename = '.historic-staging-writable';

    /**
     * The last probe, shared across every instance in this process.
     *
     * Static for the same reason {@see HistoricStagingGuard} keeps its baseline
     * static: this class is not bound scoped or singleton, so a fresh instance
     * is constructed for each resolution, and an instance-level cache would
     * expire on every job rather than every few seconds.
     *
     * @var array{at: float, reachable: bool, reason: ?string}|null
     */
    private static ?array $lastProbe = null;

    public function __construct(
        private readonly HistoricStagingGuard $guard,
    ) {}

    /**
     * True when the staging volume is present and accepted a write just now.
     *
     * Never throws: an unconfigured or unreadable staging disk is reported as
     * unreachable, because a worker that cannot establish where its output goes
     * must not consume work either.
     */
    public function isReachable(): bool
    {
        return $this->probe()['reachable'];
    }

    /**
     * Why the volume was judged unreachable, or null when it is reachable.
     */
    public function unreachableReason(): ?string
    {
        return $this->probe()['reason'];
    }

    /**
     * @return array{at: float, reachable: bool, reason: ?string}
     */
    private function probe(): array
    {
        $now = microtime(true);

        if (self::$lastProbe !== null && ($now - self::$lastProbe['at']) < self::ProbeTtlSeconds) {
            return self::$lastProbe;
        }

        $reason = $this->firstFailure();

        return self::$lastProbe = [
            'at' => $now,
            'reachable' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * The first reason the volume cannot be written to, or null when it can.
     */
    private function firstFailure(): ?string
    {
        try {
            $disk = $this->guard->stagingDisk();
        } catch (Throwable $exception) {
            return 'No historic staging disk is configured: '.$exception->getMessage();
        }

        $configuration = config("filesystems.disks.{$disk}");

        if (! is_array($configuration)) {
            return "Historic staging disk [{$disk}] has no configuration.";
        }

        /**
         * Only a local disk has a mount that can disappear. A remote driver
         * fails per-operation with its own errors and is not this guard's
         * concern, so it is reported reachable rather than blocking the queue.
         */
        if (($configuration['driver'] ?? null) !== 'local') {
            return null;
        }

        $root = $this->guard->liveRoot($disk);

        if ($root === '') {
            return "Historic staging disk [{$disk}] has no root path.";
        }

        if (! is_dir($root)) {
            return "Historic staging root [{$root}] is not a directory; the volume is not mounted.";
        }

        return $this->writeFailure($root);
    }

    /**
     * Write and remove a probe file, reporting what stopped it.
     */
    private function writeFailure(string $root): ?string
    {
        $path = rtrim($root, '/').'/'.self::ProbeFilename;

        try {
            /**
             * `@` rather than a warning-to-exception shim: this is the one place
             * a failure is the expected outcome, and the reason is reconstructed
             * from the return value rather than from a captured warning.
             */
            $written = @file_put_contents($path, (string) time());

            if ($written === false) {
                return "Historic staging root [{$root}] rejected a write; the volume is unwritable.";
            }

            @unlink($path);

            return null;
        } catch (Throwable $exception) {
            return "Historic staging root [{$root}] rejected a write: ".$exception->getMessage();
        }
    }

    /**
     * Discard the cached probe.
     *
     * A worker never needs this — the cache is meant to expire on its own. It
     * exists for the test suite, where many tests point the staging disk at
     * different roots inside one PHP process and must not inherit the previous
     * test's reading.
     */
    public static function resetForTesting(): void
    {
        self::$lastProbe = null;
    }
}
