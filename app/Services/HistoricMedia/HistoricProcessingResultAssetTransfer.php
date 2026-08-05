<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricProcessingResultAssetTransfer
{
    /**
     * Return the resolved storage identities used by the operation token.
     * Credentials and absolute local roots are intentionally reduced to stable
     * fingerprints; the token must distinguish disks without becoming a secret
     * or a portable local path carrier.
     *
     * @return array<string, array<string, string|null>>
     */
    public function storageIdentity(): array
    {
        return [
            'staging' => $this->diskIdentity($this->stagingName()),
            'production' => $this->diskIdentity((string) config('media-processing.storage.sermon_disk')),
        ];
    }

    /**
     * @param  list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>  $assets
     */
    public function verifyStaged(array $assets): void
    {
        $disk = $this->stagingDisk();

        foreach ($assets as $asset) {
            $this->guardPath($asset['path']);
            $this->verify($disk, $asset);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return list<string>
     */
    public function copyCreateOnly(array $assets): array
    {
        $destinations = [];

        foreach ($this->expand($assets) as $asset) {
            $destinations[$asset['role']] = $asset['path'];
        }

        return $this->copyToDestinations($assets, $destinations);
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    public function copyToDestinations(array $assets, array $destinations): array
    {
        $source = $this->stagingDisk();
        $target = Storage::disk((string) config('media-processing.storage.sermon_disk'));
        $created = [];

        try {
            foreach ($assets as $asset) {
                $this->verify($source, $asset);

                foreach ($this->roles($asset) as $role) {
                    $targetPath = $destinations[$role] ?? null;

                    if (! is_string($targetPath)) {
                        throw new RuntimeException("No production path was allocated for asset role {$role}.");
                    }

                    if ($target->exists($targetPath)) {
                        $this->verifyAtPath($target, $targetPath, $asset);

                        continue;
                    }

                    $stream = $source->readStream($asset['path']);

                    if (! is_resource($stream)) {
                        throw new RuntimeException("Unable to open verified asset {$asset['path']} for copying.");
                    }

                    try {
                        $created[] = $targetPath;

                        if (! $target->writeStream($targetPath, $stream)) {
                            throw new RuntimeException("Unable to copy verified asset {$asset['path']} to {$targetPath}.");
                        }
                    } finally {
                        fclose($stream);
                    }

                    $this->verifyAtPath($target, $targetPath, $asset);
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanup($created);

            throw $exception;
        }

        return array_values(array_unique($created));
    }

    /**
     * Verify every logical role against its already-existing production
     * destination. This is deliberately separate from copying: an exact
     * no-op must prove the live bytes are still present rather than trusting
     * the staged manifest or historic metadata.
     *
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     */
    public function verifyDestinations(array $assets, array $destinations): void
    {
        $target = Storage::disk((string) config('media-processing.storage.sermon_disk'));

        foreach ($this->expand($assets) as $asset) {
            $path = $destinations[$asset['role']] ?? null;

            if (! is_string($path)) {
                throw new RuntimeException("No production path was allocated for asset role {$asset['role']}.");
            }

            $this->verifyAtPath($target, $path, $asset);
        }
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    public function destinationMatches(array $asset, string $path): bool
    {
        try {
            $this->verifyAtPath(
                Storage::disk((string) config('media-processing.storage.sermon_disk')),
                $path,
                $asset,
            );

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @param list<string> $paths */
    public function cleanup(array $paths): void
    {
        $target = Storage::disk((string) config('media-processing.storage.sermon_disk'));

        foreach ($paths as $path) {
            $target->delete($path);
        }
    }

    /**
     * Convert the grouped Bundle A manifest into logical role records for
     * destination allocation and legacy callers. No media bytes are loaded.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return list<array{role: string, path: string, size: int, sha256: string, kind: string}>
     */
    public function expand(array $assets): array
    {
        $expanded = [];

        foreach ($assets as $asset) {
            foreach ($this->roles($asset) as $role) {
                $expanded[] = [
                    'role' => $role,
                    'path' => $asset['path'],
                    'size' => $asset['size'],
                    'sha256' => $asset['sha256'],
                    'kind' => $asset['kind'] ?? 'unknown',
                ];
            }
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return list<string>
     */
    public function roles(array $asset): array
    {
        if (is_array($asset['roles'] ?? null)) {
            return array_values(array_filter($asset['roles'], 'is_string'));
        }

        return is_string($asset['role'] ?? null) ? [$asset['role']] : [];
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function verify(FilesystemAdapter $disk, array $asset): void
    {
        $this->verifyAtPath($disk, $asset['path'], $asset);
    }

    /** @param array<string, mixed> $asset */
    private function verifyAtPath(FilesystemAdapter $disk, string $path, array $asset): void
    {
        if (! $disk->exists($path)) {
            throw new RuntimeException("Verified bundle asset is missing: {$asset['path']}.");
        }

        if ($disk->size($path) !== $asset['size'] || ! hash_equals($asset['sha256'], $this->hash($disk, $path))) {
            throw new RuntimeException("Verified bundle asset differs from its manifest: {$asset['path']}.");
        }
    }

    private function hash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Verified bundle asset could not be opened for hashing: {$path}.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function stagingDisk(): FilesystemAdapter
    {
        $staging = $this->stagingName();
        $production = (string) config('media-processing.storage.sermon_disk');

        if ($staging === '' || $staging === $production) {
            throw new RuntimeException('Historic staging and production media disks must be distinct for import.');
        }

        return Storage::disk($staging);
    }

    private function stagingName(): string
    {
        return (string) config('media-processing.storage.historic_staging_disk');
    }

    /** @return array<string, string|null> */
    private function diskIdentity(string $disk): array
    {
        $configuration = config("filesystems.disks.{$disk}", []);

        if (! is_array($configuration)) {
            throw new RuntimeException("Storage disk '{$disk}' is not configured.");
        }

        $root = $configuration['root'] ?? null;
        $prefix = $configuration['prefix'] ?? null;

        return [
            'name' => $disk,
            'driver' => is_string($configuration['driver'] ?? null) ? $configuration['driver'] : null,
            'bucket' => is_string($configuration['bucket'] ?? null) ? $configuration['bucket'] : null,
            'prefix' => is_string($prefix) ? $prefix : null,
            'root_fingerprint' => is_string($root) ? hash('sha256', $root) : null,
        ];
    }

    private function guardPath(string $path): void
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '../')
            || str_contains($path, '\\')
        ) {
            throw new RuntimeException("Unsafe bundle asset path: {$path}.");
        }
    }
}
