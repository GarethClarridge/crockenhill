<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricReleaseObjectStore;
use App\Data\HistoricReleaseObject;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The real destination boundary: a local filesystem, or DigitalOcean Spaces
 * through the raw S3 client.
 *
 * Two things HIR-D1 measured shape the whole class.
 *
 * **Flysystem drops both conditional headers.** `AwsS3V3Adapter::AVAILABLE_OPTIONS`
 * omits `IfNoneMatch` and `IfMatch`, so `Storage::put($path, $body,
 * ['IfNoneMatch' => '*'])` silently sends an unconditional write. The conditional
 * create therefore goes through `Storage::disk(...)->getClient()`, never the
 * facade.
 *
 * **`LocalFilesystemAdapter::writeToFile()` is `file_put_contents`,** which
 * truncates, so it cannot express "create if absent" either. The local path uses
 * `fopen($path, 'x')`, which is the O_EXCL the contract needs.
 *
 * Delete alongside the release ledger after the accepted public release and
 * rollback observation window (G9/WP10).
 */
class FilesystemHistoricReleaseObjectStore implements HistoricReleaseObjectStore
{
    public function __construct(
        private readonly HistoricReleaseDestinationGuard $destinations,
    ) {}

    public function inspect(string $disk, string $path): ?HistoricReleaseObject
    {
        $filesystem = $this->disk($disk);

        if (! $filesystem->exists($path)) {
            return null;
        }

        return new HistoricReleaseObject(
            disk: $disk,
            path: $path,
            size: (int) $filesystem->size($path),
            sha256: $this->hash($filesystem, $path),
            receipt: $this->receipt($disk, $path),
        );
    }

    public function createIfAbsent(string $disk, string $path, mixed $stream): HistoricReleaseObject
    {
        $this->destinations->assertWritable($disk);

        $created = $this->driver($disk) === 'local'
            ? $this->createLocal($disk, $path, $stream)
            : $this->createRemote($disk, $path, $stream);

        if ($created) {
            $filesystem = $this->disk($disk);

            return new HistoricReleaseObject(
                disk: $disk,
                path: $path,
                size: (int) $filesystem->size($path),
                sha256: $this->hash($filesystem, $path),
                receipt: $this->receipt($disk, $path),
                createdByThisAttempt: true,
            );
        }

        $existing = $this->inspect($disk, $path);

        if (! $existing instanceof HistoricReleaseObject) {
            /**
             * The create was refused because the destination was present, and it
             * is gone by the time we look. Someone else is writing this path
             * concurrently and this attempt has no claim to it.
             */
            throw new RuntimeException("Release destination {$disk}:{$path} is being written by another process.");
        }

        return $existing;
    }

    public function verify(string $disk, string $path, int $size, string $sha256): HistoricReleaseObject
    {
        $object = $this->inspect($disk, $path);

        if (! $object instanceof HistoricReleaseObject) {
            throw new RuntimeException("Released asset is absent from its destination: {$disk}:{$path}.");
        }

        if (! $object->matches($size, $sha256)) {
            throw new RuntimeException("Released asset failed verification at its destination: {$disk}:{$path}.");
        }

        return $object;
    }

    /**
     * False everywhere.
     *
     * Spaces has no bucket versioning and silently ignores `IfMatch` on
     * `DeleteObject`, so a "conditional" delete deletes whatever is there. The
     * local store refuses for the same answer rather than a different one: a
     * fake that could delete exactly would certify a capability production
     * lacks, and the compensation path built on it would pass every test and
     * still destroy the winner's bytes.
     */
    public function supportsExactVersionDelete(string $disk): bool
    {
        return false;
    }

    public function deleteExactVersion(HistoricReleaseObject $object): void
    {
        throw new RuntimeException(
            "Exact-version deletion is unavailable for {$object->disk}; a failed release object is retained as an "
            .'orphan for operator reconciliation, never deleted by path.'
        );
    }

    /** @param resource $stream */
    private function createLocal(string $disk, string $path, mixed $stream): bool
    {
        $filesystem = $this->disk($disk);
        $absolute = $filesystem->path($path);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Release destination directory cannot be created: {$directory}.");
        }

        /** `x` is O_CREAT|O_EXCL: the create fails rather than truncating. */
        $handle = @fopen($absolute, 'x');

        if ($handle === false) {
            return false;
        }

        try {
            if (stream_copy_to_stream($stream, $handle) === false) {
                throw new RuntimeException("Release destination could not be written: {$disk}:{$path}.");
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /** @param resource $stream */
    private function createRemote(string $disk, string $path, mixed $stream): bool
    {
        $filesystem = $this->disk($disk);
        /**
         * The raw client, not the facade. Flysystem's AVAILABLE_OPTIONS omits
         * both conditional headers, so `Storage::put(..., ['IfNoneMatch' => '*'])`
         * silently sends an unconditional write.
         */
        $client = method_exists($filesystem, 'getClient') ? $filesystem->getClient() : null;

        if (! $client instanceof S3Client) {
            throw new RuntimeException("Release destination disk '{$disk}' exposes no conditional-create client.");
        }

        $configuration = config("filesystems.disks.{$disk}", []);
        $bucket = is_array($configuration) ? ($configuration['bucket'] ?? null) : null;

        if (! is_string($bucket) || $bucket === '') {
            throw new RuntimeException("Release destination disk '{$disk}' names no bucket.");
        }

        try {
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $this->remoteKey($configuration, $path),
                'Body' => $stream,
                /** Measured as genuinely enforced: a present key returns 412. */
                'IfNoneMatch' => '*',
            ]);
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 412 || $exception->getAwsErrorCode() === 'PreconditionFailed') {
                return false;
            }

            throw new RuntimeException(
                "Release destination {$disk}:{$path} refused the conditional create: {$exception->getAwsErrorCode()}.",
                previous: $exception,
            );
        }

        return true;
    }

    /** @param array<string, mixed>|mixed $configuration */
    private function remoteKey(mixed $configuration, string $path): string
    {
        $prefix = is_array($configuration) ? ($configuration['root'] ?? $configuration['prefix'] ?? null) : null;

        return is_string($prefix) && trim($prefix, '/') !== ''
            ? trim($prefix, '/').'/'.ltrim($path, '/')
            : ltrim($path, '/');
    }

    /**
     * The provider's own identifier for the stored bytes where it has one, and
     * the content hash where it does not. Never a version id, because none of
     * these stores issues one.
     */
    private function receipt(string $disk, string $path): ?string
    {
        try {
            $checksum = $this->disk($disk)->checksum($path, ['checksum_algo' => 'md5']);

            return is_string($checksum) && $checksum !== '' ? $checksum : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hash(FilesystemAdapter $filesystem, string $path): string
    {
        $stream = $filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Release asset cannot be hashed: {$path}.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function driver(string $disk): string
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException("Release destination disk '{$disk}' is not configured.");
        }

        return $driver;
    }

    private function disk(string $disk): FilesystemAdapter
    {
        return Storage::disk($disk);
    }
}
