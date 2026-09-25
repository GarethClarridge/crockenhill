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
