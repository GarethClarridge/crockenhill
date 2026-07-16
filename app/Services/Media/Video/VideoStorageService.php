<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

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
     * @param  array<int, string>  $filePaths
     */
    public function cleanupTemporaryFiles(array $filePaths): void
    {
        $deletedCount = 0;

        // Clean up specific files passed in the array
        foreach ($filePaths as $filePath) {
            if ($filePath === '') {
                continue;
            }

            try {
                // Try temp disk first
                if (Storage::disk($this->tempDisk())->exists($filePath)) {
                    Storage::disk($this->tempDisk())->delete($filePath);
                    $deletedCount++;
                    Log::debug('Deleted temp file', ['file' => $filePath, 'disk' => $this->tempDisk()]);
                }
                // Check if it's an absolute path (for local files)
                elseif (file_exists($filePath)) {
                    unlink($filePath);
                    $deletedCount++;
                    Log::debug('Deleted local temp file', ['file' => $filePath]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete temp file', [
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Temporary files cleaned up', [
            'file_count' => count($filePaths),
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Verify if enough local disk space is available for processing.
     *
     * @param  int  $requiredBytes  The estimated storage needed
     * @return bool True if sufficient space exists
     */
    public function validateStorageSpace(int $requiredBytes): bool
    {
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
