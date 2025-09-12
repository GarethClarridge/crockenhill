<?php

namespace App\Console\Commands;

use App\Models\Sermon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLivestreamAudioFiles extends Command
{
    protected $signature = 'sermons:migrate-livestream-audio 
                           {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate livestream audio files from local to public storage';

    public function handle(): int
    {
        $this->info('Searching for livestream sermons with misplaced audio files...');

        $livestreamSermons = Sermon::where('source_type', 'livestream')
            ->whereNotNull('filename')
            ->get();

        $migratedCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($livestreamSermons as $sermon) {
            $filename = $sermon->filename;
            
            // Check if file already exists in public storage
            if (Storage::disk('public')->exists($filename)) {
                $this->line("✓ {$sermon->title} - Audio already accessible");
                $skippedCount++;
                continue;
            }

            // Check if file exists in local storage
            if (!Storage::disk('local')->exists($filename)) {
                $this->error("✗ {$sermon->title} - Audio file not found in either location");
                $errors[] = "Sermon ID {$sermon->id}: {$filename}";
                continue;
            }

            if ($this->option('dry-run')) {
                $this->warn("Would migrate: {$sermon->title} - {$filename}");
                $migratedCount++;
                continue;
            }

            try {
                // Copy file from local to public storage
                $content = Storage::disk('local')->get($filename);
                Storage::disk('public')->put($filename, $content);
                
                $this->info("✓ Migrated: {$sermon->title} - {$filename}");
                $migratedCount++;
                
            } catch (\Exception $e) {
                $this->error("✗ Failed to migrate {$sermon->title}: " . $e->getMessage());
                $errors[] = "Sermon ID {$sermon->id}: " . $e->getMessage();
            }
        }

        $this->newLine();
        $this->info("Migration Summary:");
        $this->info("- Migrated: {$migratedCount} files");
        $this->info("- Skipped (already accessible): {$skippedCount} files");
        $this->info("- Errors: " . count($errors) . " files");

        if (!empty($errors)) {
            $this->newLine();
            $this->error("Errors encountered:");
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('This was a dry run. Use --no-dry-run to perform actual migration.');
        }

        return count($errors) > 0 ? 1 : 0;
    }
}