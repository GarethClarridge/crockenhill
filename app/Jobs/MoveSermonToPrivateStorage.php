<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MoveSermonToPrivateStorage implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /** @var array<int, true> */
    private static array $sermonsBeingMoved = [];

    /** @var array<string, array{disk: string, source: string, target: string}> */
    private array $pendingSourceDeletions = [];

    public function __construct(
        private readonly int $sermonId,
    ) {}

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
        $moveFailure = null;

        try {
            $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
            $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
            $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

            $this->moveDirectAsset('audio_file_path', $sermonDisk);
            $this->moveDirectAsset('video_file_path', $sermonDisk);
            $this->moveDirectAsset('transcript_file_path', $transcriptDisk);
            $this->moveDirectAsset('thumbnail_file_path', $thumbnailDisk);
            $this->moveMetadataAsset('plain_thumbnail_path', $thumbnailDisk);
            $this->moveMetadataAsset('card_thumbnail_path', $thumbnailDisk);
            $this->moveMetadataAsset('overlay_thumbnail_path', $thumbnailDisk);
            $this->moveCandidateAssets($thumbnailDisk);
        } catch (Throwable $exception) {
            $moveFailure = $exception;
        } finally {
            try {
                $this->deleteScheduledSources();
            } catch (Throwable $cleanupException) {
                if ($moveFailure === null) {
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

        if ($moveFailure !== null) {
            throw $moveFailure;
        }
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

    private function moveDirectAsset(string $field, string $sourceDisk): void
    {
        $sermon = Sermon::query()->findOrFail($this->sermonId);
        $path = $sermon->getAttribute($field);

        if (! is_string($path) || $path === '') {
            return;
        }

        [$sourcePath, $targetPath] = $this->sourceAndTargetPaths($path);

        if ($path === $sourcePath) {
            $this->copyAndVerify($sourceDisk, $sourcePath, $targetPath);
            $this->compareAndSetDirectPath($field, $sourcePath, $targetPath);
        } else {
            $this->verifyCommittedTarget($targetPath);
        }

        $this->scheduleSourceDeletion($sourceDisk, $sourcePath, $targetPath);
    }

    private function moveMetadataAsset(string $key, string $sourceDisk): void
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
            $this->copyAndVerify($sourceDisk, $sourcePath, $targetPath);
            $this->compareAndSetMetadataPath($key, $sourcePath, $targetPath);
        } else {
            $this->verifyCommittedTarget($targetPath);
        }

        $this->scheduleSourceDeletion($sourceDisk, $sourcePath, $targetPath);
    }

    private function moveCandidateAssets(string $sourceDisk): void
    {
        $metadata = Sermon::query()->findOrFail($this->sermonId)->thumbnail_metadata;

        if (! $metadata instanceof ThumbnailMetadata) {
            return;
        }

        foreach ($metadata->thumbnailCandidates as $candidate) {
            $candidateId = $candidate['id'];

            foreach (['plain_path', 'card_path', 'overlay_path'] as $key) {
                $path = $candidate[$key] ?? null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                [$sourcePath, $targetPath] = $this->sourceAndTargetPaths($path);

                if ($path === $sourcePath) {
                    $this->copyAndVerify($sourceDisk, $sourcePath, $targetPath);
                    $this->compareAndSetCandidatePath($candidateId, $key, $sourcePath, $targetPath);
                } else {
                    $this->verifyCommittedTarget($targetPath);
                }

                $this->scheduleSourceDeletion($sourceDisk, $sourcePath, $targetPath);
            }
        }
    }

    /** @return array{string, string} */
    private function sourceAndTargetPaths(string $path): array
    {
        if (str_starts_with($path, 'private/')) {
            return [substr($path, strlen('private/')), $path];
        }

        return [$path, 'private/'.$path];
    }

    private function copyAndVerify(string $sourceDisk, string $sourcePath, string $targetPath): void
    {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk('local');

        if (! $source->exists($sourcePath)) {
            throw new RuntimeException("Private-media source is missing: [{$sourceDisk}] {$sourcePath}");
        }

        $targetCreated = false;

        if (! $target->exists($targetPath)) {
            $stream = $source->readStream($sourcePath);

            if (! is_resource($stream)) {
                throw new RuntimeException("Unable to read private-media source: [{$sourceDisk}] {$sourcePath}");
            }

            try {
                $written = $target->writeStream($targetPath, $stream);
            } finally {
                fclose($stream);
            }

            $targetCreated = true;

            if ($written !== true) {
                $target->delete($targetPath);

                throw new RuntimeException("Unable to write private-media target: {$targetPath}");
            }
        }

        if (! $target->exists($targetPath) || $source->size($sourcePath) !== $target->size($targetPath)) {
            if ($targetCreated) {
                $target->delete($targetPath);
            }

            throw new RuntimeException("Private-media target verification failed: {$targetPath}");
        }
    }

    private function verifyCommittedTarget(string $targetPath): void
    {
        if (! Storage::disk('local')->exists($targetPath)) {
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

    private function deleteSourceAfterCommit(string $sourceDisk, string $sourcePath, string $targetPath): void
    {
        $source = Storage::disk($sourceDisk);

        if (! $source->exists($sourcePath)) {
            return;
        }

        $target = Storage::disk('local');

        if (! $target->exists($targetPath) || $source->size($sourcePath) !== $target->size($targetPath)) {
            throw new RuntimeException("Refusing to delete an unverified private-media source: {$sourcePath}");
        }

        if ($this->isSourceReferenced($sourceDisk, $sourcePath)) {
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

    private function scheduleSourceDeletion(string $sourceDisk, string $sourcePath, string $targetPath): void
    {
        $key = $sourceDisk.'|'.$sourcePath;

        $this->pendingSourceDeletions[$key] = [
            'disk' => $sourceDisk,
            'source' => $sourcePath,
            'target' => $targetPath,
        ];
    }

    private function deleteScheduledSources(): void
    {
        foreach ($this->pendingSourceDeletions as $deletion) {
            $this->deleteSourceAfterCommit(
                $deletion['disk'],
                $deletion['source'],
                $deletion['target'],
            );
        }
    }

    private function isSourceReferenced(string $disk, string $path): bool
    {
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
        $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

        return Sermon::query()
            ->get(['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path', 'thumbnail_metadata'])
            ->contains(function (Sermon $sermon) use ($disk, $path, $sermonDisk, $transcriptDisk, $thumbnailDisk): bool {
                $paths = [
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
                        $paths[] = [$thumbnailDisk, $candidate[$key] ?? null];
                    }
                }

                return collect($paths)->contains(
                    fn (array $asset): bool => $asset[0] === $disk && $asset[1] === $path,
                );
            });
    }
}
