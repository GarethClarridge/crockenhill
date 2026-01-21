<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ConvertJpgToWebp extends Command
{
    protected $signature = 'images:convert-to-webp
                            {--dry-run : Show what would be done without making changes}
                            {--skip-convert : Skip image conversion, only update references}
                            {--skip-references : Skip reference updates, only convert images}
                            {--quality=85 : WebP quality (0-100)}
                            {--delete-originals : Delete original JPG files after conversion}
                            {--find-unused : Find and list images not referenced in code}';

    protected $description = 'Convert JPG images to WebP format and update all references in the codebase';

    private array $convertedFiles = [];

    private array $updatedReferences = [];

    private array $unusedImages = [];

    private array $errors = [];

    private bool $isDryRun = false;

    private int $quality = 85;

    public function handle(): int
    {
        $this->isDryRun = $this->option('dry-run');
        $this->quality = (int) $this->option('quality');

        if ($this->isDryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
            $this->newLine();
        }

        // Phase 1: Find all JPG files
        $this->info('Phase 1: Scanning for JPG files...');
        $jpgFiles = $this->findAllJpgFiles();
        $this->info("Found {$jpgFiles->count()} JPG files");
        $this->newLine();

        // Phase 2: Convert images to WebP
        if (! $this->option('skip-convert')) {
            $this->info('Phase 2: Converting images to WebP...');
            $this->convertImages($jpgFiles);
            $this->newLine();
        }

        // Phase 3: Update code references
        if (! $this->option('skip-references')) {
            $this->info('Phase 3: Updating code references...');
            $this->updateCodeReferences();
            $this->newLine();
        }

        // Phase 4: Find unused images (optional)
        if ($this->option('find-unused')) {
            $this->info('Phase 4: Finding unused images...');
            $this->findUnusedImages();
            $this->newLine();
        }

        // Phase 5: Delete originals (if requested)
        if ($this->option('delete-originals') && ! $this->isDryRun && ! empty($this->convertedFiles)) {
            if ($this->confirm('Delete original JPG files?', false)) {
                $this->deleteOriginalFiles();
            }
        }

        // Summary
        $this->displaySummary();

        return count($this->errors) > 0 ? 1 : 0;
    }

    private function findAllJpgFiles(): \Illuminate\Support\Collection
    {
        $directories = [
            base_path('public/images'),
            storage_path('app/public/sermons/thumbnails'),
        ];

        $files = collect();

        foreach ($directories as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }

            $found = collect(File::allFiles($dir))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg']))
                ->map(fn ($file) => $file->getPathname());

            $files = $files->merge($found);
        }

        return $files;
    }

    private function convertImages(\Illuminate\Support\Collection $files): void
    {
        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        foreach ($files as $jpgPath) {
            try {
                $webpPath = $this->getWebpPath($jpgPath);

                if ($this->isDryRun) {
                    $this->convertedFiles[$jpgPath] = $webpPath;
                } else {
                    // Convert using Intervention Image
                    $image = Image::make($jpgPath);
                    $image->encode('webp', $this->quality);

                    // Ensure directory exists
                    $dir = dirname($webpPath);
                    if (! File::isDirectory($dir)) {
                        File::makeDirectory($dir, 0755, true);
                    }

                    $image->save($webpPath);
                    $this->convertedFiles[$jpgPath] = $webpPath;

                    // Log size savings
                    $originalSize = File::size($jpgPath);
                    $newSize = File::size($webpPath);
                    $savings = round((1 - $newSize / $originalSize) * 100, 1);
                    $this->line(' Converted: '.basename($jpgPath)." ({$savings}% smaller)");
                }
            } catch (\Exception $e) {
                $this->errors[] = "Failed to convert {$jpgPath}: ".$e->getMessage();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function getWebpPath(string $jpgPath): string
    {
        return preg_replace('/\.(jpg|jpeg)$/i', '.webp', $jpgPath);
    }

    private function updateCodeReferences(): void
    {
        // File patterns to search
        // Note: config/ is excluded because it may contain external references
        // (like podcast feeds) that need to remain as JPG for compatibility
        $patterns = [
            'blade' => base_path('resources/views'),
            'php' => base_path('app'),
            'css' => base_path('resources/css'),
            'js' => base_path('resources/js'),
        ];

        // Patterns to find and replace
        $replacements = $this->buildReplacementPatterns();

        foreach ($patterns as $type => $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            $extensions = match ($type) {
                'blade' => ['blade.php'],
                'php' => ['php'],
                'css' => ['css', 'scss'],
                'js' => ['js', 'ts', 'vue'],
            };

            $this->updateFilesInDirectory($directory, $extensions, $replacements);
        }

        // Also check database seeders
        $this->updateFilesInDirectory(base_path('database'), ['php'], $replacements);
    }

    private function buildReplacementPatterns(): array
    {
        $replacements = [];

        // Build patterns from converted files
        foreach ($this->convertedFiles as $jpgPath => $webpPath) {
            // Get relative path from public directory
            $publicPath = base_path('public');
            if (Str::startsWith($jpgPath, $publicPath)) {
                $relativePath = Str::after($jpgPath, $publicPath);
                $relativeWebpPath = Str::after($webpPath, $publicPath);

                // Various ways the path might be referenced
                $replacements[$relativePath] = $relativeWebpPath;
                $replacements[ltrim($relativePath, '/')] = ltrim($relativeWebpPath, '/');
            }

            // Get relative path from storage
            $storagePath = storage_path('app/public');
            if (Str::startsWith($jpgPath, $storagePath)) {
                $relativePath = '/storage'.Str::after($jpgPath, $storagePath);
                $relativeWebpPath = '/storage'.Str::after($webpPath, $storagePath);

                $replacements[$relativePath] = $relativeWebpPath;
                $replacements[ltrim($relativePath, '/')] = ltrim($relativeWebpPath, '/');
            }
        }

        // Add hardcoded patterns from the codebase analysis
        $hardcodedPatterns = [
            '.webp' => '.webp', // Generic pattern for dynamic paths like {$slug}.webp
        ];

        return array_merge($replacements, $hardcodedPatterns);
    }

    private function updateFilesInDirectory(string $directory, array $extensions, array $replacements): void
    {
        $files = collect(File::allFiles($directory))
            ->filter(function ($file) use ($extensions) {
                foreach ($extensions as $ext) {
                    if (Str::endsWith($file->getFilename(), '.'.$ext)) {
                        return true;
                    }
                }

                return false;
            });

        foreach ($files as $file) {
            $content = File::get($file->getPathname());
            $originalContent = $content;
            $changes = [];

            // Check for JPG references
            if (preg_match_all('/[\'"]([^"\']*\.jpe?g)[\'"]|url\([\'"]?([^"\')\s]*\.jpe?g)[\'"]?\)/i', $content, $matches)) {
                foreach (array_merge($matches[1], $matches[2]) as $match) {
                    if (empty($match)) {
                        continue;
                    }

                    $webpPath = preg_replace('/\.jpe?g$/i', '.webp', $match);
                    $content = str_replace($match, $webpPath, $content);
                    $changes[] = $match.' -> '.$webpPath;
                }
            }

            if ($content !== $originalContent) {
                if ($this->isDryRun) {
                    $this->line("Would update: {$file->getPathname()}");
                    foreach ($changes as $change) {
                        $this->line("  - {$change}");
                    }
                } else {
                    File::put($file->getPathname(), $content);
                    $this->line("Updated: {$file->getPathname()}");
                    foreach ($changes as $change) {
                        $this->line("  - {$change}");
                    }
                }

                $this->updatedReferences[$file->getPathname()] = $changes;
            }
        }
    }

    private function findUnusedImages(): void
    {
        // Get all image files
        $allImages = collect();
        $publicImages = base_path('public/images');

        if (File::isDirectory($publicImages)) {
            $allImages = collect(File::allFiles($publicImages))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                ->map(fn ($file) => $file->getPathname());
        }

        // Search all code files for references
        $codeDirectories = [
            base_path('resources/views'),
            base_path('app'),
            base_path('config'),
            base_path('database'),
        ];

        $allCode = '';
        foreach ($codeDirectories as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                if (in_array($file->getExtension(), ['php', 'blade.php', 'js', 'vue', 'css', 'scss'])) {
                    $allCode .= File::get($file->getPathname());
                }
            }
        }

        // Check each image
        foreach ($allImages as $imagePath) {
            $filename = basename($imagePath);
            $relativePath = Str::after($imagePath, base_path('public'));

            // Check various ways the image might be referenced
            $referenced = false;

            // Check by filename
            if (Str::contains($allCode, $filename)) {
                $referenced = true;
            }

            // Check by relative path
            if (Str::contains($allCode, $relativePath)) {
                $referenced = true;
            }

            // Check for dynamic references (like {$slug}.webp pattern)
            $slugPattern = Str::beforeLast($filename, '.');
            if (preg_match('/\$[a-zA-Z_]+->slug/', $allCode) && Str::contains($imagePath, 'headings')) {
                // Heading images are referenced dynamically via slug
                $referenced = true;
            }

            // Check meeting images (dynamically loaded from directory)
            if (Str::contains($imagePath, '/meetings/')) {
                $referenced = true;
            }

            if (! $referenced) {
                $this->unusedImages[] = $imagePath;
            }
        }

        if (! empty($this->unusedImages)) {
            $this->warn('Potentially unused images found:');
            foreach ($this->unusedImages as $image) {
                $this->line('  - '.$image);
            }
        } else {
            $this->info('No unused images found.');
        }
    }

    private function deleteOriginalFiles(): void
    {
        $this->info('Deleting original JPG files...');

        foreach ($this->convertedFiles as $jpgPath => $webpPath) {
            if (File::exists($webpPath)) {
                File::delete($jpgPath);
                $this->line("Deleted: {$jpgPath}");
            }
        }
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Summary ===');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Images converted', count($this->convertedFiles)],
                ['Files with updated references', count($this->updatedReferences)],
                ['Potentially unused images', count($this->unusedImages)],
                ['Errors', count($this->errors)],
            ]
        );

        if (! empty($this->errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($this->errors as $error) {
                $this->line('  - '.$error);
            }
        }

        if ($this->isDryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN. No changes were made.');
            $this->info('Run without --dry-run to apply changes.');
        }

        $this->newLine();
        $this->warn('IMPORTANT: After running this command, you should also:');
        $this->line('  1. Run tests to ensure everything still works: sail artisan test');
        $this->line('  2. Verify images display correctly on the website');
    }
}
