<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigratePageImagesToMediaLibrary extends Command
{
    protected $signature = 'pages:migrate-images
                            {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate legacy page heading images to Spatie Media Library';

    public function handle(): int
    {
        $pages = Page::all();
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->info("Found {$pages->count()} pages to check.");
        $this->newLine();

        foreach ($pages as $page) {
            $largePath = public_path("images/headings/large/{$page->slug}.jpg");

            // Skip if already has media library image
            if ($page->getFirstMedia('headings')) {
                $this->line("  [SKIP] {$page->heading} - Already has media library image");
                $skipped++;

                continue;
            }

            // Skip if no legacy image exists
            if (! File::exists($largePath)) {
                $this->line("  [SKIP] {$page->heading} - No legacy image found");
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->info("  [DRY-RUN] Would migrate: {$page->heading}");
                $migrated++;

                continue;
            }

            try {
                $page->addMedia($largePath)
                    ->preservingOriginal()
                    ->toMediaCollection('headings');

                $this->info("  [OK] Migrated: {$page->heading}");
                $migrated++;
            } catch (\Exception $e) {
                $this->error("  [ERROR] {$page->heading}: {$e->getMessage()}");
                $errors++;
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
