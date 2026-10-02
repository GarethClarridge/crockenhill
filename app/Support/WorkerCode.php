<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The commit a long-running process booted on, against the commit its checkout holds now.
 *
 * A queue worker keeps the code it loaded at boot, so after a commit it runs old code while
 * {@see RepositoryCommit::current()} already reads the new one: the corpus re-run's stamps
 * would then name code that never ran. The boot commit is recorded once per process, as the
 * application registers, and a worker whose checkout has moved on exits before its next job
 * (registered in {@see \App\Providers\AppServiceProvider}).
 */
class WorkerCode
{
    private static ?string $bootCommit = null;

    private static ?string $bootRevision = null;

    public function __construct(private readonly ?string $basePath = null) {}

    public static function recordBoot(?string $commit): void
    {
        self::$bootCommit = $commit;
    }

    public static function bootCommit(): ?string
    {
        return self::$bootCommit;
    }

    /**
     * The {@see CodeRevision} a queue worker booted on: what the re-run's stamps compare, since a
     * commit that changes only documentation leaves the code, and the evidence, unchanged.
     */
    public static function recordBootRevision(?string $revision): void
    {
        self::$bootRevision = $revision;
    }

    public static function bootRevision(): ?string
    {
        return self::$bootRevision;
    }

    /**
     * Whether this process is a queue worker, the only kind whose boot revision is recorded: the
     * fingerprint reads every code file, which a command or test has no need to pay for at boot.
     *
     * @param  list<string>|null  $argv
     */
    public static function isQueueWorker(?array $argv = null): bool
    {
        $argv ??= is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];

        return in_array($argv[1] ?? null, ['queue:work', 'queue:listen'], true);
    }

    /**
     * Whether the checkout now holds a different commit from the one this process booted on.
     * Unknown on either side is not stale: a deployment without a readable checkout has no
     * commit to compare.
     */
    public function isStale(): bool
    {
        $current = RepositoryCommit::current($this->basePath);

        return self::$bootCommit !== null && $current !== null && $current !== self::$bootCommit;
    }
}
