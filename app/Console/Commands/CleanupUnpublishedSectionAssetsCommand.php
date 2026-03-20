<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Publication\ExpireSectionPublicationAssets;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupUnpublishedSectionAssetsCommand extends Command
{
    protected $signature = 'media:cleanup-unpublished-section-assets
        {--hours=48 : Delete unpublished section assets that expired this many hours ago}
        {--dry-run : Preview cleanup changes without writing files or database rows}';

    protected $description = 'Clean up extracted media assets for unpublished service sections';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $targetStatuses = [
            ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            ServiceSectionPublicationStatus::REJECTED->value,
            ServiceSectionPublicationStatus::APPROVED->value,
        ];

        $sections = ServiceSection::query()
            ->whereIn('publication_status', $targetStatuses)
            ->whereNull('published_sermon_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNotNull('unpublished_expires_at')
                    ->where('unpublished_expires_at', '<=', now())
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('unpublished_expires_at')
                            ->whereNotNull('extracted_at')
                            ->where('extracted_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->get();

        if ($sections->isEmpty()) {
            $this->info('No unpublished section assets require cleanup.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN enabled. No files or rows will be modified.');
        }

        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $cleanedCount = 0;
        $failedCount = 0;

        foreach ($sections as $section) {
            $videoPath = $section->extracted_video_path;
            $audioPath = $section->extracted_audio_path;

            if ($dryRun) {
                $this->line('Would clean section #'.$section->id.' video='.$this->pathLabel($videoPath).' audio='.$this->pathLabel($audioPath));

                continue;
            }

            $previousStatus = $section->publication_status->value;

            try {
                DB::transaction(function () use ($section, $videoPath, $audioPath, $sermonDisk, $tempDisk): void {
                    app(ExpireSectionPublicationAssets::class)->execute($section, [
                        'reason' => 'asset_expiry',
                        'cleaned_by' => 'scheduler',
                    ]);
                    DB::afterCommit(function () use ($videoPath, $audioPath, $sermonDisk, $tempDisk): void {
                        $this->deletePathOnKnownDisks($videoPath, [$sermonDisk, $tempDisk]);
                        $this->deletePathOnKnownDisks($audioPath, [$sermonDisk, $tempDisk]);
                    });
                });
            } catch (\RuntimeException $e) {
                $this->error('Failed to transition section #'.$section->id.' from '.$previousStatus.' to not_applicable — skipping.');
                $failedCount++;

                continue;
            }

            $cleanedCount++;
        }

        if ($dryRun) {
            $this->info('Dry run complete: '.$sections->count().' sections would be cleaned.');

            return self::SUCCESS;
        }

        $this->info('Cleanup complete: '.$cleanedCount.' sections cleaned.');

        if ($failedCount > 0) {
            $this->warn("{$failedCount} section(s) could not be transitioned and were skipped.");
        }

        Log::info('Unpublished section asset cleanup complete', [
            'sections_cleaned' => $cleanedCount,
            'sections_failed' => $failedCount,
        ]);

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pathLabel(?string $path): string
    {
        return is_string($path) && $path !== '' ? $path : '-';
    }

    /**
     * @param  array<int, string>  $disks
     */
    private function deletePathOnKnownDisks(?string $path, array $disks): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        foreach ($disks as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);

                return;
            }
        }
    }
}
