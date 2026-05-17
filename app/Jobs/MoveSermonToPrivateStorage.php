<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ThumbnailMetadata;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MoveSermonToPrivateStorage implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

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

        $this->moveAudioIfNeeded($sermon);
        $this->moveThumbnailIfNeeded($sermon);
        $this->movePlainThumbnailIfNeeded($sermon);
        $this->moveCardThumbnailIfNeeded($sermon);
        $this->moveCandidateFilesIfNeeded($sermon);
    }

    private function moveAudioIfNeeded(Sermon $sermon): void
    {
        $path = $sermon->audio_file_path;

        if (! $path || str_starts_with($path, 'private/')) {
            return;
        }

        $sourceDisk = config('media-processing.storage.sermon_disk', 'public');
        $targetPath = 'private/'.$path;

        if (! Storage::disk($sourceDisk)->exists($path)) {
            Log::warning('MoveSermonToPrivateStorage: audio file not found on source disk', [
                'sermon_id' => $this->sermonId,
                'disk' => $sourceDisk,
                'path' => $path,
            ]);

            return;
        }

        $stream = Storage::disk($sourceDisk)->readStream($path);

        if (! is_resource($stream)) {
            Log::warning('MoveSermonToPrivateStorage: could not open audio stream', [
                'sermon_id' => $this->sermonId,
                'path' => $path,
            ]);

            return;
        }

        Storage::disk('local')->writeStream($targetPath, $stream);
        Storage::disk($sourceDisk)->delete($path);

        $sermon->update(['audio_file_path' => $targetPath]);

        Log::info('MoveSermonToPrivateStorage: audio moved', [
            'sermon_id' => $this->sermonId,
            'from' => $path,
            'to' => $targetPath,
        ]);
    }

    private function moveThumbnailIfNeeded(Sermon $sermon): void
    {
        $path = $sermon->thumbnail_file_path;

        if (! $path || str_starts_with($path, 'private/')) {
            return;
        }

        $sourceDisk = config('thumbnail-generation.storage.disk', 'public');
        $targetPath = 'private/'.$path;

        if (! Storage::disk($sourceDisk)->exists($path)) {
            return;
        }

        $stream = Storage::disk($sourceDisk)->readStream($path);

        if (! is_resource($stream)) {
            return;
        }

        Storage::disk('local')->writeStream($targetPath, $stream);
        Storage::disk($sourceDisk)->delete($path);

        $sermon->update(['thumbnail_file_path' => $targetPath]);

        Log::info('MoveSermonToPrivateStorage: thumbnail moved', [
            'sermon_id' => $this->sermonId,
            'from' => $path,
            'to' => $targetPath,
        ]);
    }

    private function movePlainThumbnailIfNeeded(Sermon $sermon): void
    {
        $metadata = $sermon->thumbnail_metadata;

        if (! $metadata instanceof ThumbnailMetadata) {
            return;
        }

        $path = $metadata->plainThumbnailPath;

        if (! is_string($path) || $path === '' || str_starts_with($path, 'private/')) {
            return;
        }

        $sourceDisk = config('thumbnail-generation.storage.disk', 'public');
        $targetPath = 'private/'.$path;

        if (! Storage::disk($sourceDisk)->exists($path)) {
            return;
        }

        $stream = Storage::disk($sourceDisk)->readStream($path);

        if (! is_resource($stream)) {
            return;
        }

        Storage::disk('local')->writeStream($targetPath, $stream);
        Storage::disk($sourceDisk)->delete($path);

        $updated = array_merge($metadata->toArray(), ['plain_thumbnail_path' => $targetPath]);
        $sermon->update(['thumbnail_metadata' => $updated]);

        Log::info('MoveSermonToPrivateStorage: plain thumbnail moved', [
            'sermon_id' => $this->sermonId,
            'from' => $path,
            'to' => $targetPath,
        ]);
    }

    private function moveCardThumbnailIfNeeded(Sermon $sermon): void
    {
        $metadata = $sermon->thumbnail_metadata;

        if (! $metadata instanceof ThumbnailMetadata) {
            return;
        }

        $path = $metadata->cardThumbnailPath;

        if (! is_string($path) || $path === '' || str_starts_with($path, 'private/')) {
            return;
        }

        $sourceDisk = config('thumbnail-generation.storage.disk', 'public');
        $targetPath = 'private/'.$path;

        if (! Storage::disk($sourceDisk)->exists($path)) {
            return;
        }

        $stream = Storage::disk($sourceDisk)->readStream($path);

        if (! is_resource($stream)) {
            return;
        }

        Storage::disk('local')->writeStream($targetPath, $stream);
        Storage::disk($sourceDisk)->delete($path);

        $updated = array_merge($metadata->toArray(), ['card_thumbnail_path' => $targetPath]);
        $sermon->update(['thumbnail_metadata' => $updated]);

        Log::info('MoveSermonToPrivateStorage: card thumbnail moved', [
            'sermon_id' => $this->sermonId,
            'from' => $path,
            'to' => $targetPath,
        ]);
    }

    private function moveCandidateFilesIfNeeded(Sermon $sermon): void
    {
        $metadata = $sermon->thumbnail_metadata;

        if ($metadata === null || $metadata->thumbnailCandidates === []) {
            return;
        }

        $candidates = $metadata->thumbnailCandidates;
        $sourceDisk = config('thumbnail-generation.storage.disk', 'public');
        $changed = false;
        $pathKeys = ['plain_path', 'card_path', 'overlay_path'];

        foreach ($candidates as $index => $candidate) {
            foreach ($pathKeys as $key) {
                $path = $candidate[$key] ?? null;

                if (! is_string($path) || $path === '' || str_starts_with($path, 'private/')) {
                    continue;
                }

                $targetPath = 'private/'.$path;

                if (! Storage::disk($sourceDisk)->exists($path)) {
                    continue;
                }

                $stream = Storage::disk($sourceDisk)->readStream($path);

                if (! is_resource($stream)) {
                    continue;
                }

                Storage::disk('local')->writeStream($targetPath, $stream);
                Storage::disk($sourceDisk)->delete($path);

                $candidates[$index][$key] = $targetPath;
                $changed = true;

                Log::info('MoveSermonToPrivateStorage: candidate file moved', [
                    'sermon_id' => $this->sermonId,
                    'candidate_id' => $candidate['id'],
                    'key' => $key,
                    'from' => $path,
                    'to' => $targetPath,
                ]);
            }
        }

        if ($changed) {
            $updated = array_merge($metadata->toArray(), ['thumbnail_candidates' => $candidates]);
            $sermon->update(['thumbnail_metadata' => $updated]);
        }
    }
}
