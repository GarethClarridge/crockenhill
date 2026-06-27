<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Models\Sermon;
use App\Support\Path;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

class SermonStorageService
{
    use SanitizesLogData;

    private const STATS_CHUNK_SIZE = 100;

    private string $legacyDisk;

    private string $sermonDisk;

    private string $thumbnailDisk;

    private ?string $cdnEndpoint;

    /**
     * @var array<string, string>
     */
    private array $memoizedVersions = [];

    /**
     * @var array<string, string>
     */
    private array $memoizedDiskUrls = [];

    /**
     * @var array<string, array{type: string, disk: string, path: string, original_path: string}>
     */
    private array $memoizedFileInfo = [];

    /**
     * @var array<string, string>
     */
    private array $memoizedThumbnailDisks = [];

    public function __construct()
    {
        $this->refreshConfig();
    }

    /**
     * Clear all cached metadata and disk configuration.
     * Use this in tests to ensure fresh configuration lookups.
     */
    public function clearInternalCaches(): void
    {
        $this->refreshConfig();
        $this->clearCachedMetadata();
        $this->memoizedVersions = [];
        $this->memoizedDiskUrls = [];
        $this->memoizedFileInfo = [];
        $this->memoizedThumbnailDisks = [];
    }

    /**
     * Refresh configuration values from the global config.
     */
    private function refreshConfig(): void
    {
        $this->legacyDisk = (string) config('media-processing.storage.legacy_disk', 'public');
        $this->sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $this->thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');
        $this->cdnEndpoint = config('filesystems.disks.do_spaces.cdn_endpoint');
    }

    /**
     * Get file information for a sermon based on its storage pattern.
     *
     * Performance Optimization: Memoizes file information lookups within the request
     * to avoid redundant path validation and storage pattern logic when resolving
     * multiple URLs (e.g., audio, public, delivery) for the same sermon.
     *
     * @return array{type: string, disk: string, path: string, original_path: string}
     */
    public function getSermonFileInfo(Sermon $sermon): array
    {
        return $this->memoizedFileInfo[$this->getFileInfoCacheKey($sermon)] ??= $this->resolveFileInfo($sermon);
    }

    /**
     * Get the request-level cache key for a sermon's file information.
     */
    private function getFileInfoCacheKey(Sermon $sermon): string
    {
        $audioPath = $sermon->audio_file_path ?? '';

        // Key by ID (if persisted) or object hash (if unsaved), plus path and filetype
        // to ensure uniqueness and handle state changes within the request.
        return ($sermon->id ?? 'u'.spl_object_id($sermon))."_{$audioPath}_{$sermon->filetype}";
    }

    /**
     * Resolve file information for a sermon based on its storage pattern.
     *
     * @return array{type: string, disk: string, path: string, original_path: string}
     */
    private function resolveFileInfo(Sermon $sermon): array
    {
        $audioPath = $sermon->audio_file_path ?? '';
        $this->validatePath($audioPath, 'audio file');

        // Private files stored on the local disk (unreachable via the public/storage symlink)
        if (str_starts_with($audioPath, 'private/')) {
            return [
                'type' => 'private',
                'disk' => 'local',
                'path' => $audioPath,
                'original_path' => $audioPath,
            ];
        }

        // Determine which storage pattern this sermon uses
        if ($sermon->filetype && ! str_contains($audioPath, '/')) {
            // Legacy pattern
            // Check if filename already has extension to avoid double extensions
            $filename = $audioPath;
            if (! str_ends_with($filename, ".{$sermon->filetype}")) {
                $filename .= ".{$sermon->filetype}";
            }

            return [
                'type' => 'legacy',
                'disk' => $this->legacyDisk,
                'path' => "legacy/sermons/{$filename}",
                'original_path' => "media/sermons/{$filename}",
            ];
        }

        if (str_contains($audioPath, '/')) {
            // Newer Laravel storage pattern
            return [
                'type' => 'storage',
                'disk' => $this->sermonDisk,
                'path' => $audioPath,
                'original_path' => $audioPath,
            ];
        }

        // Current media processing pattern
        return [
            'type' => 'processing',
            'disk' => $this->sermonDisk,
            'path' => $audioPath,
            'original_path' => $audioPath,
        ];
    }

    /**
     * Get the video URL for a sermon.
     */
    public function getVideoUrl(Sermon $sermon): ?string
    {
        if (! $sermon->video_file_path) {
            return null;
        }

        $this->validatePath($sermon->video_file_path, 'video file');
        $this->ensurePubliclyResolvable($sermon->video_file_path, 'video');

        return $this->resolvePublicUrl(
            $this->sermonDisk,
            $sermon->video_file_path,
            $this->videoVersion($sermon),
        );
    }

    /**
     * Get the thumbnail URL for a sermon.
     */
    public function getThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->thumbnail_file_path, 'thumbnail');
    }

    public function getCardThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->card_thumbnail_file_path, 'card thumbnail');
    }

    public function getPlainThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->plain_thumbnail_file_path, 'plain thumbnail');
    }

    public function getThumbnailCandidatePath(Sermon $sermon, string $candidateId, string $variant): ?string
    {
        $candidate = $sermon->findThumbnailCandidate($candidateId);

        if ($candidate === null) {
            return null;
        }

        return match ($variant) {
            'overlay' => $candidate['overlay_path'] ?? null,
            'card' => $candidate['card_path'] ?? $candidate['plain_path'],
            'plain' => $candidate['plain_path'],
            default => null,
        };
    }

    public function getAdminThumbnailCandidatePreviewUrl(Sermon $sermon, string $candidateId, string $variant): ?string
    {
        $path = $this->getThumbnailCandidatePath($sermon, $candidateId, $variant);

        if (! filled($path)) {
            return null;
        }

        return route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => $candidateId,
            'variant' => $variant,
        ]);
    }

    /**
     * Resolve the storage disk for a thumbnail path.
     *
     * Performance Optimization: Memoizes disk resolution within the request
     * to avoid redundant path validation and string checks when resolving
     * multiple thumbnail variants for the same sermon listing.
     */
    public function resolveThumbnailDisk(string $thumbnailPath): string
    {
        if (isset($this->memoizedThumbnailDisks[$thumbnailPath])) {
            return $this->memoizedThumbnailDisks[$thumbnailPath];
        }

        $this->validatePath($thumbnailPath, 'thumbnail');

        return $this->memoizedThumbnailDisks[$thumbnailPath] = str_starts_with($thumbnailPath, 'private/')
            ? 'local'
            : $this->thumbnailDisk;
    }

    /**
     * Get the public URL for a sermon file.
     */
    public function getPublicUrl(Sermon $sermon): string
    {
        $info = $this->getSermonFileInfo($sermon);
        $this->ensurePubliclyResolvable($info['path'], 'audio');

        return $this->resolvePublicUrl($info['disk'], $info['path'], $this->audioVersion($sermon));
    }

    public function getAudioDeliveryUrl(Sermon $sermon): ?string
    {
        if (! filled($sermon->audio_file_path)) {
            return null;
        }

        $info = $this->getSermonFileInfo($sermon);

        if ($this->requiresGuardedDelivery($info['path'])) {
            return route('sermons.audio', ['sermon' => $sermon->slug]);
        }

        return $this->getPublicUrl($sermon);
    }

    public function getVideoDeliveryUrl(Sermon $sermon): ?string
    {
        $videoPath = $sermon->video_file_path;

        if (! filled($videoPath)) {
            return null;
        }

        $this->validatePath((string) $videoPath, 'video file');

        if ($this->requiresGuardedDelivery($videoPath)) {
            return route('sermons.video', ['sermon' => $sermon->slug]);
        }

        return $this->getVideoUrl($sermon);
    }

    public function getThumbnailDeliveryUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailDeliveryUrl(
            $sermon,
            $sermon->thumbnail_file_path,
            'thumbnail',
            'sermons.thumbnail'
        );
    }

    public function getCardThumbnailDeliveryUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailDeliveryUrl(
            $sermon,
            $sermon->card_thumbnail_file_path,
            'card thumbnail',
            'sermons.thumbnail.card'
        );
    }

    public function getPlainThumbnailDeliveryUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailDeliveryUrl(
            $sermon,
            $sermon->plain_thumbnail_file_path,
            'plain thumbnail',
            'sermons.thumbnail'
        );
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

    public function clearCachedMetadata(?Sermon $sermon = null): void
    {
        if ($sermon) {
            Cache::forget($this->fileMetadataCacheKey($sermon));

            // Also clear request-level memoization for this sermon
            unset($this->memoizedFileInfo[$this->getFileInfoCacheKey($sermon)]);

            if ($sermon->thumbnail_file_path) {
                unset($this->memoizedThumbnailDisks[$sermon->thumbnail_file_path]);
            }

            return;
        }

        // Logic for clearing broad caches if needed (none currently)
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

            return true;
        } catch (Exception $e) {
            Log::error('Failed to move sermon file', $this->sanitizeArrayForLog([
                'sermon_id' => $sermon->id,
                'from_disk' => $info['disk'],
                'to_disk' => $targetDisk,
                'path' => $info['path'],
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

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
        $stats = $this->initializeStorageStats();

        Sermon::query()
            ->select(['id', 'audio_file_path', 'filetype'])
            ->chunk(self::STATS_CHUNK_SIZE, function ($sermons) use (&$stats) {
                foreach ($sermons as $sermon) {
                    $this->updateStatsForSermon($stats, $sermon);
                }
            });

        return $stats;
    }

    /**
     * Initialize the storage statistics array.
     *
     * @return array{
     *     total_sermons: int,
     *     patterns: array{private: int, legacy: int, storage: int, processing: int},
     *     disks: array<string, array{count: int, size: int, missing: int}>,
     *     total_size: int,
     *     missing_files: int
     * }
     */
    private function initializeStorageStats(): array
    {
        return [
            'total_sermons' => Sermon::query()->count(),
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
    }

    /**
     * Update storage statistics for a single sermon.
     *
     * @param  array<string, mixed>  $stats
     */
    private function updateStatsForSermon(array &$stats, Sermon $sermon): void
    {
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
        } catch (Exception $e) {
            $stats['disks'][$disk]['missing']++;
            $stats['missing_files']++;
        }
    }

    private function appendVersion(string $url, string $version): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}v={$version}";
    }

    private function resolveThumbnailUrl(Sermon $sermon, mixed $path, string $type): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $this->ensurePubliclyResolvable((string) $path, $type);

        return $this->resolvePublicUrl(
            $this->resolveThumbnailDisk((string) $path),
            (string) $path,
            $this->thumbnailVersion($sermon, (string) $path),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolveThumbnailDeliveryUrl(Sermon $sermon, mixed $path, string $type, string $routeName): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $this->validatePath((string) $path, $type);

        if ($this->requiresGuardedDelivery((string) $path)) {
            return route($routeName, ['sermon' => $sermon->slug]);
        }

        return match ($type) {
            'thumbnail' => $this->getThumbnailUrl($sermon),
            'card thumbnail' => $this->getCardThumbnailUrl($sermon),
            'plain thumbnail' => $this->getPlainThumbnailUrl($sermon),
            default => throw new InvalidArgumentException("Unsupported thumbnail type: {$type}"),
        };
    }

    private function audioVersion(Sermon $sermon): string
    {
        $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;

        return $this->memoizedVersions["audio_{$sermon->id}_{$timestamp}"] ??= sha1(implode('|', [
            'audio',
            $sermon->audio_file_path,
            $timestamp,
        ]));
    }

    private function videoVersion(Sermon $sermon): string
    {
        $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;

        return $this->memoizedVersions["video_{$sermon->id}_{$timestamp}"] ??= sha1(implode('|', [
            'video',
            $sermon->video_file_path,
            $timestamp,
        ]));
    }

    private function resolvePublicUrl(string $disk, string $path, string $version): string
    {
        $baseUrl = ($disk === 'do_spaces' && $this->cdnEndpoint)
            ? $this->cdnEndpoint
            : ($this->memoizedDiskUrls[$disk] ??= rtrim(Storage::disk($disk)->url('/'), '/'));

        return $this->appendVersion($baseUrl.'/'.ltrim($path, '/'), $version);
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
                    'last_modified' => (int) Storage::disk($info['disk'])->lastModified($info['path']),
                    'size' => (int) Storage::disk($info['disk'])->size($info['path']),
                ];
            } catch (Exception) {
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
        $timestamp = $sermon->thumbnail_generated_at?->getTimestamp()
            ?? $sermon->updated_at?->getTimestamp()
            ?? 0;

        $cacheKey = "thumb_{$sermon->id}_{$timestamp}_".sha1($thumbnailPath);

        return $this->memoizedVersions[$cacheKey] ??= sha1(implode('|', [
            'thumbnail',
            $thumbnailPath,
            $timestamp,
        ]));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validatePath(?string $path, string $type): void
    {
        if (is_string($path) && Path::isUnsafe($path)) {
            throw new InvalidArgumentException("Invalid {$type} path: Unsafe path detected.");
        }
    }

    /**
     * @throws LogicException
     */
    private function ensurePubliclyResolvable(string $path, string $type): void
    {
        if ($this->requiresGuardedDelivery($path)) {
            throw new LogicException("Private {$type} assets must be served through guarded sermon asset routes.");
        }
    }

    private function requiresGuardedDelivery(string $path): bool
    {
        return str_starts_with($path, 'private/');
    }
}
