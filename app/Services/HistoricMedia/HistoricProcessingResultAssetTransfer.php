<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportResourceIdentity;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricProcessingResultAssetTransfer
{
    public function __construct(
        private readonly HistoricImportResourceIdentity $resources,
    ) {}

    /**
     * Return the resolved storage identities used by the operation token.
     * Credentials and absolute local roots are intentionally reduced to stable
     * fingerprints; the token must distinguish disks without becoming a secret
     * or a portable local path carrier.
     *
     * The reduction itself lives in {@see HistoricImportResourceIdentity} so
     * the guard's anchors and this token cannot drift into describing the same
     * disk two different ways.
     *
     * @return array<string, array<string, string|null>>
     */
    public function storageIdentity(): array
    {
        return [
            'staging' => $this->resources->diskIdentity($this->stagingName()),
            'production' => $this->resources->diskIdentity($this->targetDiskName()),
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
        return $this->copy($assets, $destinations, requireOperationOwnedPaths: true);
    }

    /**
     * Copy direct pipeline output with the pass-through verification budget.
     *
     * The source and a new destination are checked by exact byte size. An
     * already-existing destination is the only case that reads both sides for
     * a hash comparison, which distinguishes an identical replay from a
     * same-size conflict without adding a routine hash traversal to promotion.
     *
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    public function copyPipelineAssetsToDestinations(array $assets, array $destinations): array
    {
        return $this->copyPipeline($assets, $destinations);
    }

    /**
     * Recheck direct pipeline destinations after their database rows have been
     * bound, before the verified staging copies may be reclaimed.
     *
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     */
    public function verifyPipelineDestinations(array $assets, array $destinations): void
    {
        $target = Storage::disk($this->targetDiskName());

        foreach ($assets as $asset) {
            $size = $this->pipelineSize($asset);

            foreach ($this->roles($asset) as $role) {
                $path = $destinations[$role] ?? null;

                if (! is_string($path)) {
                    throw new RuntimeException("No production path was allocated for asset role {$role}.");
                }

                $this->guardPath($path);
                $this->verifyPipelineDestinationAtPath($target, $path, $size);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    private function copy(array $assets, array $destinations, bool $requireOperationOwnedPaths): array
    {
        $source = $this->stagingDisk();
        $target = Storage::disk($this->targetDiskName());
        $created = [];

        try {
            foreach ($assets as $asset) {
                $this->verify($source, $asset);

                foreach ($this->roles($asset) as $role) {
                    $targetPath = $destinations[$role] ?? null;

                    if (! is_string($targetPath)) {
                        throw new RuntimeException("No production path was allocated for asset role {$role}.");
                    }

                    $this->guardPath($targetPath);

                    if ($requireOperationOwnedPaths) {
                        $this->assertOperationOwnedProductionPath($targetPath);
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
     * Direct pipeline copies use their canonical relative paths and database
     * operation binding instead of Bundle A's operation-owned destination key.
     *
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    private function copyPipeline(array $assets, array $destinations): array
    {
        $source = $this->stagingDisk();
        $target = Storage::disk($this->targetDiskName());
        $created = [];

        try {
            foreach ($assets as $asset) {
                $sourcePath = $this->pipelinePath($asset);
                $size = $this->pipelineSize($asset);
                $this->guardPath($sourcePath);
                $this->verifyPipelineSourceAtPath($source, $sourcePath, $size);

                foreach ($this->roles($asset) as $role) {
                    $targetPath = $destinations[$role] ?? null;

                    if (! is_string($targetPath)) {
                        throw new RuntimeException("No production path was allocated for asset role {$role}.");
                    }

                    $this->guardPath($targetPath);

                    if ($target->exists($targetPath)) {
                        $this->verifyExistingPipelineDestination(
                            $source,
                            $sourcePath,
                            $target,
                            $targetPath,
                            $size,
                        );

                        continue;
                    }

                    $stream = $source->readStream($sourcePath);

                    if (! is_resource($stream)) {
                        throw new RuntimeException("Unable to open verified asset {$sourcePath} for copying.");
                    }

                    try {
                        $created[] = $targetPath;

                        if (! $target->writeStream($targetPath, $stream)) {
                            throw new RuntimeException("Unable to copy verified asset {$sourcePath} to {$targetPath}.");
                        }
                    } finally {
                        fclose($stream);
                    }

                    $this->verifyPipelineDestinationAtPath($target, $targetPath, $size);
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanupPipelineDestinations($created);

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
        $target = Storage::disk($this->targetDiskName());

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
                Storage::disk($this->targetDiskName()),
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
        $target = Storage::disk($this->targetDiskName());

        foreach ($paths as $path) {
            $this->assertOperationOwnedProductionPath($path);
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
    private function pipelinePath(array $asset): string
    {
        $path = $asset['path'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Historic pipeline asset has no path.');
        }

        return $path;
    }

    /** @param array<string, mixed> $asset */
    private function pipelineSize(array $asset): int
    {
        $size = $asset['size'] ?? null;

        if (! is_int($size) || $size < 0) {
            throw new RuntimeException('Historic pipeline asset has no valid byte size.');
        }

        return $size;
    }

    private function verifyPipelineSourceAtPath(
        FilesystemAdapter $disk,
        string $path,
        int $size,
    ): void {
        if (! $disk->exists($path)) {
            throw new RuntimeException("Historic pipeline asset is missing: {$path}.");
        }

        if ($disk->size($path) !== $size) {
            throw new RuntimeException("Historic pipeline asset {$path} has an unexpected byte size.");
        }
    }

    private function verifyExistingPipelineDestination(
        FilesystemAdapter $source,
        string $sourcePath,
        FilesystemAdapter $target,
        string $targetPath,
        int $size,
    ): void {
        if ($target->size($targetPath) !== $size) {
            throw new RuntimeException("Historic pipeline destination {$targetPath} differs in byte size.");
        }

        if (! hash_equals($this->hash($source, $sourcePath), $this->hash($target, $targetPath))) {
            throw new RuntimeException(
                "Historic pipeline asset {$sourcePath} differs from existing destination {$targetPath}."
            );
        }
    }

    private function verifyPipelineDestinationAtPath(
        FilesystemAdapter $disk,
        string $path,
        int $size,
    ): void {
        if (! $disk->exists($path)) {
            throw new RuntimeException("Historic pipeline destination is missing: {$path}.");
        }

        if ($disk->size($path) !== $size) {
            throw new RuntimeException("Historic pipeline destination {$path} has an unexpected byte size.");
        }
    }

    /** @param list<string> $paths */
    private function cleanupPipelineDestinations(array $paths): void
    {
        $target = Storage::disk($this->targetDiskName());

        foreach ($paths as $path) {
            $this->guardPath($path);
            $target->delete($path);
        }
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
        $production = $this->targetDiskName();

        if ($staging === '' || $staging === $production) {
            throw new RuntimeException('Historic staging and production media disks must be distinct for import.');
        }

        return Storage::disk($staging);
    }

    private function stagingName(): string
    {
        return (string) config('media-processing.storage.historic_staging_disk');
    }

    /**
     * The disk an import copies into: quarantine, not the public sermon disk.
     * That the two are distinct and private is enforced by
     * {@see HistoricImportProductionGuard}, before a
     * production run starts, and again by the release step. Test and rehearsal
     * fixtures collapse them deliberately when the subject is bundle mechanics.
     */
    public function targetDiskName(): string
    {
        $disk = config('media-processing.storage.historic_quarantine_disk');

        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('Historic quarantine media disk is not configured.');
        }

        return trim($disk);
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

    private function assertOperationOwnedProductionPath(string $path): void
    {
        if (app()->isProduction() && ! str_starts_with($path, 'historic-import/historic-')) {
            throw new RuntimeException('Production historic assets require an immutable operation-owned destination key.');
        }
    }
}
