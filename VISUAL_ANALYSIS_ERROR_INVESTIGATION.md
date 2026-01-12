# Visual Analysis Error Investigation

## Problem Summary

**Error**: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'visual_samples' in 'field list'`

**Location**: Production environment, during visual analysis job execution

**Processing ID**: `de830481-b1e1-4316-b0fc-8860db7ec982`

## Root Cause

The visual analysis feature was recently added in commit `edb9edf7` (Nov 23, 2025), which included:

1. ✅ New job: `PerformVisualAnalysis.php`
2. ✅ New services: `VisualAnalysisService`, `SongClusteringService`, `VideoSegmentationService`
3. ✅ Database migration: `2025_11_16_223228_add_visual_analysis_columns.php`
4. ✅ Model updates: `MediaProcessingLog.php` with new fields

**The Issue**: The migration file exists in the codebase but **has NOT been run in production**.

The `PerformVisualAnalysis` job (line 155 in [PerformVisualAnalysis.php:155](/Users/garethclarridge/Projects/crockenhill/app/Jobs/PerformVisualAnalysis.php#L155)) attempts to update these columns:

```php
$this->processingLog->update([
    'visual_samples' => $visualSamples,       // ❌ Missing column
    'song_clusters' => $songClusters,         // ❌ Missing column
    'visual_sample_count' => count($visualSamples), // ❌ Missing column
    'visual_processing_time' => $processingTime,    // ❌ Missing column
]);
```

But these columns don't exist in the production `media_processing_logs` table.

## Missing Database Columns

The following columns from the migration need to be added to production:

### media_processing_logs table:
- `visual_samples` (json, nullable) - Visual frame analysis samples
- `song_clusters` (json, nullable) - Clustered song periods
- `visual_sample_count` (integer, nullable) - Count of samples analyzed
- `visual_processing_time` (float, nullable) - Processing time in seconds

### livestream_segments table:
- `visual_confidence` (float, nullable) - Visual classification confidence
- `visual_sample_count` (integer, nullable) - Visual samples in segment
- `calibration_method` (string, nullable) - Calibration method used

## Solution

### Option 1: Run the Migration in Production (Recommended)

Run the existing migration on the production database:

```bash
# SSH into production server
php artisan migrate --force

# Or specifically:
php artisan migrate --path=/database/migrations/2025_11_16_223228_add_visual_analysis_columns.php --force
```

This is the cleanest solution as the migration already exists and is properly structured.

### Option 2: Manual SQL (If migration system unavailable)

If you cannot run migrations, execute this SQL directly:

```sql
-- Add columns to media_processing_logs
ALTER TABLE `media_processing_logs`
ADD COLUMN `visual_samples` JSON NULL COMMENT 'Visual frame analysis samples with timestamps and classifications' AFTER `rms_stats`,
ADD COLUMN `song_clusters` JSON NULL COMMENT 'Clustered song periods identified from visual analysis' AFTER `visual_samples`,
ADD COLUMN `visual_sample_count` INT NULL COMMENT 'Total number of visual samples analyzed' AFTER `song_clusters`,
ADD COLUMN `visual_processing_time` FLOAT NULL COMMENT 'Time taken for visual analysis in seconds' AFTER `visual_sample_count`;

-- Add columns to livestream_segments
ALTER TABLE `livestream_segments`
ADD COLUMN `visual_confidence` FLOAT NULL COMMENT 'Confidence score from visual classification (0-1)' AFTER `peak_rms`,
ADD COLUMN `visual_sample_count` INT NULL COMMENT 'Number of visual samples in this segment' AFTER `visual_confidence`,
ADD COLUMN `calibration_method` VARCHAR(255) NULL COMMENT 'Method used for threshold calibration: per_song_visual, adaptive, fixed, fallback' AFTER `visual_sample_count`;
```

### Option 3: Disable Visual Analysis (Temporary workaround)

If you need to quickly resolve the production issue, disable visual analysis in the config:

```env
# Add to .env
VISUAL_ANALYSIS_ENABLED=false
```

Then update [config/media-processing.php](/Users/garethclarridge/Projects/crockenhill/config/media-processing.php):

```php
'visual_analysis' => [
    'enabled' => env('VISUAL_ANALYSIS_ENABLED', true), // Change default to false
    // ... rest of config
],
```

The system has fallback logic built-in (see [PerformVisualAnalysis.php:130-144](/Users/garethclarridge/Projects/crockenhill/app/Jobs/PerformVisualAnalysis.php#L130-L144)) that will continue processing with RMS-only detection.

## Verification Steps

After applying the fix:

1. **Check migration status**:
   ```bash
   php artisan migrate:status | grep visual_analysis
   ```
   Should show "Ran" status.

2. **Verify column existence**:
   ```sql
   DESCRIBE media_processing_logs;
   DESCRIBE livestream_segments;
   ```

3. **Test with a new upload**:
   - Upload a livestream video
   - Monitor logs for successful visual analysis
   - Check that `visual_samples` column is populated

4. **Monitor error logs**:
   ```bash
   tail -f storage/logs/laravel.log | grep "visual_analysis"
   ```

## Related Files

- **Migration**: [database/migrations/2025_11_16_223228_add_visual_analysis_columns.php](/Users/garethclarridge/Projects/crockenhill/database/migrations/2025_11_16_223228_add_visual_analysis_columns.php)
- **Job**: [app/Jobs/PerformVisualAnalysis.php](/Users/garethclarridge/Projects/crockenhill/app/Jobs/PerformVisualAnalysis.php)
- **Model**: [app/Models/MediaProcessingLog.php](/Users/garethclarridge/Projects/crockenhill/app/Models/MediaProcessingLog.php)
- **Config**: [config/media-processing.php](/Users/garethclarridge/Projects/crockenhill/config/media-processing.php)

## Prevention

To prevent this issue in the future:

1. **Add migration check to deployment**:
   ```bash
   # In deployment script
   php artisan migrate:status
   php artisan migrate --force
   ```

2. **Add health check endpoint** that verifies required columns exist

3. **Consider adding a pre-flight check** in the `PerformVisualAnalysis` job:
   ```php
   if (!Schema::hasColumn('media_processing_logs', 'visual_samples')) {
       Log::warning('Visual analysis columns not found, skipping');
       return;
   }
   ```

## Timeline

- **Nov 23, 2025**: Visual analysis feature committed (edb9edf7)
- **Jan 12, 2026**: Error discovered in production (21:11:35)
- Migration exists but was not run during deployment

## Impact

- Visual analysis jobs are failing
- System is NOT failing completely (fallback to RMS-only is working)
- No data loss or corruption
- Livestream processing continues but without visual analysis benefits

## Recommended Action

**Run the migration immediately in production** (Option 1). The migration is safe, adds nullable columns with no data changes, and will enable the full visual analysis pipeline.
