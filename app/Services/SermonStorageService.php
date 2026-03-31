<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SermonStorageService
{
    private const STATS_CHUNK_SIZE = 100;

    private readonly string $legacyDisk;

    private readonly string $sermonDisk;

    private readonly string $thumbnailDisk;

    private readonly ?string $cdnEndpoint;

    public function __construct()
    {
        /** @var string $legacyDisk */
        $legacyDisk = config('media-processing.storage.legacy_disk', 'public');
        $this->legacyDisk = $legacyDisk;

        /** @var string $sermonDisk */
        $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->sermonDisk = $sermonDisk;

        /** @var string $thumbnailDisk */
        $thumbnailDisk = config('thumbnail-generation.storage.disk', 'public');
        $this->thumbnailDisk = $thumbnailDisk;

        /** @var ?string $cdnEndpoint */
        $cdnEndpoint = config('filesystems.disks.do_spaces.cdn_endpoint');
        $this->cdnEndpoint = $cdnEndpoint;
    }

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

        // Private files stored on the local disk (unreachable via the public/storage symlink)
        if (str_starts_with($sermon->audio_file_path, 'private/')) {
            return [
                'type' => 'private',
                'disk' => 'local',
                'path' => $sermon->audio_file_path,
                'original_path' => $sermon->audio_file_path,
            ];
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
                'disk' => $this->legacyDisk,
                'path' => "legacy/sermons/{$filename}",
                'original_path' => "media/sermons/{$filename}",
            ];
        } elseif (str_contains($sermon->audio_file_path, '/')) {
            // Newer Laravel storage pattern
            return [
                'type' => 'storage',
                'disk' => $this->sermonDisk,
                'path' => $sermon->audio_file_path,
                'original_path' => $sermon->audio_file_path,
            ];
        } else {
            // Current media processing pattern
            return [
                'type' => 'processing',
                'disk' => $this->sermonDisk,
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

        return Storage::disk($this->sermonDisk)->url($sermon->video_file_path);
    }

    /**
     * Get the thumbnail URL for a sermon
     */
    public function getThumbnailUrl(Sermon $sermon): ?string
    {
        if (! $sermon->thumbnail_file_path) {
            return null;
        }

        $url = Storage::disk($this->thumbnailDisk)->url($sermon->thumbnail_file_path);

        return $this->appendVersion($url, $this->thumbnailVersion($sermon, $sermon->thumbnail_file_path));
    }

    public function getCardThumbnailUrl(Sermon $sermon): ?string
    {
        $cardThumbnailPath = $sermon->plain_thumbnail_file_path;

        if (! is_string($cardThumbnailPath) || $cardThumbnailPath === '') {
            return null;
        }

        $disk = str_starts_with($cardThumbnailPath, 'private/')
            ? 'local'
            : $this->thumbnailDisk;

        return $this->appendVersion(
            Storage::disk($disk)->url($cardThumbnailPath),
            $this->thumbnailVersion($sermon, $cardThumbnailPath),
        );
    }

    /**
     * Get the public URL for a sermon file
     */
    public function getPublicUrl(Sermon $sermon): string
    {
        $info = $this->getSermonFileInfo($sermon);

        // Use CDN for public files if available
        if ($info['disk'] === 'do_spaces' && $this->cdnEndpoint) {
            return $this->appendVersion($this->cdnEndpoint.'/'.$info['path'], $this->audioVersion($sermon));
        }

        return $this->appendVersion(Storage::disk($info['disk'])->url($info['path']), $this->audioVersion($sermon));
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
        return $this->fileMetadata($sermon)['size'];
    }

    /**
     * Get the file's last modified time.
     *
     * Performance Optimization: Avoids redundant exists() check before lastModified() call
     * to reduce remote storage network round-trips.
     */
    public function getLastModified(Sermon $sermon): ?int
    {
        return $this->fileMetadata($sermon)['last_modified'];
    }

    public function clearCachedMetadata(Sermon $sermon): void
    {
        Cache::forget($this->fileMetadataCacheKey($sermon));
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
                'private' => 0,
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

    private function appendVersion(string $url, string $version): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}v={$version}";
    }

    private function audioVersion(Sermon $sermon): string
    {
        return sha1(implode('|', [
            'audio',
            $sermon->audio_file_path,
            $sermon->updated_at?->getTimestamp() ?? 0,
        ]));
    }

    /**
     * @return array{last_modified: ?int, size: ?int}
     */
    private function fileMetadata(Sermon $sermon): array
    {
        /** @var array{last_modified: ?int, size: ?int} */
        return Cache::rememberForever($this->fileMetadataCacheKey($sermon), function () use ($sermon): array {
            $info = $this->getSermonFileInfo($sermon);

            try {
                return [
                    'last_modified' => Storage::disk($info['disk'])->lastModified($info['path']),
                    'size' => Storage::disk($info['disk'])->size($info['path']),
                ];
            } catch (\Exception $e) {
                return [
                    'last_modified' => null,
                    'size' => null,
                ];
            }
        });
    }

    private function fileMetadataCacheKey(Sermon $sermon): string
    {
        return 'sermon_file_metadata_'.sha1(implode('|', [
            $sermon->id,
            $sermon->audio_file_path,
            $sermon->updated_at?->getTimestamp() ?? 0,
        ]));
    }

    private function thumbnailVersion(Sermon $sermon, string $thumbnailPath): string
    {
        return sha1(implode('|', [
            'thumbnail',
            $thumbnailPath,
            $sermon->thumbnail_generated_at?->getTimestamp()
                ?? $sermon->updated_at?->getTimestamp()
                ?? 0,
        ]));
    }
}
