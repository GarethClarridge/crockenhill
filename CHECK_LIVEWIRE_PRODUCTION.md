# Livewire Upload Broken in Production

## Symptoms
- Corrupted filename display: `�+��*�`
- Wrong file path: `livewire-tmp/livewire-tmp` (doubled path)
- `getSize()` fails on uploaded files

## What This Means
Livewire successfully uploads the file chunks BUT cannot read them back. This suggests:

1. **File permissions issue** - Livewire uploads as one user but reads as another
2. **Disk configuration change** - Something changed in `config/filesystems.php`
3. **Livewire version mismatch** - Composer dependencies changed

## Diagnostic Steps

### 1. Check what files actually exist
```bash
ls -la storage/app/livewire-tmp/
```

Should show files like `livewire-file:xxxxx` with correct permissions (644 or 664).

### 2. Check file permissions
```bash
stat storage/app/livewire-tmp/*
```

Owner should match web server user (www-data, nginx, etc).

### 3. Check filesystem config
```bash
grep -A 10 "temporary_file_upload" config/livewire.php
```

Should NOT specify a custom disk - should be `null` (default).

### 4. Compare Livewire versions
```bash
# On production
composer show livewire/livewire

# Compare with local
```

## Quick Fixes to Try

### Fix 1: Reset livewire-tmp directory
```bash
rm -rf storage/app/livewire-tmp
php artisan upload:fix-directories
chmod 775 storage/app/livewire-tmp
```

### Fix 2: Check disk configuration
In `config/livewire.php`, ensure:
```php
'temporary_file_upload' => [
    'disk' => null,  // MUST be null, not 'local'
```

### Fix 3: Clear all caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

## Why This Worked Before

Something changed in production between commit `184cd533` and now:
- Deployment script changes?
- Server configuration changes?
- Composer dependency updates?
- File permissions reset?

Check deployment logs for what changed.
