<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixUploadDirectories extends Command
{
    protected $signature = 'upload:fix-directories';

    protected $description = 'Create and fix permissions for upload directories';

    public function handle(): int
    {
        $this->info('🔧 Fixing upload directories...');
        $this->newLine();

        $directories = [
            'livewire-tmp' => storage_path('app/livewire-tmp'),
            'temp/livewire-upload' => storage_path('app/temp/livewire-upload'),
            'temp' => storage_path('app/temp'),
            'sermons' => storage_path('app/public/sermons'),
            'transcripts' => storage_path('app/transcripts'),
        ];

        foreach ($directories as $name => $path) {
            if (! is_dir($path)) {
                if (mkdir($path, 0755, true)) {
                    $this->line("✅ Created: {$name}");
                } else {
                    $this->error("❌ Failed to create: {$name}");
                }
            } else {
                $this->line("✓ Exists: {$name}");
            }

            // Ensure writable
            if (is_dir($path)) {
                chmod($path, 0755);
                $this->line('   Permissions set to 0755');
            }
        }

        $this->newLine();
        $this->info('✅ Upload directories fixed!');
        $this->newLine();
        $this->warn('⚠️  Remember to run: php artisan storage:link');

        return 0;
    }
}
