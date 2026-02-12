<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class MigrateImagesToStorage extends Command
{
    protected $signature = 'images:migrate-to-storage
                            {--dry-run : Show what would be done without making changes}
                            {--category= : Migrate specific category (pages, meetings, photos, site)}
                            {--convert-webp : Convert images to WebP during migration}
                            {--delete-originals : Delete original files after successful migration}';

    protected $description = 'Migrate images from public/ to storage/app/public/ following Laravel best practices';

    private array $stats = [
        'migrated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'size_before' => 0,
        'size_after' => 0,
    ];

    private bool $isDryRun = false;

    private bool $convertWebp = false;

    private int $webpQuality = 85;

    public function handle(): int
    {
        $this->isDryRun = $this->option('dry-run');
        $this->convertWebp = $this->option('convert-webp');

        if ($this->isDryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
            $this->newLine();
        }

        $category = $this->option('category');
        $categories = $category ? [$category] : ['pages', 'meetings', 'photos', 'site'];

        foreach ($categories as $cat) {
            $this->newLine();
            $this->info("=== Migrating: {$cat} ===");

            match ($cat) {
                'pages' => $this->migratePageHeadingImages(),
                'meetings' => $this->migrateMeetingPhotos(),
                'photos' => $this->migratePhotoSelectorImages(),
                'site' => $this->migrateSiteImages(),
                default => $this->error("Unknown category: {$cat}"),
            };
        }

        $this->displaySummary();

        return $this->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Migrate page heading images to Media Library (already handled by existing command)
     */
    private function migratePageHeadingImages(): void
    {
        $this->info('Page heading images are managed via Media Library.');
        $this->info('Run: sail artisan pages:migrate-images');
        $this->newLine();

        // Just report status
        $pages = Page::all();
        $withMedia = $pages->filter(fn (Page $p): bool => $p->getFirstMedia('headings') !== null)->count();
        $withLegacy = $pages->filter(function (Page $p): bool {
            if ($p->getFirstMedia('headings')) {
                return false;
            }

            return File::exists(public_path("images/headings/large/{$p->slug}.webp"))
                || File::exists(public_path("images/headings/large/{$p->slug}.jpg"));
        })->count();

        $this->line("  Pages with Media Library images: {$withMedia}");
        $this->line("  Pages with legacy images to migrate: {$withLegacy}");

        if ($withLegacy > 0 && ! $this->isDryRun) {
            if ($this->confirm('Run pages:migrate-images now?', true)) {
                $this->call('pages:migrate-images');
            }
        }
    }

    /**
     * Migrate meeting photos from /public/images/meetings/ to /storage/app/public/meetings/
     */
    private function migrateMeetingPhotos(): void
    {
        $sourceDir = public_path('images/meetings');
        $targetDir = 'meetings/photos';

        if (! File::isDirectory($sourceDir)) {
            $this->warn('No meeting photos directory found');

            return;
        }

        $meetings = Meeting::where('pictures', true)->get();
        $this->info("Found {$meetings->count()} meetings with photos");

        foreach ($meetings as $meeting) {
            $meetingSourceDir = "{$sourceDir}/{$meeting->slug}";

            if (! File::isDirectory($meetingSourceDir)) {
                $this->line("  [SKIP] {$meeting->heading} - No photos directory");
                $this->stats['skipped']++;

                continue;
            }

            $photos = File::files($meetingSourceDir);
            $photoCount = count($photos);
            $this->line("  Migrating {$meeting->heading} ({$photoCount} photos)...");

            foreach ($photos as $photo) {
                $this->migrateFile(
                    $photo->getPathname(),
                    "{$targetDir}/{$meeting->slug}/{$photo->getFilename()}"
                );
            }
        }
    }

    /**
     * Migrate photo selector images to storage
     */
    private function migratePhotoSelectorImages(): void
    {
        $sourceDir = public_path('images/photos');
        $targetDir = 'site/photos';

        if (! File::isDirectory($sourceDir)) {
            $this->warn('No photos directory found');

            return;
        }

        $photos = File::files($sourceDir);
        $this->info('Found '.count($photos).' photos to migrate');

        foreach ($photos as $photo) {
            $this->migrateFile(
                $photo->getPathname(),
                "{$targetDir}/{$photo->getFilename()}"
            );
        }
    }

    /**
     * Migrate site-wide images (logos, backgrounds, etc)
     */
    private function migrateSiteImages(): void
    {
        // These are truly static and should stay in public/
        // But we can identify which ones are actually used

        $siteImages = [
            'images/Primary.png' => 'site/branding/og-image.png',
            'images/IconWhite.svg' => 'site/branding/logo-white.svg',
            'images/IconBackground.png' => 'site/branding/logo-background.png',
            'images/BrandOverlay.png' => 'site/branding/brand-overlay.png',
            'images/default-sermon-thumbnail.webp' => 'site/defaults/sermon-thumbnail.webp',
            'images/podcast/EveningArtwork.webp' => 'site/podcast/evening-artwork.webp',
        ];

        $this->info('Site images migration (optional - these can stay in public/ for truly static assets)');

        foreach ($siteImages as $source => $target) {
            $sourcePath = public_path($source);

            if (! File::exists($sourcePath)) {
                $this->warn("  [MISSING] {$source}");
                $this->stats['errors']++;

                continue;
            }

            $this->line("  Found: {$source}");
        }

        $this->newLine();
        $this->info('Recommendation: Keep truly static branding assets in public/');
        $this->info('Only move user-uploaded content to storage/');
    }

    /**
     * Migrate a single file to storage
     */
    private function migrateFile(string $sourcePath, string $targetPath): void
    {
        if (! File::exists($sourcePath)) {
            $this->warn("  [SKIP] File not found: {$sourcePath}");
            $this->stats['skipped']++;

            return;
        }

        // Check if already migrated
        if (Storage::disk('public')->exists($targetPath)) {
            $this->line("  [SKIP] Already exists: {$targetPath}");
            $this->stats['skipped']++;

            return;
        }

        $this->stats['size_before'] += File::size($sourcePath);

        if ($this->isDryRun) {
            $this->line('  [DRY-RUN] Would migrate: '.basename($sourcePath));
            $this->stats['migrated']++;

            return;
        }

        try {
            // Ensure directory exists
            $dir = dirname($targetPath);
            Storage::disk('public')->makeDirectory($dir);

            // Convert to WebP if requested and it's an image
            $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
            $convertibleFormats = ['jpg', 'jpeg', 'png', 'gif'];
            $isConvertible = in_array($extension, $convertibleFormats, true);

            if ($this->convertWebp && $isConvertible) {
                $image = Image::read($sourcePath);
                $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $targetPath);

                Storage::disk('public')->put($webpPath, (string) $image->toWebp(quality: $this->webpQuality));

                $finalPath = Storage::disk('public')->path($webpPath);
                $this->stats['size_after'] += File::size($finalPath);
                $this->line('  [OK] Converted: '.basename($sourcePath).' -> '.basename($webpPath));
            } else {
                Storage::disk('public')->put($targetPath, File::get($sourcePath));

                $finalPath = Storage::disk('public')->path($targetPath);
                $this->stats['size_after'] += File::size($finalPath);
                $this->line('  [OK] Migrated: '.basename($sourcePath));
            }

            // Delete original if requested
            if ($this->option('delete-originals')) {
                File::delete($sourcePath);
            }

            $this->stats['migrated']++;

        } catch (\Exception $e) {
            $this->error("  [ERROR] {$sourcePath}: {$e->getMessage()}");
            $this->stats['errors']++;
        }
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Migration Summary ===');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Files migrated', $this->stats['migrated']],
                ['Files skipped', $this->stats['skipped']],
                ['Errors', $this->stats['errors']],
                ['Size before', $this->formatBytes($this->stats['size_before'])],
                ['Size after', $this->formatBytes($this->stats['size_after'])],
                ['Space saved', $this->formatBytes($this->stats['size_before'] - $this->stats['size_after'])],
            ]
        );

        if ($this->isDryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN. No changes were made.');
        }

        $this->newLine();
        $this->info('Next steps after migration:');
        $this->line('  1. Update ViewServiceProvider to use new paths');
        $this->line('  2. Update meetings/show.blade.php photo paths');
        $this->line('  3. Run: sail artisan storage:link (if not already done)');
        $this->line('  4. Test all image displays');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }
}
