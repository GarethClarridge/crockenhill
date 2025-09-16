# Storage Migration Plan: Local → DigitalOcean Spaces

## Current State Analysis

### Three Storage Patterns Identified

1. **Legacy Sermons** (~hundreds of files)
   - **Database**: `filename` field (base name only), `filetype` field (extension)
   - **Location**: `public/media/sermons/{filename}.{filetype}`
   - **Examples**: `08March2020pm.mp3`, `123a.mp3`
   - **Detection**: `filename` field doesn't contain "/" AND `filetype` field exists

2. **Newer Manual Uploads** (dozens of files)
   - **Database**: `filename` field contains full storage path
   - **Location**: `storage/app/public/sermons/`
   - **Examples**: `sermons/1prCLcoKMr4BK9pVTbFCtoW1Wo7mMfcR8WFItarX.mp3`
   - **Detection**: `filename` field contains "/" (uses Laravel Storage)

3. **Current Media Processing** (3 files)
   - **Database**: Uses new processing architecture with configurable storage
   - **Location**: Determined by environment config (currently `storage/app/public/sermons/`)
   - **Examples**: UUID-based filenames with processing metadata
   - **Detection**: Managed by ProcessingRouter and media processing services

### Configuration Dependencies

- `PROCESSING_PERMANENT_DISK` → Media processing permanent storage
- `SERMON_STORAGE_DISK` → Sermon processing storage
- `LIVESTREAM_STORAGE_DISK` → Livestream processing storage
- Legacy files served by `SermonController::serveAudio()` with dual-path logic

## Migration Strategy

### Phase 1: Infrastructure Setup

**1.1 Install Required Dependencies**
```bash
composer require league/flysystem-aws-s3-v3
```

**1.2 Configure DigitalOcean Spaces**
```php
// config/filesystems.php - Add new disk configurations
'do_spaces' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_ACCESS_KEY_ID'),
    'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
    'region' => env('DO_SPACES_DEFAULT_REGION', 'nyc3'),
    'bucket' => env('DO_SPACES_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT', 'https://nyc3.digitaloceanspaces.com'),
    'use_path_style_endpoint' => false,
    'throw' => false,
    'visibility' => 'public',
    'bucket_endpoint' => true,
],

'do_spaces_private' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_ACCESS_KEY_ID'),
    'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
    'region' => env('DO_SPACES_DEFAULT_REGION', 'nyc3'),
    'bucket' => env('DO_SPACES_PRIVATE_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT', 'https://nyc3.digitaloceanspaces.com'),
    'use_path_style_endpoint' => false,
    'throw' => false,
    'visibility' => 'private',
    'bucket_endpoint' => true,
],
```

**1.3 Environment Configuration**
```env
# DigitalOcean Spaces Configuration
DO_SPACES_ACCESS_KEY_ID=your_access_key
DO_SPACES_SECRET_ACCESS_KEY=your_secret_key
DO_SPACES_DEFAULT_REGION=nyc3
DO_SPACES_BUCKET=crockenhill
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_CDN_ENDPOINT=https://crockenhill.nyc3.cdn.digitaloceanspaces.com

# Progressive Migration Configuration
PROCESSING_PERMANENT_DISK=do_spaces
PROCESSING_TEMP_DISK=local  # Keep local for temp files
SERMON_STORAGE_DISK=do_spaces
LIVESTREAM_STORAGE_DISK=do_spaces
LIVESTREAM_SERMON_DISK=do_spaces

# Legacy file serving
LEGACY_SERMON_DISK=do_spaces  # Where to serve legacy files from after migration
```

### Phase 2: Service Layer Updates

**2.1 Create Enhanced Storage Service**
```php
// app/Services/SermonStorageService.php
class SermonStorageService
{
    public function getSermonFileInfo(Sermon $sermon): array
    {
        // Determine which storage pattern this sermon uses
        if ($sermon->filetype && !str_contains($sermon->filename, '/')) {
            // Legacy pattern
            return [
                'type' => 'legacy',
                'disk' => config('filesystems.legacy_sermon_disk', 'do_spaces'),
                'path' => "legacy/sermons/{$sermon->filename}.{$sermon->filetype}",
                'original_path' => "media/sermons/{$sermon->filename}.{$sermon->filetype}"
            ];
        } elseif (str_contains($sermon->filename, '/')) {
            // Newer Laravel storage pattern
            return [
                'type' => 'storage',
                'disk' => config('sermon-processing.storage.disk', 'do_spaces'),
                'path' => $sermon->filename,
                'original_path' => $sermon->filename
            ];
        } else {
            // Current media processing pattern
            return [
                'type' => 'processing',
                'disk' => config('media-processing.storage.permanent_disk', 'do_spaces'),
                'path' => $sermon->filename,
                'original_path' => $sermon->filename
            ];
        }
    }

    public function getPublicUrl(Sermon $sermon): string
    {
        $info = $this->getSermonFileInfo($sermon);

        // Use CDN for public files if available
        if ($info['disk'] === 'do_spaces' && config('DO_SPACES_CDN_ENDPOINT')) {
            return config('DO_SPACES_CDN_ENDPOINT') . '/' . $info['path'];
        }

        return Storage::disk($info['disk'])->url($info['path']);
    }
}
```

**2.2 Update SermonController for Cloud Compatibility**
```php
// Update serveAudio method in SermonController
public function serveAudio(Sermon $sermon)
{
    if (!$sermon->filename) {
        abort(404, 'Audio file not found.');
    }

    $storageService = app(SermonStorageService::class);
    $fileInfo = $storageService->getSermonFileInfo($sermon);

    if (!Storage::disk($fileInfo['disk'])->exists($fileInfo['path'])) {
        abort(404, 'Audio file not found.');
    }

    // For cloud storage, redirect to CDN URL for better performance
    if ($fileInfo['disk'] === 'do_spaces' && config('DO_SPACES_CDN_ENDPOINT')) {
        return redirect($storageService->getPublicUrl($sermon));
    }

    // Fallback to Laravel serving (useful for private files or local storage)
    $path = Storage::disk($fileInfo['disk'])->path($fileInfo['path']);
    $name = basename($fileInfo['path']);

    return response()->file($path, [
        'Content-Type' => 'audio/mpeg',
        'Content-Disposition' => 'inline; filename="'.$name.'"',
        'Cache-Control' => 'public, max-age=3600',
    ]);
}
```

### Phase 3: Migration Commands

**3.1 Create Multi-Pattern Migration Command**
```php
// app/Console/Commands/MigrateSermonStorageCommand.php
class MigrateSermonStorageCommand extends Command
{
    protected $signature = 'sermons:migrate-storage
                           {--target=do_spaces : Target disk}
                           {--pattern= : Specific pattern (legacy|storage|processing)}
                           {--dry-run : Preview migration without executing}
                           {--batch-size=25 : Files per batch}';

    public function handle(): int
    {
        $targetDisk = $this->option('target');
        $specificPattern = $this->option('pattern');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $patterns = $specificPattern ? [$specificPattern] : ['legacy', 'storage', 'processing'];

        foreach ($patterns as $pattern) {
            $this->migratePattern($pattern, $targetDisk, $dryRun, $batchSize);
        }

        return 0;
    }

    private function migratePattern(string $pattern, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $query = Sermon::query();

        switch ($pattern) {
            case 'legacy':
                $sermons = $query->whereNotNull('filetype')
                    ->whereRaw('filename NOT LIKE "%/%"')
                    ->get();
                $this->migrateLegacySermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'storage':
                $sermons = $query->whereRaw('filename LIKE "%/%"')
                    ->whereNull('filetype')
                    ->get();
                $this->migrateStorageSermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'processing':
                // Handle sermons created by current media processing
                $this->migrateProcessingSermons($targetDisk, $dryRun, $batchSize);
                break;
        }
    }

    private function migrateLegacySermons($sermons, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $this->info("Migrating {$sermons->count()} legacy sermons");

        if ($dryRun) {
            foreach ($sermons as $sermon) {
                $sourcePath = public_path("media/sermons/{$sermon->filename}.{$sermon->filetype}");
                $targetPath = "legacy/sermons/{$sermon->filename}.{$sermon->filetype}";
                $this->line("Would migrate: {$sourcePath} → {$targetPath}");
            }
            return;
        }

        $chunks = $sermons->chunk($batchSize);
        $this->withProgressBar($chunks, function ($chunk) use ($targetDisk) {
            foreach ($chunk as $sermon) {
                try {
                    $sourcePath = public_path("media/sermons/{$sermon->filename}.{$sermon->filetype}");
                    $targetPath = "legacy/sermons/{$sermon->filename}.{$sermon->filetype}";

                    if (file_exists($sourcePath)) {
                        $content = file_get_contents($sourcePath);
                        Storage::disk($targetDisk)->put($targetPath, $content);

                        // Verify upload
                        if (Storage::disk($targetDisk)->exists($targetPath)) {
                            $this->info("✓ Migrated: {$sermon->filename}.{$sermon->filetype}");
                        }
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate {$sermon->filename}: " . $e->getMessage());
                }
            }
        });
    }

    private function migrateStorageSermons($sermons, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $this->info("Migrating {$sermons->count()} storage sermons");

        if ($dryRun) {
            foreach ($sermons as $sermon) {
                $this->line("Would migrate storage sermon: {$sermon->filename}");
            }
            return;
        }

        $storageService = app(CloudStorageService::class);
        $chunks = $sermons->chunk($batchSize);

        $this->withProgressBar($chunks, function ($chunk) use ($targetDisk, $storageService) {
            foreach ($chunk as $sermon) {
                try {
                    $storageService->migrateFile($sermon->filename, 'public', $targetDisk);
                    $this->info("✓ Migrated storage sermon: {$sermon->filename}");
                } catch (Exception $e) {
                    $this->error("Failed to migrate storage sermon {$sermon->filename}: " . $e->getMessage());
                }
            }
        });
    }
}
```

**3.2 Create Verification Command**
```php
// app/Console/Commands/VerifySermonStorageCommand.php
class VerifySermonStorageCommand extends Command
{
    protected $signature = 'sermons:verify-storage {--disk=do_spaces}';

    public function handle(): int
    {
        $disk = $this->option('disk');
        $storageService = app(SermonStorageService::class);

        $sermons = Sermon::all();
        $missing = [];
        $accessible = 0;

        $this->info("Verifying {$sermons->count()} sermons on {$disk} disk...");

        $this->withProgressBar($sermons, function ($sermon) use ($disk, $storageService, &$missing, &$accessible) {
            $fileInfo = $storageService->getSermonFileInfo($sermon);

            if (Storage::disk($disk)->exists($fileInfo['path'])) {
                $accessible++;
            } else {
                $missing[] = [
                    'id' => $sermon->id,
                    'title' => $sermon->title,
                    'filename' => $sermon->filename,
                    'expected_path' => $fileInfo['path'],
                    'pattern' => $fileInfo['type']
                ];
            }
        });

        $this->newLine(2);
        $this->info("✓ Accessible files: {$accessible}");

        if (count($missing) > 0) {
            $this->error("✗ Missing files: " . count($missing));
            $this->table(
                ['ID', 'Title', 'Filename', 'Expected Path', 'Pattern'],
                $missing
            );
        } else {
            $this->info("✓ All sermon files are accessible!");
        }

        return count($missing) > 0 ? 1 : 0;
    }
}
```

### Phase 4: Deployment Strategy

**4.1 Pre-Migration Testing**
```bash
# Test DigitalOcean Spaces connectivity
php artisan tinker
Storage::disk('do_spaces')->put('test.txt', 'Hello World');
Storage::disk('do_spaces')->exists('test.txt');
Storage::disk('do_spaces')->url('test.txt');
Storage::disk('do_spaces')->delete('test.txt');

# Preview migration scope
php artisan sermons:migrate-storage --dry-run
php artisan sermons:migrate-storage --dry-run --pattern=legacy
```

**4.2 Gradual Migration Process**

**Week 1: Setup & Test**
```bash
# Deploy configuration and test uploads
git pull origin main
composer install --optimize-autoloader --no-dev
php artisan config:cache

# Test new uploads work with DigitalOcean Spaces
# Upload a test sermon to verify end-to-end functionality
```

**Week 2: Migrate Non-Critical Files**
```bash
# Start with thumbnails and smaller files
php artisan sermons:migrate-storage --pattern=storage --batch-size=10
php artisan sermons:verify-storage
```

**Week 3: Migrate Legacy Files**
```bash
# Migrate legacy files (largest group)
php artisan sermons:migrate-storage --pattern=legacy --batch-size=25
php artisan sermons:verify-storage

# Update environment to serve from cloud
# Update LEGACY_SERMON_DISK=do_spaces in .env
php artisan config:cache
```

**Week 4: Complete Migration**
```bash
# Migrate remaining processing files
php artisan sermons:migrate-storage --pattern=processing --batch-size=5
php artisan sermons:verify-storage

# Final verification and cleanup
php artisan sermons:verify-storage
```

**4.3 Rollback Plan**
```env
# Emergency rollback - revert environment variables
PROCESSING_PERMANENT_DISK=public
SERMON_STORAGE_DISK=public
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=public
LEGACY_SERMON_DISK=public_images  # Back to public serving
```

### Phase 5: Optimization & Monitoring

**5.1 CDN Integration**
- Update `SermonStorageService::getPublicUrl()` to use CDN endpoints
- Implement cache headers for optimal CDN performance
- Monitor CDN hit rates and adjust cache policies

**5.2 Performance Monitoring**
- Track file access patterns and CDN performance
- Monitor DigitalOcean Spaces usage and costs
- Implement health checks for cloud storage connectivity

**5.3 Cleanup**
- Archive or delete local files after successful migration
- Remove legacy file serving code once fully migrated
- Update documentation and deployment procedures

## Benefits & Considerations

### Benefits
- **Cost Efficiency**: ~$5/month vs local storage costs
- **Scalability**: Automatic scaling without server storage limits
- **Performance**: CDN acceleration for global audience
- **Reliability**: Built-in redundancy and geographic distribution
- **Maintenance**: Reduced server storage management

### Considerations
- **Migration Complexity**: Three different storage patterns to handle
- **URL Compatibility**: Existing sermon links will use new CDN URLs
- **Bandwidth Costs**: Monitor transfer costs for large files
- **Access Control**: Consider whether to serve files directly from CDN or through Laravel
- **Legacy Support**: Maintain backward compatibility during transition

## Risk Mitigation

1. **Gradual Migration**: Migrate in phases to minimize risk
2. **Verification**: Comprehensive verification at each step
3. **Rollback Plan**: Quick reversion capability if issues arise
4. **Backup**: Maintain local files until migration fully verified
5. **Monitoring**: Real-time monitoring of file accessibility

This plan ensures a smooth transition while preserving all existing sermon files and maintaining compatibility with the current application architecture.