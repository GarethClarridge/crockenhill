<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricRecoveryArtifactObservation;
use RuntimeException;

/**
 * Opens every artifact the recovery evidence names.
 *
 * HIR5 item 3. The evidence declares logical artifact IDs; the operator supplies
 * `--artifact=id=path` for each one; this class is the only place those paths are
 * touched. Nothing here trusts the evidence: size and digest are recomputed from
 * the bytes, and the file is read twice so an artifact that changes underneath
 * the verification is refused rather than certified against whichever pass the
 * gate happened to keep.
 *
 * Delete alongside {@see HistoricImportRecoveryEvidence} once the acceptance and
 * rollback retention windows have closed (G9/WP10).
 */
final class HistoricImportRecoveryArtifactResolver
{
    public function __construct(
        private readonly HistoricSourceFilesystemInspector $inspector,
    ) {}

    /**
     * `id=path` pairs, with a duplicate ID refused rather than overwritten.
     *
     * Silently keeping the last mapping would let a second `--artifact` for an
     * ID replace the first, which is the same substitution — one artifact
     * standing in for two — the gate exists to catch.
     *
     * @param  list<string>  $options
     * @return array<string, string>
     */
    public function parseMappings(array $options): array
    {
        $mappings = [];

        foreach ($options as $option) {
            $parts = explode('=', $option, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new RuntimeException(
                    "Recovery artifact mapping must be given as artifact-id=verification-path: {$option}",
                );
            }

            [$artifactId, $path] = [trim($parts[0]), trim($parts[1])];

            if (array_key_exists($artifactId, $mappings)) {
                throw new RuntimeException("Recovery artifact {$artifactId} was supplied more than once.");
            }

            $mappings[$artifactId] = $path;
        }

        return $mappings;
    }

    /**
     * Every declared artifact, supplied exactly once and observed.
     *
     * @param  list<string>  $declared
     * @param  array<string, string>  $mappings
     * @return array<string, HistoricRecoveryArtifactObservation>
     */
    public function resolve(array $declared, array $mappings): array
    {
        $declared = array_values(array_unique($declared));
        $missing = array_values(array_diff($declared, array_keys($mappings)));
        $unexpected = array_values(array_diff(array_keys($mappings), $declared));

        if ($missing !== []) {
            throw new RuntimeException(
                'Recovery evidence declares artifacts that were not supplied for verification: '
                .implode(', ', $missing).'.',
            );
        }

        if ($unexpected !== []) {
            throw new RuntimeException(
                'Recovery verification was given artifacts the evidence does not declare: '
                .implode(', ', $unexpected).'.',
            );
        }

        $observations = [];
        $identities = [];

        foreach ($declared as $artifactId) {
            $observation = $this->observe($artifactId, $mappings[$artifactId]);

            /**
             * Two logical artifacts resolving to one file is the review's
             * "same backup presented as both the on-host and the off-host
             * restore", generalised: whatever two IDs mean, they are not two
             * things if they are one inode.
             */
            if (isset($identities[$observation->fileIdentity])) {
                throw new RuntimeException(
                    "Recovery artifacts {$identities[$observation->fileIdentity]} and {$artifactId} are the same file, "
                    .'so one artifact is being counted twice.',
                );
            }

            $identities[$observation->fileIdentity] = $artifactId;
            $observations[$artifactId] = $observation;
        }

        return $observations;
    }

    private function observe(string $artifactId, string $path): HistoricRecoveryArtifactObservation
    {
        $this->assertSafePath($artifactId, $path);

        $before = stat($path);
        $first = hash_file('sha256', $path);

        /**
         * The mount observation sits deliberately *between* the two reads. It
         * is the slowest thing this method does — a mountinfo scan and a write
         * probe — so putting it here widens the window the second read closes
         * rather than narrowing it to two adjacent syscalls.
         */
        $failureDomain = $this->inspector->observeRoot(dirname($path))->failureDomain();
        $second = hash_file('sha256', $path);
        $after = stat($path);

        if (! is_array($before) || ! is_array($after) || ! is_string($first) || ! is_string($second)) {
            throw new RuntimeException("Recovery artifact {$artifactId} could not be read.");
        }

        /**
         * Read twice on purpose. An artifact that is still being written, or
         * that is replaced between the gate reading it and the gate accepting
         * it, would otherwise be certified under whichever digest happened to
         * land first.
         */
        if (! hash_equals($first, $second)
            || $before['size'] !== $after['size']
            || $before['ino'] !== $after['ino']
            || $before['dev'] !== $after['dev']
            || $before['mtime'] !== $after['mtime']) {
            throw new RuntimeException(
                "Recovery artifact {$artifactId} changed while it was being verified; nothing was accepted.",
            );
        }

        return new HistoricRecoveryArtifactObservation(
            artifactId: $artifactId,
            byteSize: (int) $before['size'],
            sha256: $first,
            fileIdentity: hash('sha256', $before['dev'].':'.$before['ino']),
            failureDomain: $failureDomain,
            path: $path,
        );
    }

    /**
     * An absolute, canonical, regular file and nothing else.
     *
     * Comparing the supplied path with its own `realpath()` refuses a symlink at
     * any component, a relative path and a `..` traversal in one comparison: all
     * three are ways the bytes the gate hashes stop being the bytes at the path
     * the evidence was verified against.
     */
    private function assertSafePath(string $artifactId, string $path): void
    {
        if (! str_starts_with($path, '/')) {
            throw new RuntimeException("Recovery artifact {$artifactId} must be supplied as an absolute path.");
        }

        if (is_link($path)) {
            throw new RuntimeException("Recovery artifact {$artifactId} is a symlink, not a retained artifact.");
        }

        if (! is_file($path)) {
            throw new RuntimeException("Recovery artifact {$artifactId} is not a readable regular file.");
        }

        if (realpath($path) !== $path) {
            throw new RuntimeException(
                "Recovery artifact {$artifactId} must be supplied at its canonical path, with no symlinked or "
                .'relative components.',
            );
        }
    }
}
