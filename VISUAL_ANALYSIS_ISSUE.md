# Visual Analysis Issue - Diagnosis & Fix

## Problem Summary
The visual analysis implementation is running but **failing to detect any song periods**, causing the system to fall back to RMS-only segmentation. This defeats the purpose of the visual analysis feature.

## What's Happening

### From the logs (Laravel.log - 2025-11-16):
```
[23:05:25] Visual analysis complete {"total_samples":232,"song_samples":0,"speech_samples":232}
[23:05:25] Song clustering complete {"raw_clusters":0,"merged_clusters":0,"final_clusters":0}
[23:05:25] WARNING: Insufficient song clusters detected {"clusters_found":0,"min_required":1}
[23:08:18] Using RMS-only segmentation (no visual data)
```

### The Issue
- Visual analysis ran successfully (232 frames sampled)
- **ALL frames classified as "speech"** - 0 classified as "song"
- No song clusters created
- System fell back to RMS-only mode
- Visual samples/clusters NOT stored in database (all NULL)

## Root Cause Analysis

The visual classification algorithm in `VisualAnalysisService` is not correctly identifying on-screen lyrics as "song" frames. There are likely two issues:

### 1. Classification Thresholds Too Strict
The thresholds in [config/media-processing.php](config/media-processing.php) may not match the actual visual characteristics of your song slides:

```php
'visual_analysis' => [
    'brightness_threshold' => 0.6,      // May be too high/low
    'contrast_threshold' => 0.7,        // May be too high/low
    'edge_density_threshold' => 0.5,    // May be too high/low
]
```

### 2. Missing Debug Output
We need to see the actual metrics being extracted to understand why classification is failing. The service logs:
- "Frame metrics extracted" ✓
- "Visual analysis complete" ✓

But NOT:
- What brightness/contrast/edge values were measured
- How they compare to thresholds
- Why frames were classified as speech vs song

## Data Storage Issue

Visual samples ARE being analyzed but NOT being stored in the database:

```php
// PerformVisualAnalysis.php line 138
$this->processingLog->update([
    'visual_samples' => json_encode($visualSamples),  // Not persisting
    'song_clusters' => json_encode($songClusters),    // Not persisting
    // ...
]);
```

Database shows:
- `visual_sample_count`: NULL
- `visual_samples`: NULL
- `song_clusters`: NULL

**Possible causes:**
1. Model not configured to cast JSON columns
2. Migration didn't run properly
3. JSON encoding failing silently

## Fixes Applied

### 1. ✅ Fixed MediaProcessingLog Model
Added missing fields to [app/Models/MediaProcessingLog.php](app/Models/MediaProcessingLog.php):
- Added to `$fillable` array: `visual_samples`, `song_clusters`, `visual_sample_count`, `visual_processing_time`
- Added to `$casts` array: `visual_samples => 'array'`, `song_clusters => 'array'`, `visual_sample_count => 'integer'`, `visual_processing_time => 'float'`
- Added to PHPDoc: All visual analysis properties

### 2. ✅ Fixed PerformVisualAnalysis Job
Removed manual `json_encode()` calls in [app/Jobs/PerformVisualAnalysis.php](app/Jobs/PerformVisualAnalysis.php):
- Changed from: `'visual_samples' => json_encode($visualSamples)`
- Changed to: `'visual_samples' => $visualSamples`
- Let Laravel's model casts handle JSON encoding automatically

### 3. ✅ Added Debug Logging
Added classification debugging in [app/Services/VisualAnalysisService.php](app/Services/VisualAnalysisService.php):
- Logs every 10 minutes OR when confidence >= 0.5
- Shows raw metrics (brightness, contrast, edge_density)
- Shows computed scores for each metric
- Shows final confidence and classification
- Shows all thresholds being used

## Next Steps - Testing

### 1. Start Docker and Re-upload Video
```bash
# Start Docker
sail up -d

# Delete old processing logs (optional, to clean up)
sail artisan tinker --execute="
App\Models\MediaProcessingLog::truncate();
App\Models\LivestreamSegment::truncate();
"

# Re-upload the 10-30.mkv video through the UI
```

### 2. Monitor Logs for Debug Output
```bash
# Watch logs in real-time
sail logs -f

# Or tail Laravel logs specifically
tail -f storage/logs/laravel.log | grep "Frame classification"
```

### 3. Check Visual Data is Stored
After processing completes:
```bash
sail artisan tinker --execute="
\$log = App\Models\MediaProcessingLog::latest()->first();
echo 'Visual Sample Count: ' . (\$log->visual_sample_count ?? 'null') . PHP_EOL;
echo 'Has Visual Samples: ' . (\$log->visual_samples ? 'yes (' . count(\$log->visual_samples) . ')' : 'no') . PHP_EOL;
echo 'Has Song Clusters: ' . (\$log->song_clusters ? 'yes (' . count(\$log->song_clusters) . ')' : 'no') . PHP_EOL;
"
```

### 4. Analyze Debug Logs
The new debug logging will show you:
- Actual brightness/contrast/edge values from your video
- Whether they're meeting the thresholds
- What adjustments to make if needed

### 5. Adjust Thresholds if Necessary
If the debug logs show metrics are consistently below thresholds, update [config/media-processing.php](config/media-processing.php):
```php
'visual_analysis' => [
    'brightness_threshold' => 0.4,   // Lower from 0.6 if needed
    'contrast_threshold' => 0.4,     // Lower from 0.7 if needed
    'edge_density_threshold' => 0.3, // Lower from 0.5 if needed
    'min_confidence' => 0.6,         // Lower from 0.7 if needed
]
```

### 6. Extract Sample Frames for Manual Inspection (Optional)
```bash
# Extract frames at known song times (23:44 and 29:14)
ffmpeg -ss 00:23:44 -i video.mkv -frames:v 1 frame_song1.png
ffmpeg -ss 00:29:14 -i video.mkv -frames:v 1 frame_song2.png
ffmpeg -ss 00:10:00 -i video.mkv -frames:v 1 frame_speech.png
```

Then manually check visual characteristics.

## Final Fixes Applied (2025-11-17)

### 4. ✅ Adjusted Visual Classification Thresholds
Updated [config/media-processing.php](config/media-processing.php) based on actual video metrics:

**Old thresholds (too strict):**
```php
'brightness_threshold' => 0.6,
'contrast_threshold' => 0.7,    // WAY too high!
'edge_density_threshold' => 0.5,
'min_confidence' => 0.7,
```

**New thresholds (realistic for this video):**
```php
'brightness_threshold' => 0.5,   // Lowered from 0.6
'contrast_threshold' => 0.25,    // Lowered from 0.7 (major fix!)
'edge_density_threshold' => 0.5, // Kept same
'min_confidence' => 0.5,         // Lowered from 0.7
```

**Rationale:**
- Video's max contrast is 0.279, so 0.7 threshold was impossible to meet
- Average brightness is 0.515, so 0.6 was barely reached
- Lowering thresholds allows frames with actual song characteristics to be detected

### 5. ✅ Created Frame Extraction Diagnostic Tool
New artisan command: `media:extract-frames`

**Usage:**
```bash
# Extract default song/speech samples
sail artisan media:extract-frames livestream/temp/video.mkv

# Extract at specific times
sail artisan media:extract-frames livestream/temp/video.mkv --timestamps=00:23:44 --timestamps=00:29:14

# Extract every 60 seconds
sail artisan media:extract-frames livestream/temp/video.mkv --interval=60

# Custom output directory
sail artisan media:extract-frames livestream/temp/video.mkv --output-dir=temp/debug-frames
```

This allows manual inspection of frames to understand visual characteristics.

## Expected Behavior After Fixes

After re-processing with new thresholds, logs should show:
```
Visual analysis complete {"total_samples":232,"song_samples":15-25,"speech_samples":207-217}
Song clustering complete {"raw_clusters":2-3,"merged_clusters":2,"final_clusters":2}
Visual analysis results stored {"sample_count":232,"cluster_count":2}
Using visual-guided RMS calibration for segmentation
```

Database should have:
- `visual_sample_count`: 232
- `visual_samples`: JSON array with 232 entries showing classifications
- `song_clusters`: JSON array with 2 clusters (around 23:44 and 29:14)
- Segments with `calibration_method`: 'per_song_visual'
- Segments with populated `visual_confidence` and `visual_sample_count`

## Testing the Fixes

### Step 1: Clear Config Cache and Restart Workers ⚠️ CRITICAL!
```bash
# Clear Laravel's config cache
sail artisan config:clear

# Restart queue workers to load new thresholds
sail artisan queue:restart

# Verify new thresholds are loaded
sail artisan tinker --execute="
echo 'Brightness: ' . config('media-processing.visual_analysis.brightness_threshold') . PHP_EOL;
echo 'Contrast: ' . config('media-processing.visual_analysis.contrast_threshold') . PHP_EOL;
"
# Should show: Brightness: 0.5, Contrast: 0.25
```

**Why this is critical:** Queue workers cache configuration in memory. Without restarting them, they'll continue using the old thresholds (0.6/0.7) even though the config file was changed!

### Step 2: Clear Old Data (Optional but Recommended)
```bash
sail artisan tinker --execute="
App\Models\MediaProcessingLog::truncate();
App\Models\LivestreamSegment::truncate();
App\Models\Sermon::where('source_type', 'livestream')->delete();
"
```

### Step 3: Re-upload Video
Upload the 10-30.mkv file through the web interface at:
`http://localhost/admin/sermons/upload` (or wherever your upload form is)

### Step 4: Monitor Processing
```bash
# Watch all logs
sail logs -f

# Or just Laravel logs
tail -f storage/logs/laravel.log | grep -E "Visual analysis|Frame classification|Song clustering|segmentation"
```

### Step 5: Verify Results
After processing completes (~10-15 minutes):

```bash
# Check visual data was stored
sail artisan tinker --execute="
\$log = App\Models\MediaProcessingLog::latest()->first();
echo 'Status: ' . \$log->status->value . PHP_EOL;
echo 'Visual Sample Count: ' . (\$log->visual_sample_count ?? 'null') . PHP_EOL;
echo 'Song Clusters: ' . (isset(\$log->song_clusters) ? count(\$log->song_clusters) : 'null') . PHP_EOL;

if (\$log->visual_samples) {
    \$songs = array_filter(\$log->visual_samples, fn(\$s) => \$s['classification'] === 'song');
    echo 'Song Samples: ' . count(\$songs) . PHP_EOL;
}

if (\$log->song_clusters) {
    foreach (\$log->song_clusters as \$i => \$cluster) {
        echo sprintf('Cluster %d: %s - %s' . PHP_EOL,
            \$i + 1,
            gmdate('H:i:s', \$cluster['start_estimate']),
            gmdate('H:i:s', \$cluster['end_estimate'])
        );
    }
}
"

# Check segmentation used visual calibration
sail artisan tinker --execute="
\$segments = App\Models\LivestreamSegment::latest('id')->take(5)->get();
foreach (\$segments as \$seg) {
    echo sprintf('%s: %s - %s [method: %s, visual_conf: %s]' . PHP_EOL,
        \$seg->classification,
        gmdate('H:i:s', \$seg->start_time),
        gmdate('H:i:s', \$seg->end_time),
        \$seg->calibration_method ?? 'null',
        \$seg->visual_confidence ?? 'null'
    );
}
"
```

### Step 6: Extract Frames for Manual Inspection (Optional)
```bash
# Find the temp video path
sail artisan tinker --execute="
\$log = App\Models\MediaProcessingLog::latest()->first();
echo \$log->source_file_path . PHP_EOL;
"

# Extract frames at song times
sail artisan media:extract-frames <path-from-above>
```

Check the extracted frames in `storage/app/temp/frames/` to see what songs actually look like visually.

## If Still Not Working

If songs are still not detected:

1. **Check debug logs** - Look for "Frame classification" messages showing actual metrics
2. **Extract frames manually** - See what brightness/contrast values they actually have
3. **Lower thresholds further** - If metrics are still below 0.25 contrast
4. **Check video quality** - Low quality videos may have poor contrast
5. **Verify song periods exist** - The video might not have on-screen lyrics at all
