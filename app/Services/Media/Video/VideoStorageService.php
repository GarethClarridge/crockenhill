<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Services\Media\TempDiskSpace;
use App\Support\Path;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoStorageService
{
    /**
     * Store an uploaded video file temporarily for processing.
     *
     * @param  UploadedFile  $file  The uploaded video file
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
    public function storeUploadedVideo(UploadedFile $file): array
    {
        try {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $tempPath = 'livestream/temp/'.$filename;

            $storedPath = $file->storeAs('livestream/temp', $filename, $this->tempDisk());
            if ($storedPath === false) {
                throw new \RuntimeException('Failed to store uploaded video on temp disk.');
            }

            $fullPath = Storage::disk($this->tempDisk())->path($storedPath);

            Log::info('Video uploaded and stored temporarily', [
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize(),
            ]);

            return [
                'temp_path' => $storedPath,
                'full_path' => $fullPath,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to store uploaded video', [
                'error' => $e->getMessage(),
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw $e;
        }
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

    private function tempDisk(): string
    {
        return (string) config('media-processing.storage.temp_disk', 'local');
    }
}
