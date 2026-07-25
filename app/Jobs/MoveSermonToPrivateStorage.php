<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Support\MediaAssetPath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Moves a children's talk's assets between the kind's ordinary disk and the
 * local `private/` disk. Both directions run through the same copy-verify-commit
 * -then-delete sequence; only `$toPrivate` decides which end is the source.
 */
class MoveSermonToPrivateStorage implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const PRIVATE_PREFIX = 'private/';

    private const PRIVATE_DISK = 'local';

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /** @var array<int, true> */
    private static array $sermonsBeingMoved = [];

    /** @var array<string, array{source_disk: string, target_disk: string, source: string, target: string}> */
    private array $pendingSourceDeletions = [];

    /**
     * Declared with defaults instead of promoted so that a job already queued
     * before these parameters existed still deserialises into the forward
     * direction rather than an uninitialised readonly property.
     */
    private bool $toPrivate = true;

    private bool $deleteSource = true;

    public function __construct(
        private readonly int $sermonId,
        bool $toPrivate = true,
        bool $deleteSource = true,
    ) {
        $this->toPrivate = $toPrivate;
        $this->deleteSource = $deleteSource;
    }

    public function handle(): void
    {
        $sermon = Sermon::query()->find($this->sermonId);

        if ($sermon === null) {
            Log::warning('MoveSermonToPrivateStorage: sermon not found', ['sermon_id' => $this->sermonId]);

            return;
        }

        if ($sermon->content_type !== SermonContentType::ChildrensTalk) {
            return;
        }

        self::$sermonsBeingMoved[$this->sermonId] = true;
        $this->pendingSourceDeletions = [];

        /** @var array<int, Throwable> $moveFailures */
        $moveFailures = [];

        try {
            $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
            $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
            $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

            // One failed asset must not leave the others public: attempt every
            // move, collect the failures, and rethrow after cleanup so retries
            // still happen for whatever could not be protected this run.
            $moveOperations = [
                fn () => $this->moveDirectAsset('audio_file_path', $sermonDisk),
                fn () => $this->moveDirectAsset('video_file_path', $sermonDisk),
                fn () => $this->moveDirectAsset('transcript_file_path', $transcriptDisk),
                fn () => $this->moveDirectAsset('thumbnail_file_path', $thumbnailDisk),
                fn () => $this->moveMetadataAsset('plain_thumbnail_path', $thumbnailDisk),
                fn () => $this->moveMetadataAsset('card_thumbnail_path', $thumbnailDisk),
                fn () => $this->moveMetadataAsset('overlay_thumbnail_path', $thumbnailDisk),
            ];

            foreach ($moveOperations as $moveOperation) {
                try {
                    $moveOperation();
                } catch (Throwable $exception) {
                    $moveFailures[] = $exception;
                }
            }

            $moveFailures = [...$moveFailures, ...$this->moveCandidateAssets($thumbnailDisk)];
        } finally {
            try {
                $this->deleteScheduledSources();
            } catch (Throwable $cleanupException) {
                if ($moveFailures === []) {
                    throw $cleanupException;
                }

                Log::error('MoveSermonToPrivateStorage: cleanup failed after asset move failure', [
                    'sermon_id' => $this->sermonId,
                    'exception' => $cleanupException,
                ]);
            } finally {
                unset(self::$sermonsBeingMoved[$this->sermonId]);
            }
        }

        if ($moveFailures === []) {
            return;
        }

        foreach (array_slice($moveFailures, 1) as $additionalFailure) {
            Log::error('MoveSermonToPrivateStorage: additional asset move failure', [
                'sermon_id' => $this->sermonId,
                'exception' => $additionalFailure,
            ]);
        }

        throw $moveFailures[0];
    }

    public static function isMovingSermon(int $sermonId): bool
    {
        return isset(self::$sermonsBeingMoved[$sermonId]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('MoveSermonToPrivateStorage failed', [
            'sermon_id' => $this->sermonId,
            'exception' => $exception,
        ]);
    }

    private function moveDirectAsset(string $field, string $kindDisk): void
    {
        $sermon = Sermon::query()->findOrFail($this->sermonId);
        $path = $sermon->getAttribute($field);

        if (! is_string($path) || $path === '') {
            return;
        }

        [$sourcePath, $targetPath] = $this->sourceAndTargetPaths($path);

        if ($path === $sourcePath) {
            $this->copyAndVerify($kindDisk, $sourcePath, $targetPath);
            $this->compareAndSetDirectPath($field, $sourcePath, $targetPath);
        } else {
            $this->verifyCommittedTarget($kindDisk, $targetPath);
        }

        $this->scheduleSourceDeletion($kindDisk, $sourcePath, $targetPath);
    }

    private function moveMetadataAsset(string $key, string $kindDisk): void
    {
        $metadata = Sermon::query()->findOrFail($this->sermonId)->thumbnail_metadata;

        if (! $metadata instanceof ThumbnailMetadata) {
            return;
        }

        $path = match ($key) {
            'plain_thumbnail_path' => $metadata->plainThumbnailPath,
            'card_thumbnail_path' => $metadata->cardThumbnailPath,
            'overlay_thumbnail_path' => $metadata->overlayThumbnailPath,
            default => null,
        };

        if (! is_string($path) || $path === '') {
            return;
        }

        [$sourcePath, $targetPath] = $this->sourceAndTargetPaths($path);

        if ($path === $sourcePath) {
            $this->copyAndVerify($kindDisk, $sourcePath, $targetPath);
            $this->compareAndSetMetadataPath($key, $sourcePath, $targetPath);
        } else {
            $this->verifyCommittedTarget($kindDisk, $targetPath);
        }

        $this->scheduleSourceDeletion($kindDisk, $sourcePath, $targetPath);
    }

    /** @return array<int, Throwable> */
    private function moveCandidateAssets(string $kindDisk): array
    {
        $metadata = Sermon::query()->findOrFail($this->sermonId)->thumbnail_metadata;

        if (! $metadata instanceof ThumbnailMetadata) {
            return [];
        }

        $failures = [];

        foreach ($metadata->thumbnailCandidates as $candidate) {
            $candidateId = $candidate['id'];

            foreach (['plain_path', 'card_path', 'overlay_path'] as $key) {
                $path = $candidate[$key] ?? null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                try {
                    $this->moveCandidatePath($candidateId, $key, $path, $kindDisk);
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            }
        }

        return $failures;
    }

    private function moveCandidatePath(string $candidateId, string $key, string $path, string $kindDisk): void
    {
        [$sourcePath, $targetPath] = $this->sourceAndTargetPaths($path);

        if ($path === $sourcePath) {
            $this->copyAndVerify($kindDisk, $sourcePath, $targetPath);
            $this->compareAndSetCandidatePath($candidateId, $key, $sourcePath, $targetPath);
        } else {
            $this->verifyCommittedTarget($kindDisk, $targetPath);
        }

        $this->scheduleSourceDeletion($kindDisk, $sourcePath, $targetPath);
    }

    /**
     * The [source, target] pair for the configured direction. Both are derived
     * from the prefix-stripped path, so an asset already sitting at its target
     * yields `$path !== $source` and callers take the verify-only branch — which
     * is how a partially-completed run resumes in either direction.
     *
     * @return array{string, string}
     */
    private function sourceAndTargetPaths(string $path): array
    {
        $publicPath = str_starts_with($path, self::PRIVATE_PREFIX)
            ? substr($path, strlen(self::PRIVATE_PREFIX))
            : $path;

        $privatePath = self::PRIVATE_PREFIX.$publicPath;

        return $this->toPrivate
            ? [$publicPath, $privatePath]
            : [$privatePath, $publicPath];
    }

    private function sourceDisk(string $kindDisk): string
    {
        return $this->toPrivate ? $kindDisk : self::PRIVATE_DISK;
    }

    private function targetDisk(string $kindDisk): string
    {
        return $this->toPrivate ? self::PRIVATE_DISK : $kindDisk;
    }

    private function copyAndVerify(string $kindDisk, string $sourcePath, string $targetPath): void
    {
        $sourceDisk = $this->sourceDisk($kindDisk);
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($this->targetDisk($kindDisk));

        if (! $source->exists($sourcePath)) {
            throw new RuntimeException("Private-media source is missing: [{$sourceDisk}] {$sourcePath}");
        }

        if ($target->exists($targetPath)) {
            if ($source->size($sourcePath) === $target->size($targetPath)) {
                return;
            }

            // A mismatched pre-existing target that another sermon row already
            // references may be its only private copy — never delete it. An
            // unreferenced one is a stale partial from an earlier crashed
            // attempt; replace it so retries can heal instead of failing forever.
            if ($this->isPathReferenced($targetPath)) {
                throw new RuntimeException("Private-media target verification failed: {$targetPath}");
            }

            Log::warning('MoveSermonToPrivateStorage: replacing stale unreferenced target', [
                'sermon_id' => $this->sermonId,
                'target' => $targetPath,
            ]);

            $target->delete($targetPath);
        }

        $stream = $source->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read private-media source: [{$sourceDisk}] {$sourcePath}");
        }

        try {
            $written = $target->writeStream($targetPath, $stream);
        } finally {
            fclose($stream);
        }

        if ($written !== true) {
            $target->delete($targetPath);

            throw new RuntimeException("Unable to write private-media target: {$targetPath}");
        }

        if (! $target->exists($targetPath) || $source->size($sourcePath) !== $target->size($targetPath)) {
            $target->delete($targetPath);

            throw new RuntimeException("Private-media target verification failed: {$targetPath}");
        }
    }

    private function verifyCommittedTarget(string $kindDisk, string $targetPath): void
    {
        if (! Storage::disk($this->targetDisk($kindDisk))->exists($targetPath)) {
            throw new RuntimeException("Committed private-media target is missing: {$targetPath}");
        }
    }

    private function compareAndSetDirectPath(string $field, string $sourcePath, string $targetPath): void
    {
        DB::transaction(function () use ($field, $sourcePath, $targetPath): void {
            $sermon = Sermon::query()->lockForUpdate()->findOrFail($this->sermonId);
            $currentPath = $sermon->getAttribute($field);

            if ($currentPath === $targetPath) {
                return;
            }

            if ($currentPath !== $sourcePath) {
                throw new RuntimeException("Private-media path changed concurrently: {$field}");
            }

            $sermon->update([$field => $targetPath]);
        });
    }

    private function compareAndSetMetadataPath(string $key, string $sourcePath, string $targetPath): void
    {
        DB::transaction(function () use ($key, $sourcePath, $targetPath): void {
            $sermon = Sermon::query()->lockForUpdate()->findOrFail($this->sermonId);
            $metadata = $sermon->thumbnail_metadata;

            if (! $metadata instanceof ThumbnailMetadata) {
                throw new RuntimeException('Thumbnail metadata changed concurrently.');
            }

            $data = $metadata->toArray();
            $currentPath = $data[$key] ?? null;

            if ($currentPath === $targetPath) {
                return;
            }

            if ($currentPath !== $sourcePath) {
                throw new RuntimeException("Thumbnail metadata changed concurrently: {$key}");
            }

            $data[$key] = $targetPath;
            $sermon->update(['thumbnail_metadata' => $data]);
        });
    }

    private function compareAndSetCandidatePath(
        string $candidateId,
        string $key,
        string $sourcePath,
        string $targetPath,
    ): void {
        DB::transaction(function () use ($candidateId, $key, $sourcePath, $targetPath): void {
            $sermon = Sermon::query()->lockForUpdate()->findOrFail($this->sermonId);
            $metadata = $sermon->thumbnail_metadata;

            if (! $metadata instanceof ThumbnailMetadata) {
                throw new RuntimeException('Thumbnail metadata changed concurrently.');
            }

            $data = $metadata->toArray();
            $candidates = $data['thumbnail_candidates'] ?? [];

            foreach ($candidates as $index => $candidate) {
                if (($candidate['id'] ?? null) !== $candidateId) {
                    continue;
                }

                $currentPath = $candidate[$key] ?? null;

                if ($currentPath === $targetPath) {
                    return;
                }

                if ($currentPath !== $sourcePath) {
                    throw new RuntimeException("Thumbnail candidate changed concurrently: {$candidateId}.{$key}");
                }

                $candidates[$index][$key] = $targetPath;
                $data['thumbnail_candidates'] = $candidates;
                $sermon->update(['thumbnail_metadata' => $data]);

                return;
            }

            throw new RuntimeException("Thumbnail candidate disappeared concurrently: {$candidateId}");
        });
    }

    /**
     * @param  array{disk_paths: array<string, true>, paths: array<string, true>}  $referencedAssets
     */
    private function deleteSourceAfterCommit(
        string $sourceDisk,
        string $targetDisk,
        string $sourcePath,
        string $targetPath,
        array $referencedAssets,
    ): void {
        $source = Storage::disk($sourceDisk);

        if (! $source->exists($sourcePath)) {
            return;
        }

        $target = Storage::disk($targetDisk);

        if (! $target->exists($targetPath) || $source->size($sourcePath) !== $target->size($targetPath)) {
            throw new RuntimeException("Refusing to delete an unverified private-media source: {$sourcePath}");
        }

        if (isset($referencedAssets['disk_paths'][$sourceDisk.'|'.$sourcePath])) {
            Log::warning('MoveSermonToPrivateStorage: retained referenced source', [
                'sermon_id' => $this->sermonId,
                'disk' => $sourceDisk,
                'path' => $sourcePath,
            ]);

            return;
        }

        if ($source->delete($sourcePath) !== true) {
            throw new RuntimeException("Unable to delete public private-media source: [{$sourceDisk}] {$sourcePath}");
        }

        if (Storage::disk($sourceDisk)->exists($sourcePath)) {
            throw new RuntimeException("Unable to delete public private-media source: [{$sourceDisk}] {$sourcePath}");
        }

        Log::info('MoveSermonToPrivateStorage: asset moved', [
            'sermon_id' => $this->sermonId,
            'disk' => $sourceDisk,
            'from' => $sourcePath,
            'to' => $targetPath,
        ]);
    }

    private function scheduleSourceDeletion(string $kindDisk, string $sourcePath, string $targetPath): void
    {
        $sourceDisk = $this->sourceDisk($kindDisk);
        $key = $sourceDisk.'|'.$sourcePath;

        $this->pendingSourceDeletions[$key] = [
            'source_disk' => $sourceDisk,
            'target_disk' => $this->targetDisk($kindDisk),
            'source' => $sourcePath,
            'target' => $targetPath,
        ];
    }

    private function deleteScheduledSources(): void
    {
        // A copy-only run leaves every source in place, so the copy can be
        // audited before anything becomes unrecoverable. A later run with
        // deletion enabled takes the verify-only branch for each already
        // committed path and removes the sources it has just re-verified.
        if (! $this->deleteSource || $this->pendingSourceDeletions === []) {
            return;
        }

        // All path commits have happened by now, so one snapshot answers every
        // deletion's "is this source still referenced elsewhere" question.
        $referencedAssets = $this->referencedAssetIndex();

        foreach ($this->pendingSourceDeletions as $deletion) {
            $this->deleteSourceAfterCommit(
                $deletion['source_disk'],
                $deletion['target_disk'],
                $deletion['source'],
                $deletion['target'],
                $referencedAssets,
            );
        }
    }

    private function isPathReferenced(string $path): bool
    {
        return isset($this->referencedAssetIndex()['paths'][$path]);
    }

    /**
     * Every disk+path pair referenced by any sermon row, plus a disk-agnostic
     * path set for private-target ownership checks.
     *
     * Each path's disk is resolved from the path itself, not from its kind's
     * ordinary disk: a row still holding `private/…` lives on the private disk,
     * and keying it against the public one would make the "source is still
     * referenced elsewhere" guard in `deleteSourceAfterCommit()` unable to match
     * in the reverse direction, so a shared asset could be deleted out from
     * under another sermon.
     *
     * @return array{disk_paths: array<string, true>, paths: array<string, true>}
     */
    private function referencedAssetIndex(): array
    {
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
        $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

        $index = ['disk_paths' => [], 'paths' => []];

        Sermon::query()
            ->get(['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path', 'thumbnail_metadata'])
            ->each(function (Sermon $sermon) use (&$index, $sermonDisk, $transcriptDisk, $thumbnailDisk): void {
                $assets = [
                    [$sermonDisk, $sermon->audio_file_path],
                    [$sermonDisk, $sermon->video_file_path],
                    [$transcriptDisk, $sermon->transcript_file_path],
                    [$thumbnailDisk, $sermon->thumbnail_file_path],
                    [$thumbnailDisk, $sermon->plain_thumbnail_file_path],
                    [$thumbnailDisk, $sermon->card_thumbnail_file_path],
                    [$thumbnailDisk, $sermon->thumbnail_metadata?->overlayThumbnailPath],
                ];

                foreach ($sermon->thumbnail_candidates as $candidate) {
                    foreach (['plain_path', 'card_path', 'overlay_path'] as $key) {
                        $assets[] = [$thumbnailDisk, $candidate[$key] ?? null];
                    }
                }

                foreach ($assets as [$publicDisk, $path]) {
                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $disk = MediaAssetPath::diskForPath($path, $publicDisk);

                    $index['disk_paths'][$disk.'|'.$path] = true;
                    $index['paths'][$path] = true;
                }
            });

        return $index;
    }
}
