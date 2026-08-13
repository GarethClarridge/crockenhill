<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricSourceRootObservation;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Linux observation, from `/proc/self/mountinfo`.
 *
 * `mountinfo` is the authoritative view of what is mounted where, and it is the
 * only one that distinguishes a bind mount of an already-mounted filesystem
 * from a genuinely separate store — which is precisely the distinction "two
 * independent copies" turns on. Parsing `mount` output would not.
 *
 * Read-only is taken from the mount options *and* confirmed by a write probe.
 * Either one alone is defeatable: options can say `ro` on a filesystem exported
 * writable underneath, and a probe can fail for reasons that have nothing to do
 * with protection.
 *
 * Delete alongside the source-acquisition verifier (G9/WP10).
 */
class LinuxHistoricSourceFilesystemInspector implements HistoricSourceFilesystemInspector
{
    public const string Version = 'linux-mountinfo-v1';

    public function platform(): string
    {
        return 'Linux';
    }

    public function observeRoot(string $root): HistoricSourceRootObservation
    {
        $canonical = realpath($root);

        if (! is_string($canonical) || ! is_dir($canonical)) {
            throw new RuntimeException("Historic source copy does not exist: {$root}");
        }

        $stat = stat($canonical);

        if (! is_array($stat)) {
            throw new RuntimeException("Historic source copy device cannot be observed: {$root}");
        }

        $mount = $this->mountFor($canonical);

        return new HistoricSourceRootObservation(
            root: $root,
            canonicalPath: $canonical,
            platform: $this->platform(),
            inspectorVersion: self::Version,
            deviceIdentity: (string) $stat['dev'],
            mountPoint: $mount['mount_point'],
            mountSource: $mount['mount_source'],
            filesystemType: $mount['filesystem_type'],
            mountOptions: $mount['options'],
            readOnly: in_array('ro', $mount['options'], true),
            writeProbeFailed: ! $this->writeProbeSucceeds($canonical),
        );
    }

    public function supportsExtendedAttributes(): bool
    {
        $which = new Process(['which', 'getfattr']);
        $which->run();

        return $which->isSuccessful();
    }

    public function xattrs(string $path): array
    {
        $listing = new Process(['getfattr', '--absolute-names', '--dump', '--no-dereference', $path]);
        $listing->run();

        if (! $listing->isSuccessful()) {
            /**
             * `getfattr` exits non-zero for a path it cannot read at all. A path
             * with no attributes exits zero with empty output, so the two are
             * distinguishable and only the first is a refusal.
             */
            throw new RuntimeException("Historic source extended attributes cannot be read: {$path}");
        }

        $attributes = [];

        foreach (explode("\n", $listing->getOutput()) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $attributes[$name] = trim($value, '"');
        }

        ksort($attributes);

        return $attributes;
    }

    /**
     * The longest mount point that is a prefix of this path — the one the path
     * is actually on.
     *
     * @return array{mount_point: string, mount_source: string, filesystem_type: string, options: list<string>}
     */
    private function mountFor(string $canonical): array
    {
        $mountinfo = @file_get_contents('/proc/self/mountinfo');

        if (! is_string($mountinfo) || trim($mountinfo) === '') {
            throw new RuntimeException(
                'Historic source mount facts cannot be observed on this host: /proc/self/mountinfo is unavailable.',
            );
        }

        $best = null;

        foreach (explode("\n", $mountinfo) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $separator = array_search('-', $fields, true);

            if ($separator === false || ! isset($fields[4], $fields[5], $fields[$separator + 1], $fields[$separator + 2])) {
                continue;
            }

            $mountPoint = $this->unescape($fields[4]);

            if ($mountPoint !== '/' && ! str_starts_with($canonical.'/', $mountPoint.'/')) {
                continue;
            }

            if ($best !== null && strlen($best['mount_point']) >= strlen($mountPoint)) {
                continue;
            }

            $best = [
                'mount_point' => $mountPoint,
                'mount_source' => $this->unescape($fields[$separator + 2]),
                'filesystem_type' => $fields[$separator + 1],
                'options' => array_values(array_filter(explode(',', $fields[5]))),
            ];
        }

        if ($best === null) {
            throw new RuntimeException("Historic source mount cannot be identified for {$canonical}.");
        }

        return $best;
    }

    /** `mountinfo` octal-escapes space, tab, newline and backslash. */
    private function unescape(string $value): string
    {
        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $value);
    }

    /**
     * A probe that creates and removes one file. It never writes into an
     * existing path, so a genuinely protected copy is untouched and an
     * unprotected one is observed rather than assumed.
     */
    private function writeProbeSucceeds(string $canonical): bool
    {
        $probe = $canonical.'/.historic-source-write-probe-'.bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);
        @unlink($probe);

        return true;
    }
}
