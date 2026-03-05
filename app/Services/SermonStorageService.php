<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;

class SermonStorageService
{
    private const STATS_CHUNK_SIZE = 100;

    /**
     * Get file information for a sermon based on its storage pattern
     *
     * @return array{type: string, disk: string, path: string, original_path: string}
     */
    public function getSermonFileInfo(Sermon $sermon): array
    {
        // Security check: Prevent path traversal
        if (str_contains($sermon->audio_file_path, '..')) {
            throw new \InvalidArgumentException('Invalid audio file path: Path traversal detected.');
        }

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
     * Get the video URL for a sermon
     */
    public function getVideoUrl(Sermon $sermon): ?string
    {
        if (! $sermon->video_file_path) {
            return null;
        }

        return Storage::disk(config('media-processing.storage.sermon_disk'))->url($sermon->video_file_path);
    }

    /**
     * Get the thumbnail URL for a sermon
     */
    public function getThumbnailUrl(Sermon $sermon): ?string
    {
        if (! $sermon->thumbnail_file_path) {
            return null;
        }

        $disk = config('thumbnail-generation.storage.disk', 'public');

        return Storage::disk($disk)->url($sermon->thumbnail_file_path);
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

            if (! is_string($content)) {
                return false;
            }

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
     *
     * @return array<string, mixed>
     */
    public function getStorageStats(): array
    {
        $stats = [
            'total_sermons' => Sermon::count(),
            'patterns' => [
                'legacy' => 0,
                'storage' => 0,
                'processing' => 0,
            ],
            'disks' => [],
            'total_size' => 0,
            'missing_files' => 0,
        ];

        Sermon::query()
            ->select(['id', 'audio_file_path', 'filetype'])
            ->chunk(self::STATS_CHUNK_SIZE, function ($sermons) use (&$stats) {
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

                    try {
                        $size = Storage::disk($disk)->size($info['path']);
                        $stats['disks'][$disk]['size'] += $size;
                        $stats['total_size'] += $size;
                    } catch (\Exception $e) {
                        $stats['disks'][$disk]['missing']++;
                        $stats['missing_files']++;
                    }
                }
            });

        return $stats;
    }
}
