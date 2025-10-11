<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DiagnoseUpload extends Command
{
    protected $signature = 'upload:diagnose';
    protected $description = 'Diagnose upload configuration and permissions';

    public function handle()
    {
        $this->info('🔍 Upload Diagnostics');
        $this->newLine();

        // Check disk space
        $this->info('📦 Disk Space:');
        $storagePath = storage_path();
        $freeSpace = disk_free_space($storagePath);
        $totalSpace = disk_total_space($storagePath);
        $this->line("  Free: " . $this->formatBytes($freeSpace));
        $this->line("  Total: " . $this->formatBytes($totalSpace));
        $this->line("  Usage: " . round((1 - $freeSpace / $totalSpace) * 100, 2) . "%");
        
        if ($freeSpace < 10 * 1024 * 1024 * 1024) { // Less than 10GB
            $this->warn("  ⚠️  Low disk space!");
        }
        $this->newLine();

        // Check Livewire temp directory
        $this->info('📁 Livewire Temp Directory:');
        $livewireTmpPath = storage_path('app/livewire-tmp');
        $this->line("  Path: {$livewireTmpPath}");
        $this->line("  Exists: " . (is_dir($livewireTmpPath) ? '✅' : '❌'));
        $this->line("  Writable: " . (is_writable($livewireTmpPath) ? '✅' : '❌'));
        $this->line("  Permissions: " . (is_dir($livewireTmpPath) ? substr(sprintf('%o', fileperms($livewireTmpPath)), -4) : 'N/A'));
        $this->newLine();

        // Check storage directory
        $this->info('📁 Storage Directory:');
        $this->line("  Path: {$storagePath}");
        $this->line("  Writable: " . (is_writable($storagePath) ? '✅' : '❌'));
        $this->newLine();

        // PHP configuration
        $this->info('⚙️  PHP Configuration:');
        $this->line("  upload_max_filesize: " . ini_get('upload_max_filesize'));
        $this->line("  post_max_size: " . ini_get('post_max_size'));
        $this->line("  max_execution_time: " . ini_get('max_execution_time') . "s");
        $this->line("  max_input_time: " . ini_get('max_input_time') . "s");
        $this->line("  memory_limit: " . ini_get('memory_limit'));
        $this->line("  upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: 'default'));
        $this->newLine();

        // Livewire configuration
        $this->info('🔌 Livewire Configuration:');
        $livewireRules = config('livewire.temporary_file_upload.rules');
        $this->line("  Rules: " . json_encode($livewireRules));
        $this->line("  Max upload time: " . config('livewire.temporary_file_upload.max_upload_time') . " minutes");
        $this->newLine();

        // Test file creation
        $this->info('✍️  Testing File Creation:');
        try {
            $testFile = $livewireTmpPath . '/test-' . time() . '.txt';
            file_put_contents($testFile, 'test');
            $this->line("  Create: ✅");
            unlink($testFile);
            $this->line("  Delete: ✅");
        } catch (\Exception $e) {
            $this->error("  Failed: " . $e->getMessage());
        }

        return 0;
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
