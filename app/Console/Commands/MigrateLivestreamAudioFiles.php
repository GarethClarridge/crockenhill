<?php

namespace App\Console\Commands;

use App\Models\Sermon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLivestreamAudioFiles extends Command
{
    protected $signature = 'sermons:migrate-livestream-audio 
                           {--dry-run : Show what would be migrated without making changes}
                           {--cleanup : Remove files from local storage after successful migration}';

    protected $description = 'Migrate sermon audio files from local to public storage (finds by filename pattern)';

    public function handle(): int
    {
        $this->info('Searching for livestream sermons with misplaced audio files...');
        $this->info('Expected pattern: sermons/YYYY/MM/uuid.mp3');
        $this->newLine();

        // Find sermons with the livestream filename pattern: sermons/YYYY/MM/uuid.mp3
        // We use LIKE pattern matching since source_type might not be set correctly
        $livestreamSermons = Sermon::whereNotNull('audio_file_path')
            ->where('audio_file_path', 'LIKE', 'sermons/____/__/%.mp3') // Pattern: sermons/YYYY/MM/uuid.mp3
            ->orderBy('created_at', 'desc')
            ->get();

        if ($livestreamSermons->isEmpty()) {
            $this->warn('No livestream sermons found in database.');

            return 0;
        }

        $this->info("Found {$livestreamSermons->count()} livestream sermons to check...");
        $this->newLine();

        $migratedCount = 0;
        $skippedCount = 0;
        $alreadyAccessibleCount = 0;
        $errors = [];

        foreach ($livestreamSermons as $sermon) {
            $filename = $sermon->audio_file_path; // e.g., "sermons/2025/09/uuid.mp3"
            $sermonTitle = $sermon->title ?: "Sermon ID {$sermon->id}";

            // Debug info
            $this->line("Checking: {$sermonTitle}");
            $this->line("  Filename: {$filename}");
            $this->line('  Source Type: '.($sermon->source_type ?: 'NULL'));
            $this->line('  Created: '.($sermon->created_at ?: 'NULL'));

            // Check if file already exists in public storage (already accessible)
            $publicExists = Storage::disk('public')->exists($filename);
            $localExists = Storage::disk('local')->exists($filename);

            $this->line('  Public storage: '.($publicExists ? 'EXISTS' : 'MISSING'));
            $this->line('  Local storage: '.($localExists ? 'EXISTS' : 'MISSING'));

            if ($publicExists) {
                $this->line("✓ {$sermonTitle} - Already accessible via public storage");
                $alreadyAccessibleCount++;
                $this->newLine();

                continue;
            }

            if (! $localExists) {
                $this->error("✗ {$sermonTitle} - File not found in local storage");
                $this->error("  Expected at: storage/app/{$filename}");
                $errors[] = "Sermon ID {$sermon->id}: File not found at {$filename}";
                $this->newLine();

                continue;
            }

            // File exists in local but not public - needs migration
            if ($this->option('dry-run')) {
                $this->warn("Would migrate: {$sermonTitle}");
                $this->warn("  From: storage/app/{$filename}");
                $this->warn("  To: storage/app/public/{$filename}");
                $migratedCount++;
                $this->newLine();

                continue;
            }

            try {
                // Ensure directory exists in public storage
                $directory = dirname($filename);
                if (! Storage::disk('public')->exists($directory)) {
                    $this->line("  Creating directory: {$directory}");
                    Storage::disk('public')->makeDirectory($directory);
                }

                // Copy file from local to public storage
                $content = Storage::disk('local')->get($filename);
                $success = Storage::disk('public')->put($filename, $content);

                if (! $success) {
                    throw new \Exception('Failed to write file to public storage');
                }

                // Verify the file was copied correctly
                if (! Storage::disk('public')->exists($filename)) {
                    throw new \Exception('File not found after copy operation');
                }

                $originalSize = Storage::disk('local')->size($filename);
                $newSize = Storage::disk('public')->size($filename);

                if ($originalSize !== $newSize) {
                    throw new \Exception("Size mismatch: original {$originalSize} bytes, copied {$newSize} bytes");
                }

                $this->info("✓ Migrated: {$sermonTitle}");
                $this->info('  Size: '.number_format($originalSize).' bytes');
                $migratedCount++;

                // Cleanup original file if requested
                if ($this->option('cleanup')) {
                    Storage::disk('local')->delete($filename);
                    $this->line('  Cleaned up original file from local storage');
                }

            } catch (\Exception $e) {
                $this->error("✗ Failed to migrate {$sermonTitle}: ".$e->getMessage());
                $errors[] = "Sermon ID {$sermon->id}: ".$e->getMessage();
            }

            $this->newLine();
        }

        // Summary
        $this->info('Migration Summary:');
        $this->info('=================');
        $this->info("- Already accessible: {$alreadyAccessibleCount} files");
        $this->info("- Migrated: {$migratedCount} files");
        $this->info('- Errors: '.count($errors).' files');

        if (! empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('This was a dry run. Remove --dry-run to perform actual migration.');
        }

        return count($errors) > 0 ? 1 : 0;
    }
}
