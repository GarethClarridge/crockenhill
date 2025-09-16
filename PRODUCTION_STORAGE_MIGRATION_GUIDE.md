# Production Storage Migration Guide
# From Local Storage to DigitalOcean Spaces

This guide provides step-by-step instructions for safely migrating sermon files from local storage to DigitalOcean Spaces in a production environment.

## Prerequisites

### 1. DigitalOcean Spaces Setup

#### Create Spaces Bucket
1. Log into DigitalOcean Control Panel
2. Navigate to **Spaces Object Storage**
3. Create bucket:
   - **Bucket name**: `crockenhill` (public read access)
4. Note the region (e.g., `nyc3`, `ams3`, `sfo3`)

#### Generate API Keys
1. Go to **API** → **Spaces Keys**
2. Generate a new Spaces access key
3. Save the **Access Key ID** and **Secret Access Key** securely

#### Configure CDN (Optional but Recommended)
1. In Spaces, go to **Settings** → **CDN**
2. Enable CDN for the bucket
3. Note the CDN endpoint URL (e.g., `https://crockenhill.nyc3.cdn.digitaloceanspaces.com`)

### 2. Server Prerequisites

```bash
# Ensure required PHP extensions are installed
php -m | grep -E "(curl|openssl|fileinfo)"

# Check available disk space (you'll need space for temporary copies during migration)
df -h

# Verify composer dependencies are installed
composer show league/flysystem-aws-s3-v3
```

## Pre-Migration Steps

### 1. Verify DigitalOcean Droplet Backup

**✅ If you have recent DigitalOcean droplet backups, you can skip file-level backups**

```bash
# Check DigitalOcean Control Panel for recent droplet backup
# Ensure backup is within last 24-48 hours
# Droplet backups provide complete system state recovery
```

### 2. Minimal Database Backup (Optional)

```bash
# Quick database export for sermon metadata (small file)
mysqldump -u username -p database_name sermons > /tmp/sermons-backup-$(date +%Y%m%d).sql

# This is tiny compared to audio files and provides quick metadata recovery
# File will be small (typically < 1MB vs GB of audio files)
```

### 3. Current Storage Assessment

```bash
# Check current disk usage
df -h

# Check sermon file sizes
du -sh public/media/sermons/ storage/app/public/sermons/

# Note: Migration will HELP with space issues by moving files to cloud
```

### 4. Test Environment Setup (Optional)

If you have a staging environment available:

```bash
# Copy production database to staging
# Copy a subset of sermon files to staging
# Test the migration process completely before production
# Note: Can skip if droplet backups provide sufficient safety net
```

## Environment Configuration

### 1. Add DigitalOcean Configuration

Add to your production `.env` file:

```bash
# DigitalOcean Spaces Configuration
DO_SPACES_ACCESS_KEY_ID=your_actual_access_key_here
DO_SPACES_SECRET_ACCESS_KEY=your_actual_secret_key_here
DO_SPACES_DEFAULT_REGION=nyc3
DO_SPACES_BUCKET=crockenhill
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_CDN_ENDPOINT=https://crockenhill.nyc3.cdn.digitaloceanspaces.com

# Start with local storage, change these gradually during migration
PROCESSING_PERMANENT_DISK=public
SERMON_STORAGE_DISK=public
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=public
LEGACY_SERMON_DISK=public_images  # Will change to do_spaces after migration
```

### 2. Clear Application Cache

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
```

## Migration Implementation

**🎯 Space-Efficient Migration Strategy:**
- Files are **COPIED** to cloud first (originals remain on server)
- Verify cloud files work correctly before deleting local copies
- **Net result**: More free disk space after migration
- Droplet backups provide complete rollback capability

### Week 1: Setup and Connectivity Testing

#### Day 1-2: Initial Setup

```bash
# 1. Deploy the migration code to production
git pull origin main
composer install --optimize-autoloader --no-dev

# 2. Test DigitalOcean Spaces connectivity
php artisan tinker
```

In Tinker:
```php
// Test basic connectivity
Storage::disk('do_spaces')->put('test-connectivity.txt', 'Hello from ' . now());
Storage::disk('do_spaces')->exists('test-connectivity.txt');
$url = Storage::disk('do_spaces')->url('test-connectivity.txt');
echo $url;

// Test CDN if configured
// Visit the URL in browser to verify CDN is working

// Clean up test file
Storage::disk('do_spaces')->delete('test-connectivity.txt');
exit
```

#### Day 3-4: Migration Preview

```bash
# Preview the complete migration scope
php artisan sermons:migrate-storage --dry-run

# Preview each pattern separately
php artisan sermons:migrate-storage --dry-run --pattern=legacy
php artisan sermons:migrate-storage --dry-run --pattern=storage
php artisan sermons:migrate-storage --dry-run --pattern=processing

# Get current storage statistics
php artisan sermons:verify-storage --disk=public
```

**Expected Output Analysis:**
- Note total number of files per pattern
- Estimate total storage size needed
- Identify any missing or problematic files

#### Day 5-7: Test Migration with Subset

```bash
# Test with a small batch first (modify command to limit results)
# This requires temporarily modifying the command or testing on staging

# Monitor system resources during test migration
htop  # or top
iostat 1  # monitor disk I/O
```

### Week 2: Migrate Non-Critical Files

#### Storage Pattern Files (Newer Manual Uploads)

```bash
# These are typically the smallest group and safest to start with
php artisan sermons:migrate-storage --pattern=storage --batch-size=5

# Monitor the process
tail -f storage/logs/laravel.log

# Verify migration success
php artisan sermons:verify-storage --disk=do_spaces
```

**Verification Steps:**
1. Check that files are accessible via CDN URLs
2. Test sermon playback from the website
3. Verify no broken links on sermon pages

**Free Up Space (After Verification):**
```bash
# Only after confirming cloud files work perfectly
# Remove local copies of migrated storage pattern files
rm -rf storage/app/public/sermons/sermons/*

# Check freed space
df -h
```

#### Processing Pattern Files

```bash
# Migrate processing files (current media processing)
php artisan sermons:migrate-storage --pattern=processing --batch-size=3

# Verify
php artisan sermons:verify-storage --disk=do_spaces
```

### Week 3: Migrate Legacy Files (High Risk)

**⚠️ Critical Phase - Legacy files are the largest group**

#### Pre-Migration Checks

```bash
# Verify all previous migrations are successful
php artisan sermons:verify-storage --disk=do_spaces

# Check available bandwidth and storage
df -h
speedtest-cli  # if available
```

#### Legacy Migration

```bash
# Start with smaller batches for legacy files
php artisan sermons:migrate-storage --pattern=legacy --batch-size=10

# Monitor progress closely
watch -n 10 'php artisan sermons:verify-storage --disk=do_spaces | tail -20'

# If migration is slow, consider larger batches
php artisan sermons:migrate-storage --pattern=legacy --batch-size=25
```

#### Switch to Cloud Serving

Once legacy files are migrated and verified:

```bash
# Update environment to serve legacy files from cloud
# Edit .env file:
LEGACY_SERMON_DISK=do_spaces

# Clear cache
php artisan config:clear
php artisan config:cache
```

**Critical Testing:**
1. Test old sermon URLs still work
2. Check that audio playback works from CDN
3. Verify download functionality
4. Test on multiple devices/browsers

**Free Up Space (After Legacy Verification):**
```bash
# Only after confirming ALL legacy files work from cloud
# This will free up the most space (legacy files are typically largest group)
# Be very careful - verify thoroughly first

# Check what will be removed
du -sh public/media/sermons/

# Remove legacy files (after thorough testing)
rm -rf public/media/sermons/*

# Check freed space
df -h
```

### Week 4: Complete Migration and Optimization

#### Final Migration Steps

```bash
# Complete any remaining files
php artisan sermons:migrate-storage --target=do_spaces

# Final verification
php artisan sermons:verify-storage --disk=do_spaces
```

#### Update All Storage Configuration

Update `.env` to use cloud storage for all new uploads:

```bash
# Update to use cloud storage for new files
PROCESSING_PERMANENT_DISK=do_spaces
SERMON_STORAGE_DISK=do_spaces
LIVESTREAM_STORAGE_DISK=do_spaces
LIVESTREAM_SERMON_DISK=do_spaces
LEGACY_SERMON_DISK=do_spaces

# Clear cache
php artisan config:clear
php artisan config:cache
```

## Post-Migration Steps

### 1. Performance Testing

```bash
# Test website performance
# - Check sermon page load times
# - Test audio streaming performance
# - Verify CDN cache hit rates
```

### 2. Monitoring Setup

#### Log Monitoring
Monitor for these error patterns:
```bash
# Check for storage-related errors
grep -i "storage\|s3\|spaces" storage/logs/laravel.log

# Monitor for 404 errors on sermon files
grep "404.*sermon" /var/log/nginx/access.log  # or Apache logs
```

#### Health Checks
Add monitoring for:
- DigitalOcean Spaces connectivity
- CDN performance
- Sermon file accessibility

### 3. Space Management and Final Cleanup

**Progressive Space Recovery During Migration:**
- Space is freed up **gradually** as each pattern is migrated and verified
- Most space recovery happens during migration weeks (not after)
- Final cleanup is minimal (just any remaining temporary files)

```bash
# Final verification that all files are accessible from cloud
php artisan sermons:verify-storage --disk=do_spaces

# Clean up any remaining temporary files
find /tmp -name "*sermon*" -mtime +7 -delete

# Verify final disk space improvement
df -h
echo "Space recovery complete!"
```

**Expected Space Recovery:**
- **Week 2**: 10-20% space freed (storage pattern files)
- **Week 3**: 60-80% space freed (legacy files - largest group)
- **Week 4**: 90%+ space freed (all patterns migrated)

## Rollback Plan

### Emergency Rollback

**Option 1: Quick Configuration Rollback (if files still exist locally)**

```bash
# 1. Immediately revert environment configuration
PROCESSING_PERMANENT_DISK=public
SERMON_STORAGE_DISK=public
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=public
LEGACY_SERMON_DISK=public_images

# 2. Clear cache
php artisan config:clear
php artisan config:cache

# 3. Verify local files are accessible
php artisan sermons:verify-storage --disk=public
```

**Option 2: Full Droplet Restoration (if local files were deleted)**

```bash
# 1. Go to DigitalOcean Control Panel
# 2. Navigate to Droplets → Your Droplet → Backups
# 3. Select backup from before migration started
# 4. Restore droplet to previous state
# 5. This restores entire server to pre-migration state
```

### Partial Rollback

To rollback specific patterns:

```bash
# Rollback only legacy files
LEGACY_SERMON_DISK=public_images

# Rollback new uploads
PROCESSING_PERMANENT_DISK=public
SERMON_STORAGE_DISK=public
```

## Monitoring and Maintenance

### Daily Checks (First Month)

```bash
# Verify all sermons are accessible
php artisan sermons:verify-storage --disk=do_spaces

# Check error logs
grep -i "error\|exception" storage/logs/laravel.log | tail -20

# Monitor DigitalOcean Spaces usage
# Check bandwidth and storage usage in DO control panel
```

### Weekly Checks

1. **Performance Monitoring**
   - Check CDN hit rates
   - Monitor page load times
   - Verify audio streaming performance

2. **Cost Monitoring**
   - Review DigitalOcean billing
   - Monitor bandwidth usage
   - Check storage growth

3. **Backup Verification**
   - Ensure backups are still being created
   - Test backup restoration process

### Monthly Maintenance

1. **Clean up unused files**
2. **Review access patterns**
3. **Optimize CDN cache settings**
4. **Update documentation**

## Troubleshooting Common Issues

### Connection Issues

```bash
# Test DNS resolution
nslookup nyc3.digitaloceanspaces.com

# Test connectivity
curl -I https://nyc3.digitaloceanspaces.com

# Check firewall rules
sudo ufw status  # or iptables -L
```

### Authentication Issues

```bash
# Verify credentials in tinker
php artisan tinker
config('filesystems.disks.do_spaces.key')
config('filesystems.disks.do_spaces.secret')
```

### Permission Issues

```bash
# Check if files have correct permissions
ls -la storage/app/public/sermons/

# Ensure web server can read files
sudo chown -R www-data:www-data storage/app/public/sermons/
```

### CDN Issues

1. **Check CDN configuration** in DigitalOcean panel
2. **Verify CORS settings** if needed
3. **Clear CDN cache** if serving stale content
4. **Test with direct Spaces URLs** to isolate CDN issues

## Success Criteria

✅ **Migration is successful when:**

1. All sermons accessible via website
2. Audio playback works from all sources
3. No 404 errors for sermon files
4. CDN is serving files correctly
5. Page load times are improved or maintained
6. No data loss verified by file count/size comparison
7. All three storage patterns working correctly
8. **Significant disk space freed up** (60-90% of sermon file storage)

## 🎯 **Key Benefits for Space-Constrained Servers**

**✅ This migration SOLVES space problems:**
- **Progressive space recovery**: Free up space during migration, not after
- **No temporary space needed**: Files copied to cloud, then local files deleted
- **Immediate benefits**: Start seeing space freed up from Week 2
- **Safety net**: Droplet backups provide complete rollback capability
- **Future-proof**: All new sermon uploads go directly to cloud

**📊 Expected Outcomes:**
- **Before**: Server running out of space with growing sermon collection
- **After**: 60-90% more free space + unlimited cloud storage for future growth

## Emergency Contacts

- **DigitalOcean Support**: [Support ticket system]
- **Server Administrator**: [Contact info]
- **Application Developer**: [Contact info]
- **Backup Recovery**: [Process documentation location]

## Security Considerations

1. **Rotate Access Keys** after migration completion
2. **Review bucket permissions** to ensure appropriate access
3. **Monitor access logs** for unusual activity
4. **Implement rate limiting** if not already present
5. **Regular security audits** of cloud storage configuration

---

**Remember**: This migration affects the core functionality of your church website. Always test thoroughly in staging before applying to production, and have your rollback plan ready.