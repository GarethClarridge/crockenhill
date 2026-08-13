<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricSourceRootObservation;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Darwin/APFS observation, for the acquisition host.
 *
 * macOS has no `/proc/self/mountinfo`, so the mount point and its backing device
 * come from `df -P` and the mount options from `mount`. Both are parsed rather
 * than trusted in aggregate: `df` alone would not say whether the volume is
 * read-only, and `mount` alone would not say which of several mounts this path
 * is on.
 *
 * `xattr -l` reports the attributes actually present, which is what the custody
 * artifact's claims are compared against.
 *
 * Delete alongside the source-acquisition verifier (G9/WP10).
 */
class DarwinHistoricSourceFilesystemInspector implements HistoricSourceFilesystemInspector
{
    public const string Version = 'darwin-df-mount-v1';

    public function platform(): string
    {
        return 'Darwin';
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

        [$mountSource, $mountPoint] = $this->mountFor($canonical);
        [$filesystemType, $options] = $this->mountOptionsFor($mountPoint);

        return new HistoricSourceRootObservation(
            root: $root,
            canonicalPath: $canonical,
            platform: $this->platform(),
            inspectorVersion: self::Version,
            deviceIdentity: (string) $stat['dev'],
            mountPoint: $mountPoint,
            mountSource: $mountSource,
            filesystemType: $filesystemType,
            mountOptions: $options,
            readOnly: in_array('read-only', $options, true),
            writeProbeFailed: ! $this->writeProbeSucceeds($canonical),
        );
    }

    public function supportsExtendedAttributes(): bool
    {
        $which = new Process(['which', 'xattr']);
        $which->run();

        return $which->isSuccessful();
    }

    public function xattrs(string $path): array
    {
        $names = new Process(['xattr', '-s', $path]);
        $names->run();

        if (! $names->isSuccessful()) {
            throw new RuntimeException("Historic source extended attributes cannot be read: {$path}");
        }

        $attributes = [];

        foreach (explode("\n", trim($names->getOutput())) as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = new Process(['xattr', '-p', '-s', $name, $path]);
            $value->run();

            if (! $value->isSuccessful()) {
                throw new RuntimeException("Historic source extended attribute {$name} cannot be read: {$path}");
            }

            $attributes[$name] = trim($value->getOutput());
        }

        ksort($attributes);

        return $attributes;
    }

    /** @return array{0: string, 1: string} */
    private function mountFor(string $canonical): array
    {
        $df = new Process(['df', '-P', $canonical]);
        $df->run();

        if (! $df->isSuccessful()) {
            throw new RuntimeException("Historic source mount cannot be identified for {$canonical}.");
        }

        $lines = array_values(array_filter(explode("\n", trim($df->getOutput()))));
        $fields = preg_split('/\s+/', trim($lines[1] ?? ''), 6) ?: [];

        if (! isset($fields[0], $fields[5])) {
            throw new RuntimeException("Historic source mount cannot be identified for {$canonical}.");
        }

        return [$fields[0], $fields[5]];
    }

    /** @return array{0: string, 1: list<string>} */
    private function mountOptionsFor(string $mountPoint): array
    {
        $mount = new Process(['mount']);
        $mount->run();

        if (! $mount->isSuccessful()) {
            throw new RuntimeException('Historic source mount options cannot be observed on this host.');
        }

        foreach (explode("\n", $mount->getOutput()) as $line) {
            if (preg_match('/^(?<source>\S+) on (?<point>.+) \((?<details>[^)]*)\)$/', trim($line), $matches) !== 1) {
                continue;
            }

            if ($matches['point'] !== $mountPoint) {
                continue;
            }

            $details = array_map('trim', explode(',', $matches['details']));
            $type = (string) array_shift($details);

            return [$type, $details];
        }

        throw new RuntimeException("Historic source mount options cannot be observed for {$mountPoint}.");
    }

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
