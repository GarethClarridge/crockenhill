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
        $source = $this->stagingDisk();
        $target = Storage::disk((string) config('media-processing.storage.sermon_disk'));
        $created = [];

        foreach ($assets as $asset) {
            $this->verify($source, $asset);

            if ($target->exists($asset['path'])) {
                $this->verify($target, $asset);

                continue;
            }

            $contents = $source->get($asset['path']);

            if (! is_string($contents) || ! $target->put($asset['path'], $contents)) {
                $this->cleanup($created);
                throw new RuntimeException("Unable to copy verified asset {$asset['path']}.");
            }

            $created[] = $asset['path'];
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
        if (! $disk->exists($asset['path'])) {
            throw new RuntimeException("Verified bundle asset is missing: {$asset['path']}.");
        }

        $contents = $disk->get($asset['path']);

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
