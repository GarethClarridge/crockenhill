# Media Processing Refactor - Production Deployment Guide

**Date:** October 11, 2025
**Target:** Production environment with legacy sermon data
**Risk Level:** Low-Medium (database changes, no API breaking changes)

---

## Pre-Deployment Checklist

### 1. Code Quality Verification ✓

```bash
# Run all tests
sail artisan test --parallel

# Static analysis
sail composer phpstan

# Code formatting
sail composer exec pint

# Verify all tests pass (especially integration tests)
```

**Expected Result:** All tests passing, 0 PHPStan errors

---

### 2. Database Backup 🔴 CRITICAL

Before any deployment, create a complete database backup:

```bash
# On production server
php artisan db:backup

# Or manually via your hosting provider's control panel
# Store backup securely with timestamp
```

**Verify backup integrity** before proceeding.

---

### 3. Feature Flag Preparation (Optional but Recommended)

Consider adding a feature flag to control new processing:

```php
// config/features.php
return [
    'use_unified_media_processing' => env('USE_UNIFIED_MEDIA_PROCESSING', true),
];
```

This allows quick rollback without code deployment.

---

## Deployment Steps

### Phase 1: Database Migration (Zero Downtime)

**Timeline:** 5-10 minutes
**Downtime:** None (new table created alongside old ones)

```bash
# 1. Deploy code to production (via Git)
git checkout master
git pull origin master

# 2. Run new migrations ONLY
php artisan migrate --path=database/migrations/2025_10_02_140532_create_media_processing_logs_table.php
php artisan migrate --path=database/migrations/2025_10_02_140717_update_livestream_segments_to_use_media_processing_log.php
php artisan migrate --path=database/migrations/2025_10_02_143000_fix_livestream_segments_foreign_keys.php

# 3. Verify new table exists
php artisan tinker
>>> \App\Models\MediaProcessingLog::count()
=> 0  # Expected - no records yet
```

**What This Does:**
- Creates `media_processing_logs` table (new unified model)
- Updates `livestream_segments` foreign key to point to new table
- Old tables (`sermon_processing_logs`, `livestream_processing_logs`) remain untouched

**Rollback:** Safe - old tables still exist, old code still works

---

### Phase 2: Application Code Deployment

**Timeline:** 2-5 minutes
**Downtime:** Brief (during deployment)

```bash
# 1. Put application in maintenance mode (optional but recommended)
php artisan down --refresh=15 --retry=60 --secret="your-secret-token"
# Access via: https://yoursite.com/your-secret-token

# 2. Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 3. Install dependencies (if any composer changes)
composer install --no-dev --optimize-autoloader

# 4. Restart queue workers (CRITICAL - they cache code)
php artisan queue:restart
# Or via Supervisor: supervisorctl restart all

# 5. Verify queues are running
php artisan queue:monitor --max=3

# 6. Bring application back up
php artisan up
```

**What This Does:**
- Deploys new unified code (MediaProcessingLog, unified jobs)
- All new uploads will use new system
- Old processing logs remain readable but inactive

---

### Phase 3: Verification & Monitoring

**Timeline:** 30-60 minutes
**Action:** Monitor new uploads

```bash
# 1. Test audio upload via API or web UI
# Upload a small test audio file (~1MB)

# 2. Monitor processing log creation
php artisan tinker
>>> \App\Models\MediaProcessingLog::latest()->first()
# Verify processing_type, status, file paths

# 3. Watch queue processing
php artisan queue:work --once  # Process one job
tail -f storage/logs/laravel.log  # Monitor logs

# 4. Test video upload
# Upload a small test video file (~5MB)

# 5. Verify sermon creation
>>> \App\Models\Sermon::latest()->first()
# Check audio_file_path, transcript_file_path fields

# 6. Test livestream upload (if applicable)
# Upload a test livestream recording
```

**Expected Results:**
- ✅ MediaProcessingLog created with correct `processing_type`
- ✅ Jobs dispatched and processed successfully
- ✅ Sermon record created with correct file paths
- ✅ No errors in logs
- ✅ Old sermon data still accessible

---

### Phase 4: Sermon Field Migration (Optional - Breaking Change)

**Timeline:** 5-10 minutes
**Risk:** MEDIUM - Renames fields, requires code update

⚠️ **WARNING:** This step renames Sermon model fields. Deploy AFTER verifying Phase 1-3 work correctly.

```bash
# 1. Run field standardization migration
php artisan migrate --path=database/migrations/2025_10_03_071419_standardize_sermon_file_path_fields.php

# 2. Verify field renames
php artisan tinker
>>> Schema::hasColumn('sermons', 'audio_file_path')
=> true
>>> Schema::hasColumn('sermons', 'filename')
=> false  # Old field renamed
```

**What This Does:**
- Renames `filename` → `audio_file_path`
- Renames `transcript_path` → `transcript_file_path`
- Renames `thumbnail_path` → `thumbnail_file_path`

**Compatibility:** Code already updated to use new names

---

### Phase 5: Cleanup Old Tables (Optional - After Stable Period)

**Timeline:** 1-2 minutes
**Risk:** HIGH - Permanent deletion

⚠️ **WAIT AT LEAST 7-14 DAYS** before running this step to ensure no issues.

```bash
# 1. Verify old tables are no longer being written to
php artisan tinker
>>> \DB::table('sermon_processing_logs')->where('created_at', '>', now()->subDay())->count()
=> 0  # Should be 0

>>> \DB::table('livestream_processing_logs')->where('created_at', '>', now()->subDay())->count()
=> 0  # Should be 0

# 2. Create manual backup of old tables (optional)
mysqldump -u user -p database sermon_processing_logs > sermon_processing_logs_backup.sql
mysqldump -u user -p database livestream_processing_logs > livestream_processing_logs_backup.sql

# 3. Drop old tables via migration
php artisan migrate --path=database/migrations/YYYY_MM_DD_drop_old_processing_log_tables.php
```

**Create this migration manually:**

```php
// database/migrations/YYYY_MM_DD_drop_old_processing_log_tables.php
public function up(): void
{
    Schema::dropIfExists('sermon_processing_logs');
    Schema::dropIfExists('livestream_processing_logs');
}

public function down(): void
{
    // No rollback - tables permanently deleted
    throw new \Exception('Cannot rollback - old tables deleted');
}
```

---

## Monitoring Checklist

### Immediate (First 24 Hours)

- [ ] Monitor error logs: `tail -f storage/logs/laravel.log`
- [ ] Check queue failures: `php artisan queue:failed`
- [ ] Verify sermon creation: Check admin panel or database
- [ ] Monitor S3/Spaces uploads: Check storage provider dashboard
- [ ] Test status API: `GET /api/sermons/processing/{id}/status`

### Short-term (First Week)

- [ ] Compare processing success rates (old vs new)
- [ ] Monitor queue processing times
- [ ] Check for any failed jobs: `php artisan horizon:list` or `queue:failed`
- [ ] Verify transcript generation working
- [ ] Verify AI analysis working
- [ ] Check thumbnail generation (video/livestream)

### Long-term (Before Cleanup)

- [ ] Confirm zero writes to old tables (7-14 days)
- [ ] Verify all edge cases working (retries, failures, cancellations)
- [ ] Confirm old sermon data still accessible
- [ ] Performance metrics stable

---

## Rollback Procedures

### Rollback Level 1: Feature Flag (Immediate)

If you implemented feature flags:

```bash
# Disable new processing, revert to old system
php artisan tinker
>>> config(['features.use_unified_media_processing' => false]);
>>> \Artisan::call('config:cache');

# Or set environment variable
echo "USE_UNIFIED_MEDIA_PROCESSING=false" >> .env
php artisan config:cache
php artisan queue:restart
```

**Timeline:** 30 seconds
**Impact:** New uploads use old system

---

### Rollback Level 2: Code Revert (Fast)

If critical issues found:

```bash
# 1. Put site in maintenance mode
php artisan down

# 2. Revert to previous Git commit
git log --oneline -5  # Find commit before refactor
git revert <commit-hash>
# Or: git reset --hard <commit-hash> (if no new commits)

# 3. Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 4. Restart queues
php artisan queue:restart

# 5. Bring site back up
php artisan up
```

**Timeline:** 2-5 minutes
**Impact:** Reverts to old processing system, new MediaProcessingLog records orphaned but harmless

---

### Rollback Level 3: Database Rollback (Last Resort)

If database corruption or critical data issues:

```bash
# 1. Restore database backup
mysql -u user -p database < backup_YYYY_MM_DD.sql

# 2. Revert code (Level 2 steps above)

# 3. Verify old system working
php artisan tinker
>>> \App\Models\SermonProcessingLog::count()
>>> \App\Models\Sermon::latest()->first()
```

**Timeline:** 10-30 minutes (depending on database size)
**Impact:** Loses all data since backup

---

## Success Criteria

### Deployment Successful If:

✅ New audio uploads create `MediaProcessingLog` with type='audio'
✅ New video uploads create `MediaProcessingLog` with type='video'
✅ New livestream uploads create `MediaProcessingLog` with type='livestream'
✅ All jobs process successfully (transcription, AI analysis, thumbnails)
✅ Sermon records created with correct file paths
✅ Old sermon data still accessible and displaying correctly
✅ Status API returns consistent responses
✅ Zero errors in application logs
✅ Queue workers processing jobs without failures

---

## Common Issues & Solutions

### Issue 1: "Class MediaProcessingLog not found"

**Cause:** Composer autoload cache outdated

```bash
composer dump-autoload
php artisan config:clear
```

---

### Issue 2: Queue jobs failing with "column not found"

**Cause:** Migration not run or cache issue

```bash
# Verify migration ran
php artisan migrate:status

# Clear cache
php artisan config:clear

# Restart queue workers (critical!)
php artisan queue:restart
```

---

### Issue 3: Old processing logs not displaying

**Cause:** Old tables dropped too early or code issue

**Solution:**
```bash
# If tables still exist, old system should work
# If tables dropped, restore from backup
mysql -u user -p database < sermon_processing_logs_backup.sql
```

---

### Issue 4: S3/Spaces upload failures

**Cause:** Storage configuration or permissions

```bash
# Test S3 connection
php artisan tinker
>>> Storage::disk('spaces')->put('test.txt', 'test');
>>> Storage::disk('spaces')->exists('test.txt');
=> true
>>> Storage::disk('spaces')->delete('test.txt');

# Check .env configuration
grep SPACES .env
grep AWS .env
```

---

## Post-Deployment Cleanup

After 14+ days of stable operation:

1. ✅ Drop old tables (Phase 5 above)
2. ✅ Delete old model files (already done in refactor)
3. ✅ Delete old job files (already done in refactor)
4. ✅ Update documentation
5. ✅ Archive old migration files (optional)
6. ✅ Remove feature flags (if used)

---

## Communication Plan

### Pre-Deployment

**To Users:**
> "We're improving our media processing system. You may experience brief downtime (2-5 minutes) during the update. All existing sermons will remain accessible."

### During Deployment

**Status Page:**
> "System maintenance in progress. Sermon uploads temporarily unavailable. Expected completion: [TIME]"

### Post-Deployment

**To Users:**
> "Media processing update complete. Please report any issues accessing sermons or uploading content."

### If Issues Found

**To Users:**
> "We're investigating an issue with media uploads. Uploads are temporarily disabled while we resolve this. Your existing content is safe."

---

## Contact & Support

**Deployment Lead:** [Your Name]
**Backup Contact:** [Backup Person]
**Emergency Rollback Authority:** [Decision Maker]

**Escalation Path:**
1. Check monitoring dashboards
2. Review application logs
3. Consult this deployment guide
4. Execute appropriate rollback level
5. Notify team via [communication channel]

---

## Deployment Sign-off

- [ ] Pre-deployment checklist completed
- [ ] Database backup verified
- [ ] Team notified of deployment window
- [ ] Rollback procedures reviewed
- [ ] Monitoring tools ready

**Deployed By:** _____________
**Date/Time:** _____________
**Git Commit:** _____________
**Rollback Tested:** Yes / No

---

## Timeline Summary

| Phase | Duration | Downtime | Risk |
|-------|----------|----------|------|
| 1. Database Migration | 5-10 min | None | Low |
| 2. Code Deployment | 2-5 min | Brief | Low |
| 3. Verification | 30-60 min | None | - |
| 4. Field Migration | 5-10 min | None | Medium |
| 5. Cleanup (later) | 1-2 min | None | High |

**Total Initial Deployment:** ~15-30 minutes
**Total with Monitoring:** ~1-2 hours
**Cleanup:** 7-14 days later

---

## Notes

- This refactor is **backward compatible** - old sermon data remains accessible
- New uploads immediately use new unified system
- No API breaking changes - all endpoints maintain same interface
- Queue workers MUST be restarted for code changes to take effect
- Feature flags provide instant rollback capability
- Old tables can remain in database indefinitely if desired

**Questions?** Review the [aggressive refactor plan](media-processing-aggressive-refactor-plan.md) for architectural details.