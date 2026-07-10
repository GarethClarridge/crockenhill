<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @phpstan-type MeetingPhotoMigrationSummary array{meetings_examined:int,migrated:int,skipped:int,failed:int}
 * @phpstan-type MeetingPhotoMigrationItem array{status:string,label:string}
 */
class MeetingPhotoMigrationService
{
    /**
     * @return array{
     *     dry_run: bool,
     *     summary: MeetingPhotoMigrationSummary,
     *     items: list<MeetingPhotoMigrationItem>
     * }
     */
    public function migrate(bool $dryRun = false): array
    {
        $summary = [
            'meetings_examined' => 0,
            'migrated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $items = [];

        foreach (Meeting::query()->with('media')->get() as $meeting) {
            $summary['meetings_examined']++;
            $directory = public_path("images/meetings/{$meeting->slug}");

            if (! File::isDirectory($directory)) {
                $summary['skipped']++;
                $items[] = [
                    'status' => 'skip',
                    'label' => "{$meeting->heading}: no legacy photos directory",
                ];

                continue;
            }

            $photos = collect(File::files($directory))
                ->filter(fn (SplFileInfo $file): bool => in_array(
                    strtolower($file->getExtension()),
                    ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                    true,
                ))
                ->values();

            if ($photos->isEmpty()) {
                $summary['skipped']++;
                $items[] = [
                    'status' => 'skip',
                    'label' => "{$meeting->heading}: no supported image files found",
                ];

                continue;
            }

            $existingKeys = $this->existingLegacyKeys($meeting);

            foreach ($photos as $photo) {
                $legacyPath = $this->legacyPhotoPath($meeting, $photo);

                if (isset($existingKeys[$legacyPath])) {
                    $summary['skipped']++;
                    $items[] = [
                        'status' => 'skip',
                        'label' => "{$meeting->heading}: {$photo->getFilename()} already migrated",
                    ];

                    continue;
                }

                if ($dryRun) {
                    $summary['migrated']++;
                    $items[] = [
                        'status' => 'dry-run',
                        'label' => "{$meeting->heading}: would migrate {$photo->getFilename()}",
                    ];

                    continue;
                }

                try {
                    $meeting->addMedia($photo->getPathname())
                        ->withCustomProperties([
                            'legacy_photo_path' => $legacyPath,
                            'legacy_file_name' => $photo->getFilename(),
                        ])
                        ->preservingOriginal()
                        ->toMediaCollection('photos');

                    $summary['migrated']++;
                    $items[] = [
                        'status' => 'ok',
                        'label' => "{$meeting->heading}: migrated {$photo->getFilename()}",
                    ];
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $items[] = [
                        'status' => 'error',
                        'label' => "{$meeting->heading}: {$photo->getFilename()} failed: {$exception->getMessage()}",
                    ];
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function existingLegacyKeys(Meeting $meeting): array
    {
        $keys = [];

        foreach ($meeting->getMedia('photos') as $media) {
            $legacyPath = $this->legacyPathFromMedia($media, $meeting);
            $keys[$legacyPath] = true;
        }

        return $keys;
    }

    private function legacyPathFromMedia(Media $media, Meeting $meeting): string
    {
        $legacyPath = $media->getCustomProperty('legacy_photo_path');

        if (is_string($legacyPath) && $legacyPath !== '') {
            return $legacyPath;
        }

        return "images/meetings/{$meeting->slug}/{$media->file_name}";
    }

    private function legacyPhotoPath(Meeting $meeting, SplFileInfo $photo): string
    {
        return "images/meetings/{$meeting->slug}/{$photo->getFilename()}";
    }
}
