<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SongVideo;
use App\Services\Song\SongVideoService;
use App\Support\Path;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete after existing production song objects are verified private and the
 * authenticated playback rollout has completed its observation window.
 */
class PrivatiseSongVideos extends Command
{
    protected $signature = 'songs:privatise-videos
        {--disk= : Explicit S3 disk to inspect}
        {--apply : Set song objects private after reviewing the default dry run}';

    protected $description = 'Dry-run existing song object privacy; writes only with --apply';

    public function handle(SongVideoService $videos): int
    {
        $diskName = $this->option('disk');
        if (! is_string($diskName) || config("filesystems.disks.{$diskName}.driver") !== 's3') {
            $this->error('Select an S3 disk with --disk. Local public files require migration outside the web root.');

            return self::FAILURE;
        }

        $paths = [];
        foreach (SongVideo::query()->orderBy('id')->lazyById() as $video) {
            if ($videos->assetDisk($video) !== $diskName) {
                continue;
            }

            $path = $video->video_file_path;
            if (Path::isUnsafe($path) || ! str_starts_with($path, 'sermons/songs/') || ! str_ends_with($path, '.mp4')) {
                $this->error("Refusing unexpected song object key for video {$video->id}.");

                return self::FAILURE;
            }

            $paths[$path] = true;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'DRY RUN').': '.count($paths)." song objects on {$diskName}");
        $disk = Storage::disk($diskName);

        foreach (array_keys($paths) as $path) {
            $this->line($path);
            if (! $apply) {
                continue;
            }

            try {
                if (! $disk->exists($path) || ! $disk->setVisibility($path, 'private') || $disk->getVisibility($path) !== 'private') {
                    $this->error("Could not verify private visibility: {$path}");

                    return self::FAILURE;
                }
            } catch (Throwable $exception) {
                $this->error("Privacy change failed for {$path}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
