<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A fingerprint of the code a checkout would run: the path and content of every file under
 * {@see self::PATHS}, the places that decide what the pipeline does.
 *
 * The corpus re-run binds its evidence to the code that produced it. A commit hash did that
 * too coarsely and too finely at once: a plan edited mid-run moved it, stranding every round
 * not yet cut, while an uncommitted code change left it alone. The revision moves exactly when
 * the code does. Configuration in `.env` is not code; a ruling that changes it is recorded in
 * the plan, and the workers that read it are restarted.
 */
final class CodeRevision
{
    /** Relative to the checkout. `bootstrap/cache` is generated, so only the two source files count. */
    public const PATHS = [
        'app',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'composer.lock',
        'config',
        'database',
        'resources',
        'routes',
    ];

    /** @var array<string, string|null> */
    private static array $current = [];

    /**
     * The running checkout's revision, computed once per process: a command compares against the
     * code it was started on, as a worker does ({@see WorkerCode::bootRevision()}).
     */
    public static function current(?string $basePath = null): ?string
    {
        $basePath ??= base_path();

        return self::$current[$basePath] ??= self::compute($basePath);
    }

    public static function compute(string $basePath): ?string
    {
        $files = [];

        foreach (self::PATHS as $path) {
            $absolute = $basePath.'/'.$path;

            if (is_file($absolute)) {
                $files[$path] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[substr($file->getPathname(), strlen($basePath) + 1)] = $file->getPathname();
                }
            }
        }

        if ($files === []) {
            return null;
        }

        ksort($files, SORT_STRING);
        $context = hash_init('sha256');

        foreach ($files as $relative => $absolute) {
            $hash = hash_file('sha256', $absolute);

            if ($hash === false) {
                return null;
            }

            hash_update($context, $relative."\0".$hash."\n");
        }

        return hash_final($context);
    }
}
