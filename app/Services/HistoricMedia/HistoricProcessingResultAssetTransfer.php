<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricProcessingResultAssetTransfer
{
    /**
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $assets
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
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $assets
     * @return list<string>
     */
    public function copyCreateOnly(array $assets): array
    {
        $destinations = collect($assets)->mapWithKeys(
            fn (array $asset): array => [$asset['role'] => $asset['path']],
        )->all();

        return $this->copyToDestinations($assets, $destinations);
    }

    /**
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $assets
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    public function copyToDestinations(array $assets, array $destinations): array
    {
        $source = $this->stagingDisk();
        $target = Storage::disk((string) config('media-processing.storage.sermon_disk'));
        $created = [];

        foreach ($assets as $asset) {
            $this->verify($source, $asset);
            $targetPath = $destinations[$asset['role']] ?? null;

            if (! is_string($targetPath)) {
                $this->cleanup($created);
                throw new RuntimeException("No production path was allocated for asset role {$asset['role']}.");
            }

            if ($target->exists($targetPath)) {
                $this->verifyAtPath($target, $targetPath, $asset);

                continue;
            }

            $contents = $source->get($asset['path']);

            if (! is_string($contents) || ! $target->put($targetPath, $contents)) {
                $this->cleanup($created);
                throw new RuntimeException("Unable to copy verified asset {$asset['path']} to {$targetPath}.");
            }

            $created[] = $targetPath;
        }

        return $created;
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
     * @param  array{role: string, path: string, size: int, sha256: string}  $asset
     */
    private function verify(FilesystemAdapter $disk, array $asset): void
    {
        $this->verifyAtPath($disk, $asset['path'], $asset);
    }

    /** @param array{role: string, path: string, size: int, sha256: string} $asset */
    private function verifyAtPath(FilesystemAdapter $disk, string $path, array $asset): void
    {
        if (! $disk->exists($path)) {
            throw new RuntimeException("Verified bundle asset is missing: {$asset['path']}.");
        }

        $contents = $disk->get($path);

        if (
            ! is_string($contents)
            || strlen($contents) !== $asset['size']
            || ! hash_equals($asset['sha256'], hash('sha256', $contents))
        ) {
            throw new RuntimeException("Verified bundle asset differs from its manifest: {$asset['path']}.");
        }
    }

    private function stagingDisk(): FilesystemAdapter
    {
        $staging = (string) config('media-processing.storage.historic_staging_disk');
        $production = (string) config('media-processing.storage.sermon_disk');

        if ($staging === '' || $staging === $production) {
            throw new RuntimeException('Historic staging and production media disks must be distinct for import.');
        }

        return Storage::disk($staging);
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
