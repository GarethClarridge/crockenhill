<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Models\Sermon;
use App\Support\Path;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * Service for managing the resolution and retrieval of sermon media files
 * across different storage patterns (legacy, standard, and private).
 *
 * This service centralises the logic for building public and secure delivery
 * URLs, calculating cache-busting versions, and tracking storage statistics.
 * It handles the abstraction between local and S3-compatible disks.
 *
 * @phpstan-type StorageStats array{
 *     total_sermons: int,
 *     patterns: array{private: int, legacy: int, storage: int, processing: int},
 *     disks: array<string, array{count: int, size: int, missing: int}>,
 *     total_size: int,
 *     missing_files: int
 * }
 */
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

    /**
     * Initialise the service by loading configuration from the global config.
     */
    public function __construct()
    {
        $this->refreshConfig();
    }

    /**
     * Clear all request-level memoization caches and cached metadata.
     *
     * Useful for long-running processes (like queue workers or tests) to
     * ensure fresh configuration and state lookups for each operation.
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
     * Get the public video URL for a sermon.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no video exists
     *
     * @throws InvalidArgumentException If the video path contains unsafe characters
     * @throws LogicException If the video is stored in a private directory
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
     * Get the primary (branded overlay) thumbnail URL for a sermon.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no thumbnail exists
     *
     * @throws LogicException If the thumbnail is stored in a private directory
     */
    public function getThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->thumbnail_file_path, 'thumbnail');
    }

    /**
     * Get the social card thumbnail URL for a sermon.
     *
     * Social cards are branded images optimized for sharing on Twitter/Facebook.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no card exists
     *
     * @throws LogicException If the thumbnail is stored in a private directory
     */
    public function getCardThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->card_thumbnail_file_path, 'card thumbnail');
    }

    /**
     * Get the plain (unbranded frame) thumbnail URL for a sermon.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no plain thumbnail exists
     *
     * @throws LogicException If the thumbnail is stored in a private directory
     */
    public function getPlainThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailUrl($sermon, $sermon->plain_thumbnail_file_path, 'plain thumbnail');
    }

    /**
     * Resolve the storage path for a specific thumbnail candidate variant.
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $candidateId  The candidate identifier (e.g. 'candidate-1')
     * @param  string  $variant  The variant name ('overlay', 'card', or 'plain')
     * @return string|null The storage path, or null if not found
     */
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

    /**
     * Generate the administrative preview URL for a thumbnail candidate.
     *
     * These URLs are used in the admin panel to allow operators to review and
     * select from the different extracted frames.
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $candidateId  The candidate identifier
     * @param  string  $variant  The variant name
     * @return string|null The preview route URL, or null if the candidate path cannot be resolved
     */
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
     * Resolve the storage disk for a given thumbnail path.
     *
     * Performance Optimization: Memoizes disk resolution within the request
     * to avoid redundant path validation and string checks when resolving
     * multiple thumbnail variants for the same sermon listing.
     *
     * @param  string  $thumbnailPath  Relative storage path
     * @return string The resolved disk name ('local' or the configured thumbnail disk)
     *
     * @throws InvalidArgumentException If the path contains unsafe characters
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
     * Get the versioned public URL for a sermon's audio file.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string The versioned public URL
     *
     * @throws LogicException If the asset is stored in a private directory
     */
    public function getPublicUrl(Sermon $sermon): string
    {
        $info = $this->getSermonFileInfo($sermon);
        $this->ensurePubliclyResolvable($info['path'], 'audio');

        return $this->resolvePublicUrl($info['disk'], $info['path'], $this->audioVersion($sermon));
    }

    /**
     * Get the appropriate delivery URL for sermon audio, respecting privacy boundaries.
     *
     * Automatically switches between a direct public URL and a guarded application
     * route based on the file's storage location.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The delivery URL, or null if no audio exists
     */
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

    /**
     * Get the appropriate delivery URL for sermon video, respecting privacy boundaries.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The delivery URL, or null if no video exists
     *
     * @throws InvalidArgumentException If the video path contains unsafe characters
     */
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

    /**
     * Get the appropriate delivery URL for the primary thumbnail.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The delivery URL, or null if no thumbnail exists
     *
     * @throws InvalidArgumentException If the thumbnail path contains unsafe characters
     */
    public function getThumbnailDeliveryUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailDeliveryUrl(
            $sermon,
            $sermon->thumbnail_file_path,
            'thumbnail',
            'sermons.thumbnail'
        );
    }

    /**
     * Get the appropriate delivery URL for the social card thumbnail.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The delivery URL, or null if no card exists
     *
     * @throws InvalidArgumentException If the card path contains unsafe characters
     */
    public function getCardThumbnailDeliveryUrl(Sermon $sermon): ?string
    {
        return $this->resolveThumbnailDeliveryUrl(
            $sermon,
            $sermon->card_thumbnail_file_path,
            'card thumbnail',
            'sermons.thumbnail.card'
        );
    }

    /**
     * Get the appropriate delivery URL for the plain thumbnail.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The delivery URL, or null if no plain thumbnail exists
     *
     * @throws InvalidArgumentException If the path contains unsafe characters
     */
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
     * Check if the primary audio file for a sermon exists in storage.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return bool True if the file exists on its resolved disk
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

    /**
     * Clear cached file metadata and request-level memoization for a sermon.
     *
     * @param  Sermon|null  $sermon  The sermon to clear; if null, broad caches could be cleared (none currently)
     */
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
     * Get storage statistics for all sermon files.
     *
     * @return StorageStats
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

        /** @var StorageStats $stats */
        return $stats;
    }

    /**
     * Initialize the storage statistics array.
     *
     * @return StorageStats
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
