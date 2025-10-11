# Production Upload Fix

## Problem
Livestream uploads fail after ~4 minutes with "files.0 failed to upload" error.

## Root Causes Found

### 1. Missing Directory (CRITICAL)
- `storage/app/livewire-tmp` doesn't exist in production
- Livewire can't save uploaded chunks without this directory

### 2. Low Disk Space (CRITICAL)
- Only 2.29GB free (90.48% usage)
- Need minimum 3GB free for 1.4GB livestream upload (file + temp files + processing)

## Fix Steps

### Step 1: Free up disk space
```bash
# Check what's using space
du -h /var/www/laravel/storage | sort -rh | head -20

# Clean old logs
php artisan log:clear  # if available
# OR manually:
rm -f storage/logs/*.log.1
rm -f storage/logs/*.log.gz

# Clean old temp files
find storage/app/temp -type f -mtime +7 -delete
find storage/app/livewire-tmp -type f -mtime +1 -delete 2>/dev/null

# Clean Laravel cache
php artisan cache:clear
php artisan view:clear
php artisan config:clear
```

### Step 2: Create required directories
```bash
php artisan upload:fix-directories
```

This will create and set permissions for:
- `storage/app/livewire-tmp` (Livewire temporary uploads)
- `storage/app/temp/livewire-upload` (Your custom temp storage)
- `storage/app/temp` (General temp files)
- `storage/app/public/sermons` (Sermon files)
- `storage/app/transcripts` (Transcript storage)

### Step 3: Verify fix
```bash
php artisan upload:diagnose
```

Should show:
- ✅ Livewire Temp Directory: Exists
- ✅ Livewire Temp Directory: Writable
- At least 3GB free disk space

### Step 4: Test upload
Upload a small video file first to verify everything works.

## Prevention

### Automated cleanup
Add to cron (crontab -e):
```
# Clean old Livewire temp files daily at 2am
0 2 * * * cd /var/www/laravel && php artisan schedule:run >> /dev/null 2>&1
```

Then add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Clean old Livewire temp files
    $schedule->command('cleanup:livewire-tmp')->daily();
}
```

### Disk space monitoring
Set up alerts when disk usage > 80%

## Quick Reference

**Fix command:**
```bash
php artisan upload:fix-directories
```

**Diagnose command:**
```bash
php artisan upload:diagnose
```

**Clean temp files manually:**
```bash
find storage/app/livewire-tmp -type f -mtime +1 -delete
find storage/app/temp -type f -mtime +7 -delete
```
