<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SermonTranscriptReader
{
    public function __construct(
        private readonly TranscriptStorageService $transcriptStorageService,
    ) {}

    /**
     * Read the transcript content for a sermon.
     *
     * Performance Optimization: Caches successful transcript reads for 24 hours
     * to avoid redundant remote storage hits (S3/Spaces) on every sermon page view.
     * Failed reads (null results) are not cached, ensuring the system retries
     * on subsequent requests if storage was temporarily unavailable.
     *
     * @param  Sermon  $sermon  The sermon model
     * @return string|null The transcript content or null if not found
     */
    public function read(Sermon $sermon): ?string
    {
        if (! $sermon->transcript_file_path) {
            return null;
        }

        $path = trim((string) $sermon->transcript_file_path);

        if (str_contains($path, '..')) {
            Log::warning('Path traversal attempt detected in transcript path', [
                'sermon_id' => $sermon->id,
                'path' => $path,
            ]);

            return null;
        }

        $cacheKey = $this->cacheKey($sermon, $path);

        // Try to retrieve from cache first
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $transcript = $this->transcriptStorageService->readTranscriptFromPath($path);

        if ($transcript !== null) {
            // Cache successful reads for 24 hours
            Cache::put($cacheKey, $transcript, 86400);

            return $transcript;
        }

        Log::warning('Transcript file not found on any configured disk', [
            'disks_checked' => $this->transcriptStorageService->getTranscriptReadDisks(),
            'sermon_id' => $sermon->id,
            'transcript_file_path' => $path,
        ]);

        return null;
    }

    /**
     * Generate a cache key for the sermon transcript.
     */
    private function cacheKey(Sermon $sermon, string $path): string
    {
        $hash = sha1($path);
        $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;

        return "sermon_transcript_{$hash}_{$timestamp}";
    }
}
