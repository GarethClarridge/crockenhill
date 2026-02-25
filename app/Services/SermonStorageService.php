<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;

class SermonStorageService
{
    /**
     * Get file information for a sermon based on its storage pattern
     */
    public function getSermonFileInfo(Sermon $sermon): array
    {
        // Determine which storage pattern this sermon uses
        if ($sermon->filetype && ! str_contains($sermon->audio_file_path, '/')) {
            // Legacy pattern
            // Check if filename already has extension to avoid double extensions
            $filename = $sermon->audio_file_path;
            if (! str_ends_with($filename, ".{$sermon->filetype}")) {
                $filename .= ".{$sermon->filetype}";
            }

            return [
                'type' => 'legacy',
                'disk' => config('media-processing.storage.legacy_disk', 'public'),
                'path' => "legacy/sermons/{$filename}",
                'original_path' => "media/sermons/{$filename}",
            ];
        } elseif (str_contains($sermon->audio_file_path, '/')) {
            // Newer Laravel storage pattern
            return [
                'type' => 'storage',
                'disk' => config('media-processing.storage.sermon_disk', 'public'),
                'path' => $sermon->audio_file_path,
                'original_path' => $sermon->audio_file_path,
            ];
        } else {
            // Current media processing pattern
            return [
                'type' => 'processing',
                'disk' => config('media-processing.storage.sermon_disk', 'public'),
                'path' => $sermon->audio_file_path,
                'original_path' => $sermon->audio_file_path,
            ];
        }
    }

    /**
     * Get the public URL for a sermon file
     */
    public function getPublicUrl(Sermon $sermon): string
    {
        $info = $this->getSermonFileInfo($sermon);

        // Use CDN for public files if available
        $cdnEndpoint = config('filesystems.disks.do_spaces.cdn_endpoint');
        if ($info['disk'] === 'do_spaces' && $cdnEndpoint) {
            return $cdnEndpoint.'/'.$info['path'];
        }

        return Storage::disk($info['disk'])->url($info['path']);
    }

    /**
     * Check if a sermon file exists
     */
    public function fileExists(Sermon $sermon): bool
    {
        $info = $this->getSermonFileInfo($sermon);

        return Storage::disk($info['disk'])->exists($info['path']);
    }

    /**
     * Get the file size for a sermon.
     *
     * Performance Optimization: Avoids redundant exists() check before size() call
     * to reduce remote storage network round-trips.
     */
    public function getFileSize(Sermon $sermon): ?int
    {
        $info = $this->getSermonFileInfo($sermon);

        try {
            return Storage::disk($info['disk'])->size($info['path']);
        } catch (\Exception $e) {
            // File likely doesn't exist or is inaccessible
            return null;
        }
    }

    /**
     * Get the file's last modified time.
     *
     * Performance Optimization: Avoids redundant exists() check before lastModified() call
     * to reduce remote storage network round-trips.
     */
    public function getLastModified(Sermon $sermon): ?int
    {
        $info = $this->getSermonFileInfo($sermon);

        try {
            return Storage::disk($info['disk'])->lastModified($info['path']);
        } catch (\Exception $e) {
            // File likely doesn't exist or is inaccessible
            return null;
        }
    }

    /**
     * Move a sermon file to a different disk
     */
    public function moveFile(Sermon $sermon, string $targetDisk): bool
    {
        $info = $this->getSermonFileInfo($sermon);

        // Don't move if already on target disk
        if ($info['disk'] === $targetDisk) {
            return true;
        }

        // Check if source file exists
        if (! Storage::disk($info['disk'])->exists($info['path'])) {
            return false;
        }

        try {
            // Read content from source disk
            $content = Storage::disk($info['disk'])->get($info['path']);

            // Write to target disk
            Storage::disk($targetDisk)->put($info['path'], $content);

            // Verify the file was written successfully
            if (! Storage::disk($targetDisk)->exists($info['path'])) {
                return false;
            }

            // Optionally delete from source disk (commented out for safety)
            // Storage::disk($info['disk'])->delete($info['path']);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to move sermon file', [
                'sermon_id' => $sermon->id,
                'from_disk' => $info['disk'],
                'to_disk' => $targetDisk,
                'path' => $info['path'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get storage statistics for all sermon files
     */
    public function getStorageStats(): array
    {
        $sermons = Sermon::all();
        $stats = [
            'total_sermons' => $sermons->count(),
            'patterns' => [
                'legacy' => 0,
                'storage' => 0,
                'processing' => 0,
            ],
            'disks' => [],
            'total_size' => 0,
            'missing_files' => 0,
        ];

        foreach ($sermons as $sermon) {
            $info = $this->getSermonFileInfo($sermon);
            $stats['patterns'][$info['type']]++;

            $disk = $info['disk'];
            if (! isset($stats['disks'][$disk])) {
                $stats['disks'][$disk] = [
                    'count' => 0,
                    'size' => 0,
                    'missing' => 0,
                ];
            }

            $stats['disks'][$disk]['count']++;

            if (Storage::disk($disk)->exists($info['path'])) {
                $size = Storage::disk($disk)->size($info['path']);
                $stats['disks'][$disk]['size'] += $size;
                $stats['total_size'] += $size;
            } else {
                $stats['disks'][$disk]['missing']++;
                $stats['missing_files']++;
            }
        }

        return $stats;
    }
}
