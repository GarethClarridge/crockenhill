<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Meeting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MeetingMigratePhotosCommand extends Command
{
    protected $signature = 'meetings:migrate-photos
                            {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate legacy meeting photos from public/images/meetings/ to Spatie Media Library';

    public function handle(): int
    {
        $meetings = Meeting::all();
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->info("Found {$meetings->count()} meetings to check.");
        $this->newLine();

        foreach ($meetings as $meeting) {
            $directory = public_path("images/meetings/{$meeting->slug}");

            // Skip if already has media library photos
            if ($meeting->getMedia('photos')->isNotEmpty()) {
                $this->line("  [SKIP] {$meeting->heading} - Already has media library photos");
                $skipped++;

                continue;
            }

            // Skip if no legacy photos directory
            if (! File::isDirectory($directory)) {
                $this->line("  [SKIP] {$meeting->heading} - No legacy photos directory");
                $skipped++;

                continue;
            }

            $photos = collect(File::files($directory))
                ->filter(fn ($file) => in_array(
                    strtolower($file->getExtension()),
                    ['jpg', 'jpeg', 'png', 'webp', 'gif']
                ));

            if ($photos->isEmpty()) {
                $this->line("  [SKIP] {$meeting->heading} - No supported image files found");
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->info("  [DRY-RUN] Would migrate {$photos->count()} photo(s): {$meeting->heading}");
                $migrated += $photos->count();

                continue;
            }

            foreach ($photos as $photo) {
                try {
                    $meeting->addMedia($photo->getPathname())
                        ->preservingOriginal()
                        ->toMediaCollection('photos');

                    $this->info("  [OK] Migrated: {$meeting->heading} / {$photo->getFilename()}");
                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("  [ERROR] {$meeting->heading} / {$photo->getFilename()}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        $this->newLine();
        $this->info('Migration complete:');
        $this->line("  Migrated: {$migrated}");
        $this->line("  Skipped: {$skipped}");
        $this->line("  Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
