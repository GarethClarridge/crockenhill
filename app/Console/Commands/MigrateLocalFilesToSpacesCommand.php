<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLocalFilesToSpacesCommand extends Command
{
    protected $signature = 'sermons:migrate-local-files
                           {--dry-run : Preview migration without executing}';

    protected $description = 'Migrate local sermon files from public disk to DigitalOcean Spaces';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $localDisk = Storage::disk('public');
        $spacesDisk = Storage::disk('do_spaces');

        // Get all files recursively
        $files = $localDisk->allFiles('sermons');

        $this->info('Found '.count($files).' files to migrate');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be actually migrated');
            foreach ($files as $file) {
                $this->line("Would migrate: {$file}");
            }

            return 0;
        }

        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $content = $localDisk->get($file);

                if (! is_string($content)) {
                    throw new \RuntimeException("Unable to read file contents for {$file}");
                }

                $spacesDisk->put($file, $content);

                // Verify upload with retry
                $verified = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    if ($spacesDisk->exists($file)) {
                        $verified = true;
                        break;
                    }
                    if ($attempt < 3) {
                        usleep(500000); // Wait 0.5 seconds before retry
                    }
                }

                if ($verified) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = "{$file}: verification failed after 3 attempts";
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = "{$file}: {$e->getMessage()}";
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Migration complete!');
        $this->info("Success: {$success}");

        if ($failed > 0) {
            $this->error("Failed: {$failed}");
            $this->newLine();
            $this->error('Errors:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
