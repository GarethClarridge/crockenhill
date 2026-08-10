<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonPublicationState;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricStagingUrlGuard;
use App\Support\Path;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Service for managing the resolution and retrieval of sermon media files.
 *
 * This service centralises the logic for building public and secure delivery
 * URLs and calculating cache-busting versions across local and
 * S3-compatible disks.
 */
class SermonStorageService
{
    use SanitizesLogData;

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
     * @var array<string, array{type: 'storage', disk: string, path: string}>
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
     * @return array{type: 'storage', disk: string, path: string}
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

        return ($sermon->id ?? 'u'.spl_object_id($sermon))."_{$sermon->asset_disk}_{$audioPath}";
    }

    /**
     * Resolve file information for a sermon based on its canonical path.
     *
     * @return array{type: 'storage', disk: string, path: string}
     */
    private function resolveFileInfo(Sermon $sermon): array
    {
        $audioPath = $sermon->audio_file_path ?? '';
        $this->validatePath($audioPath, 'audio file');

        return [
            'type' => 'storage',
            'disk' => $this->assetDisk($sermon, $this->sermonDisk),
            'path' => $audioPath,
        ];
    }

    /**
     * Get the public video URL for a sermon.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no video exists
     *
     * @throws InvalidArgumentException If the video path contains unsafe characters
     */
    public function getVideoUrl(Sermon $sermon): ?string
    {
        if (! $sermon->video_file_path) {
            return null;
        }

        if ($sermon->publication_state === SermonPublicationState::Quarantined) {
            return route('sermons.video', ['sermon' => $sermon->slug]);
        }

        $this->validatePath($sermon->video_file_path, 'video file');

        return $this->resolvePublicUrl(
            $this->assetDisk($sermon, $this->sermonDisk),
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
     * @throws InvalidArgumentException If the thumbnail path contains unsafe characters
     */
    public function getThumbnailUrl(Sermon $sermon): ?string
    {
        if ($sermon->publication_state === SermonPublicationState::Quarantined) {
            return filled($sermon->thumbnail_file_path)
                ? route('sermons.thumbnail', ['sermon' => $sermon->slug])
                : null;
        }

        return $this->resolveThumbnailUrl($sermon, $sermon->thumbnail_file_path);
    }

    /**
     * Get the social card thumbnail URL for a sermon.
     *
     * Social cards are branded images optimized for sharing on Twitter/Facebook.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no card exists
     *
     * @throws InvalidArgumentException If the thumbnail path contains unsafe characters
     */
    public function getCardThumbnailUrl(Sermon $sermon): ?string
    {
        if ($sermon->publication_state === SermonPublicationState::Quarantined) {
            return filled($sermon->card_thumbnail_file_path)
                ? route('sermons.thumbnail.card', ['sermon' => $sermon->slug])
                : null;
        }

        return $this->resolveThumbnailUrl($sermon, $sermon->card_thumbnail_file_path);
    }

    /**
     * Get the plain (unbranded frame) thumbnail URL for a sermon.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The versioned public URL, or null if no plain thumbnail exists
     *
     * @throws InvalidArgumentException If the thumbnail path contains unsafe characters
     */
    public function getPlainThumbnailUrl(Sermon $sermon): ?string
    {
        if ($sermon->publication_state === SermonPublicationState::Quarantined) {
            return filled($sermon->plain_thumbnail_file_path)
                ? route('sermons.thumbnail.plain', ['sermon' => $sermon->slug])
                : null;
        }

        return $this->resolveThumbnailUrl($sermon, $sermon->plain_thumbnail_file_path);
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

        return $this->memoizedThumbnailDisks[$thumbnailPath] = $this->thumbnailDisk;
    }

    /**
     * Get the versioned public URL for a sermon's audio file.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string The versioned public URL
     */
    public function getPublicUrl(Sermon $sermon): string
    {
        if ($sermon->publication_state === SermonPublicationState::Quarantined) {
            return route('sermons.audio', ['sermon' => $sermon->slug]);
        }

        $info = $this->getSermonFileInfo($sermon);

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

    private function appendVersion(string $url, string $version): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}v={$version}";
    }

    private function resolveThumbnailUrl(Sermon $sermon, mixed $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        // resolveThumbnailDisk() validates the path, so no separate check here.
        return $this->resolvePublicUrl(
            $this->assetDisk($sermon, $this->resolveThumbnailDisk((string) $path)),
            (string) $path,
            $this->thumbnailVersion($sermon, (string) $path),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolveThumbnailDeliveryUrl(Sermon $sermon, mixed $path, string $type): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $this->validatePath((string) $path, $type);

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
        HistoricStagingUrlGuard::assertAllowed($disk);

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
        $cacheKey = $this->fileMetadataCacheKey($sermon);
        $cachedMetadata = Cache::get($cacheKey);

        // Only integer values are ever written below, so null values identify a
        // legacy rememberForever() entry that cached a storage failure. Reject
        // those alongside malformed shapes so the failure is re-read, not served.
        if (is_array($cachedMetadata)
            && is_int($cachedMetadata['last_modified'] ?? null)
            && is_int($cachedMetadata['size'] ?? null)) {
            /** @var array{last_modified: int, size: int} $cachedMetadata */
            return $cachedMetadata;
        }

        if ($cachedMetadata !== null) {
            Cache::forget($cacheKey);
        }

        try {
            /** @var array{last_modified: ?int, size: ?int} $metadata */
            $metadata = Cache::remember(
                $cacheKey,
                (int) config('media-processing.storage.metadata_cache_ttl', 3600),
                function () use ($sermon): array {
                    $info = $this->getSermonFileInfo($sermon);

                    return [
                        'last_modified' => (int) Storage::disk($info['disk'])->lastModified($info['path']),
                        'size' => (int) Storage::disk($info['disk'])->size($info['path']),
                    ];
                },
            );
        } catch (Exception) {
            return [
                'last_modified' => null,
                'size' => null,
            ];
        }

        return $metadata;
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

    private function assetDisk(Sermon $sermon, string $fallback): string
    {
        return filled($sermon->asset_disk) ? (string) $sermon->asset_disk : $fallback;
    }
}
