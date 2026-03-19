<?php

declare(strict_types=1);

namespace App\Jobs;

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
        $sermon = Sermon::find($this->sermonId);

        if ($sermon === null) {
            Log::warning('MoveSermonToPrivateStorage: sermon not found', ['sermon_id' => $this->sermonId]);

            return;
        }

        $this->moveAudioIfNeeded($sermon);
        $this->moveThumbnailIfNeeded($sermon);
        $this->movePlainThumbnailIfNeeded($sermon);
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
        $path = is_array($metadata) ? ($metadata['plain_thumbnail_path'] ?? null) : null;

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

        $updated = array_merge($metadata ?? [], ['plain_thumbnail_path' => $targetPath]);
        $sermon->update(['thumbnail_metadata' => $updated]);

        Log::info('MoveSermonToPrivateStorage: plain thumbnail moved', [
            'sermon_id' => $this->sermonId,
            'from' => $path,
            'to' => $targetPath,
        ]);
    }
}
