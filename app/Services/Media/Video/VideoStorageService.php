<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\HistoricStagingContext;
use App\Exceptions\HistoricSourceMountException;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\TempDiskSpace;
use App\Support\Path;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class VideoStorageService
{
    private const HISTORIC_DERIVATIVE_PREFIX = 'temp/historic-import/';

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
    ) {}

    /**
     * Store an uploaded video file temporarily for processing.
     *
     * @param  UploadedFile  $file  The uploaded video file
     * @param  int|null  $expectedSize  Approved historic source size, when the copy must be checked
     * @return array{
     *     temp_path: string,
     *     full_path: string,
     *     original_filename: string,
     *     file_size: int,
     *     mime_type: string|null
     * }
     *
     * @throws \RuntimeException If the file cannot be stored on the temp disk
     */
    public function storeUploadedVideo(UploadedFile $file, ?int $expectedSize = null): array
    {
        $disk = $this->tempDisk();
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $tempPath = 'livestream/temp/'.$filename;

        try {
            $storedPath = $file->storeAs('livestream/temp', $filename, $disk);
            if ($storedPath === false) {
                throw new \RuntimeException('Failed to store uploaded video on temp disk.');
            }

            $fullPath = Storage::disk($disk)->path($storedPath);
            $storedSize = $this->exactFileSize($fullPath);

            if ($expectedSize !== null && $storedSize !== $expectedSize) {
                throw new HistoricSourceMountException(
                    "Historic video source copy did not produce the approved byte size: {$storedPath}."
                );
            }

            $fileSize = $expectedSize ?? $file->getSize();
            $fileSize = is_int($fileSize) ? $fileSize : 0;

            Log::info('Video uploaded and stored temporarily', [
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $fileSize,
            ]);

            return [
                'temp_path' => $storedPath,
                'full_path' => $fullPath,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $fileSize,
                'mime_type' => $this->mimeType($file, $fullPath, $expectedSize !== null),
            ];

        } catch (Throwable $e) {
            if ($expectedSize !== null) {
                $this->deleteUniqueStoredPath($disk, $tempPath);

                if (! $e instanceof HistoricSourceMountException) {
                    $e = new HistoricSourceMountException(
                        'Historic video source could not be copied to the operation staging area.',
                        previous: $e,
                    );
                }
            }

            Log::error('Failed to store uploaded video', [
                'error' => $e->getMessage(),
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    /**
     * Adopt a derivative that was already written inside the active historic operation staging
     * root. Ordinary uploads cannot use this path: every identity and path binding is checked
     * before the processing log is created, and no second source-to-staging copy is performed.
     *
     * @param  array<string, mixed>  $derivative
     * @param  array<string, mixed>  $historicImport
     * @return array{
     *     temp_path: string,
     *     full_path: string,
     *     original_filename: string,
     *     file_size: int,
     *     mime_type: string|null
     * }
     */
    public function adoptHistoricStagedVideo(
        UploadedFile $file,
        array $derivative,
        array $historicImport,
        string $dedupKey,
    ): array {
        $context = $this->stagingContextRegistry->activeContext();
        $operationId = $historicImport['operation_id'] ?? null;
        $manifestItemKey = $historicImport['manifest_item_key'] ?? null;
        $stagedPath = $derivative['path'] ?? null;
        $expectedSize = $derivative['size'] ?? null;
        $sources = array_key_exists('sources', $historicImport) ? $historicImport['sources'] : null;

        if (
            ! $context instanceof HistoricStagingContext
            || $dedupKey === ''
            || ! is_string($operationId)
            || $operationId === ''
            || ! is_string($manifestItemKey)
            || $manifestItemKey === ''
            || ! is_string($stagedPath)
            || ! is_int($expectedSize)
            || $expectedSize < 0
            || ($historicImport['staging_context'] ?? null) !== $context->toArray()
            || ($historicImport['job_key'] ?? null) !== $dedupKey
            || ($historicImport['sha256_basis'] ?? null) !== 'approved_manifest_not_reverified_at_dispatch'
            || ! is_array($sources)
            || ! array_is_list($sources)
            || $sources === []
            || ! $this->hasApprovedSourceMetadata($sources)
            || ($derivative['operation_id'] ?? null) !== $operationId
            || ($derivative['manifest_item_key'] ?? null) !== $manifestItemKey
            || ($derivative['dedup_key'] ?? null) !== $dedupKey
        ) {
            throw new \RuntimeException('Historic derivative adoption is not bound to the active operation identity.');
        }

        if ($context->stagingDisk !== $this->tempDisk()
            || Path::isUnsafe($stagedPath)
            || ! str_starts_with($stagedPath, self::HISTORIC_DERIVATIVE_PREFIX)) {
            throw new \RuntimeException('Historic derivative path is outside the operation staging allow-list.');
        }

        $disk = Storage::disk($context->stagingDisk);
        $fullPath = $disk->path($stagedPath);
        $root = $disk->path('');
        $realRoot = realpath($root);
        $realPath = realpath($fullPath);
        $filePath = $file->getRealPath();

        if (
            ! is_string($realRoot)
            || ! is_string($realPath)
            || ! is_string($filePath)
            || ! $this->isWithinRoot($realPath, $realRoot)
            || $this->containsSymlink($root, $stagedPath)
            || realpath($filePath) !== $realPath
        ) {
            throw new \RuntimeException('Historic derivative is not a regular file in the operation staging root.');
        }

        $storedSize = $this->exactFileSize($realPath);

        if ($storedSize !== $expectedSize) {
            throw new \RuntimeException('Historic derivative does not have its approved byte size.');
        }

        return [
            'temp_path' => $stagedPath,
            'full_path' => $realPath,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $storedSize,
            'mime_type' => $this->mimeType($file, $realPath, true),
        ];
    }

    /**
     * Delete a run's working copies, and nothing else.
     *
     * Deletion is confined to the disks that hold working copies — the temp disk
     * and, during a historic pass, the private staging disk. It used to fall
     * through to `file_exists()` and a raw `unlink()` on whatever string it was
     * given, which had two ways to reach the wrong bytes: an absolute path could
     * name anything the container can write, including the irreplaceable source
     * corpus, and a disk-relative path resolves against the working directory, so
     * `sermons/video/x.mp4` meant a file in the project root. Neither was ever
     * needed — every path reaching here is stored disk-relative.
     *
     * Quarantine is deliberately absent from the allow-list. Promoted assets are
     * durable output, not working copies, and processing cleanup has no business
     * deleting them (plan §Phase 5, item 6).
     *
     * @param  array<int, string>  $filePaths
     */
    public function cleanupTemporaryFiles(array $filePaths): void
    {
        $deletedCount = 0;
        $refused = [];

        foreach ($filePaths as $filePath) {
            if ($filePath === '') {
                continue;
            }

            if (Path::isUnsafe($filePath)) {
                $refused[] = $filePath;

                continue;
            }

            foreach ($this->workingCopyDisks() as $disk) {
                try {
                    if (! Storage::disk($disk)->exists($filePath)) {
                        continue;
                    }

                    Storage::disk($disk)->delete($filePath);
                    $deletedCount++;
                    Log::debug('Deleted working copy', ['file' => $filePath, 'disk' => $disk]);

                    break;
                } catch (\Exception $e) {
                    Log::warning('Failed to delete working copy', [
                        'file' => $filePath,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($refused !== []) {
            Log::warning('Refused to delete paths that are not disk-relative working copies', [
                'files' => $refused,
            ]);
        }

        Log::info('Temporary files cleaned up', [
            'file_count' => count($filePaths),
            'deleted_count' => $deletedCount,
            'refused_count' => count($refused),
        ]);
    }

    /**
     * The disks a working copy may live on, most likely first.
     *
     * @return list<string>
     */
    private function workingCopyDisks(): array
    {
        $disks = [$this->tempDisk()];
        $staging = (string) config('media-processing.storage.historic_staging_disk', '');
        $quarantine = (string) config('media-processing.storage.historic_quarantine_disk', '');

        if ($staging !== '' && $staging !== $quarantine && ! in_array($staging, $disks, true)) {
            $disks[] = $staging;
        }

        return $disks;
    }

    /**
     * Verify if enough local disk space is available for processing.
     *
     * @param  int  $requiredBytes  The estimated storage needed
     * @return bool True if sufficient space exists
     */
    public function validateStorageSpace(int $requiredBytes): bool
    {
        if (TempDiskSpace::checksDisabled()) {
            return true;
        }

        try {
            $available = disk_free_space(Storage::disk($this->tempDisk())->path(''));

            return $available > ($requiredBytes * 2);
        } catch (\Exception $e) {
            Log::warning('Could not validate storage space', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Check whether the source video for a processing run is still accessible.
     *
     * Verifies existence either on the configured temp disk or as an
     * absolute filesystem path.
     *
     * @param  string  $sourceFilePath  The path to check
     * @return bool True if the source video exists
     */
    public function sourceVideoExistsForPath(string $sourceFilePath): bool
    {
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        return Storage::disk($tempDisk)->exists($sourceFilePath)
            || file_exists($sourceFilePath);
    }

    /**
     * The stored file's MIME type.
     *
     * A historic dispatch reads it from the staged copy, not the UploadedFile:
     * that file is the archive original on a removable drive, and detection
     * opens it. The staged copy is byte-identical (its size is verified against
     * the approved manifest before this returns), so the answer is the same and
     * the archive is read exactly once, by the copy itself. Ordinary uploads keep
     * reading the upload, which is already local scratch.
     */
    private function mimeType(UploadedFile $file, string $storedFullPath, bool $preferStoredCopy): ?string
    {
        if (! $preferStoredCopy) {
            return $file->getMimeType();
        }

        $detected = @mime_content_type($storedFullPath);

        return $detected === false ? $file->getMimeType() : $detected;
    }

    private function tempDisk(): string
    {
        return (string) config('media-processing.storage.temp_disk', 'local');
    }

    private function exactFileSize(string $path): ?int
    {
        clearstatcache(true, $path);

        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        return is_int($size) ? $size : null;
    }

    /**
     * @param  array<mixed>  $sources
     */
    private function hasApprovedSourceMetadata(array $sources): bool
    {
        foreach ($sources as $source) {
            if (
                ! is_array($source)
                || ! is_string($source['path'] ?? null)
                || $source['path'] === ''
                || str_starts_with($source['path'], '/')
                || str_contains($source['path'], '\\')
                || in_array('..', explode('/', $source['path']), true)
                || ! is_int($source['size'] ?? null)
                || $source['size'] < 0
                || ! is_string($source['sha256'] ?? null)
                || preg_match('/\\A[0-9a-f]{64}\\z/', $source['sha256']) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function deleteUniqueStoredPath(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $cleanupException) {
            Log::warning('Failed to delete incomplete historic staging copy', [
                'path' => $path,
                'disk' => $disk,
                'error' => $cleanupException->getMessage(),
            ]);
        }
    }

    private function containsSymlink(string $root, string $relativePath): bool
    {
        if (is_link($root)) {
            return true;
        }

        $path = rtrim($root, DIRECTORY_SEPARATOR);

        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
