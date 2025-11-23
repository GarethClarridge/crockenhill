# Visual Song Detection Implementation Plan

## Executive Summary

Replace the current RMS-only song detection with a hybrid visual-first approach that uses frame analysis to identify songs (via on-screen lyrics), then calculates per-song adaptive RMS thresholds for precise boundary detection.

**Current Problem:** RMS-based detection is missing some songs or incorrectly including songs in sermon segments because it cannot reliably distinguish song volume from speech volume.

**Proposed Solution:** Visual analysis classifies periods as song/speech, then RMS finds precise boundaries using per-song calibrated thresholds.

**Processing Time Impact:** +20-30 seconds (from ~10s to ~30-40s total for 90-minute livestream)

---

## Architecture Overview

### Current Flow
```
[GenerateRmsLog] → [AnalyzeSegments] → [ExtractSermon]
                     ↓
                Uses fixed/adaptive RMS threshold
                Single threshold for entire video
```

### Proposed Flow
```
[GenerateRmsLog] → [VisualAnalysis] → [AnalyzeSegmentsWithVisual] → [ExtractSermon]
                     ↓                  ↓
                Frame sampling       Per-song threshold calibration
                Every 10 seconds     Precise boundary detection
```

---

## Detailed Algorithm

### Phase 1: Visual Frame Analysis (NEW)

**Goal:** Identify which time periods likely contain songs vs. speech

**Method:**
1. Extract 1 frame every 10 seconds from entire video (~540 frames for 90 minutes)
2. Analyze each frame for visual characteristics of song lyrics:
   - High average brightness (white lyric boxes)
   - High contrast regions (black text on white)
   - Strong horizontal edges (top/bottom of lyric boxes)
   - Low color variance (mostly black/white vs. varied sermon colors)
3. Classify each timestamp as `SONG` or `SPEECH`
4. Store classifications with timestamps

**Output:** Array of `VisualSample` objects:
```php
[
    ['timestamp' => 0, 'classification' => 'speech', 'confidence' => 0.95],
    ['timestamp' => 10, 'classification' => 'speech', 'confidence' => 0.92],
    ['timestamp' => 300, 'classification' => 'song', 'confidence' => 0.88],
    ['timestamp' => 310, 'classification' => 'song', 'confidence' => 0.91],
    // ...
]
```

**Technical Implementation:**
- Use FFmpeg's `select` filter: `select='not(mod(n,600))'` (every 600 frames at 60fps = 10 sec)
- Can add `signalstats` filter for brightness/contrast metrics
- Output frames to temp directory OR just extract metrics to log file
- Parse metrics to classify song vs. speech

**Estimated Processing Time:** 20-30 seconds for 90-minute video

---

### Phase 2: Song Clustering (NEW)

**Goal:** Group consecutive `SONG` classifications into distinct song candidates

**Method:**
1. Iterate through visual samples sequentially
2. When classification changes from `SPEECH` → `SONG`, start new song cluster
3. Add consecutive `SONG` samples to current cluster
4. When classification changes from `SONG` → `SPEECH`, end current cluster
5. Apply minimum duration filter (e.g., 60 seconds = 6 samples minimum)
6. Handle gaps: If gap between `SONG` samples < 30 seconds, consider same song
   - Allows for brief instrumental breaks where lyrics temporarily hide

**Output:** Array of `SongCluster` objects:
```php
[
    [
        'start_estimate' => 295,      // First SONG sample timestamp
        'end_estimate' => 485,        // Last SONG sample timestamp
        'sample_count' => 20,         // Number of SONG samples in cluster
        'samples' => [300, 310, 320, ...], // All timestamps classified as SONG
        'confidence' => 0.89          // Average confidence across samples
    ],
    [
        'start_estimate' => 2100,
        'end_estimate' => 2340,
        'sample_count' => 25,
        'samples' => [2100, 2110, 2120, ...],
        'confidence' => 0.92
    ],
    // ...
]
```

**Edge Cases:**
- **Short songs (<60 sec):** May be legitimate, but likely false positives (Bible verses on screen). Filter out for safety, can make configurable.
- **Adjacent songs:** If `SPEECH` gap between songs < 20 seconds, might be prayer/transition. Keep as separate songs if both clusters meet minimum duration.
- **Flickering classification:** Use smoothing (e.g., 3 consecutive samples required to change state)

**Estimated Processing Time:** <1 second (simple iteration)

---

### Phase 3: Per-Song RMS Threshold Calibration (MODIFIED)

**Goal:** For each song cluster, calculate an optimal RMS threshold based on THIS song's characteristics

**Method:**

For each `SongCluster`:

1. **Extract RMS values for song period:**
   - Get RMS samples from existing RMS log
   - Filter to timestamps matching visual `SONG` samples
   - Calculate: `songAvgRMS = mean(rms_values_for_song_samples)`

2. **Extract RMS values for adjacent speech:**
   - Get RMS samples from 60 seconds before song cluster start
   - Get RMS samples from 60 seconds after song cluster end
   - Calculate: `speechAvgRMS = mean(before_rms + after_rms)`

3. **Calculate per-song threshold:**
   ```php
   $threshold = ($songAvgRMS + $speechAvgRMS) / 2;

   // Apply safety bounds (prevent extreme values)
   $threshold = max($threshold, -80.0);  // Floor
   $threshold = min($threshold, -20.0);  // Ceiling
   ```

4. **Store threshold with cluster:**
   ```php
   $cluster['rms_threshold'] = $threshold;
   $cluster['song_avg_rms'] = $songAvgRMS;
   $cluster['speech_avg_rms'] = $speechAvgRMS;
   ```

**Example:**
- Song 1 (loud contemporary): `songAvg = -35dB, speechAvg = -47dB → threshold = -41dB`
- Song 2 (quiet hymn): `songAvg = -45dB, speechAvg = -48dB → threshold = -46.5dB`
- Each song gets threshold tuned to its volume characteristics

**Benefits:**
- ✅ Handles volume variation between songs
- ✅ Adapts to mixing changes mid-service
- ✅ More precise than global threshold

**Estimated Processing Time:** <1 second per song (~5 seconds total for typical service)

---

### Phase 4: Precise Boundary Detection (MODIFIED)

**Goal:** Find exact start/end timestamps for each song using calibrated threshold

**Method:**

For each `SongCluster` with its `rms_threshold`:

1. **Define search region:**
   - Start search: `cluster.start_estimate - 120 seconds` (allow for long intro)
   - End search: `cluster.end_estimate + 60 seconds` (allow for outro)

2. **Parse RMS log within region using threshold:**
   - Iterate through RMS samples in search region
   - Track when RMS crosses above threshold (song start candidate)
   - Track when RMS crosses below threshold (song end candidate)

3. **Apply tolerance for quiet sections:**
   - Allow brief dips below threshold (e.g., 5-10 seconds)
   - Prevents splitting song if there's a quiet bridge/instrumental
   - If RMS dips below threshold for < 10 seconds, then goes back above, consider continuous

4. **Validate against visual samples:**
   - If detected boundary excludes visual `SONG` samples, expand boundary
   - If detected boundary includes many visual `SPEECH` samples, contract boundary
   - Ensures RMS boundaries align with visual classification

5. **Create final segment:**
   ```php
   $segment = new LivestreamSegment([
       'startTime' => $preciseStart,
       'endTime' => $preciseEnd,
       'duration' => $preciseEnd - $preciseStart,
       'classification' => 'song',
       'avgRms' => $calculatedAvg,
       'peakRms' => $calculatedPeak,
       'metadata' => [
           'threshold_used' => $threshold,
           'visual_sample_count' => count($cluster['samples']),
           'visual_confidence' => $cluster['confidence'],
           'calibration_method' => 'per_song_visual'
       ]
   ]);
   ```

**Estimated Processing Time:** <1 second per song (~5 seconds total)

---

### Phase 5: Sermon Identification (EXISTING)

**Goal:** Identify the sermon from remaining speech segments

**Method:** (No changes to existing logic)
1. Generate speech segments from gaps between songs
2. Find longest continuous speech segment
3. Must be >= 300 seconds (5 minutes) to qualify as sermon
4. Mark as `isSermonCandidate = true`

---

## New Components Required

### 1. `VisualAnalysisService`

**Location:** `app/Services/VisualAnalysisService.php`

**Responsibilities:**
- Frame extraction using FFmpeg
- Visual classification (brightness, contrast, edge detection)
- Confidence scoring

**Key Methods:**
```php
class VisualAnalysisService
{
    public function analyzeVideo(string $videoPath, int $sampleInterval = 10): array;
    public function extractFrameMetrics(string $videoPath, int $sampleInterval): array;
    public function classifyFrame(array $metrics): array; // Returns ['classification' => 'song'|'speech', 'confidence' => float]
    private function calculateBrightness(array $metrics): float;
    private function calculateContrast(array $metrics): float;
    private function detectHorizontalEdges(array $metrics): int;
}
```

**Configuration Options:**
```php
// config/media-processing.php
'visual_analysis' => [
    'enabled' => env('VISUAL_ANALYSIS_ENABLED', true),
    'sample_interval_seconds' => env('VISUAL_SAMPLE_INTERVAL', 10),
    'brightness_threshold' => env('VISUAL_BRIGHTNESS_THRESHOLD', 0.6),
    'contrast_threshold' => env('VISUAL_CONTRAST_THRESHOLD', 0.7),
    'edge_density_threshold' => env('VISUAL_EDGE_THRESHOLD', 0.5),
    'min_confidence' => env('VISUAL_MIN_CONFIDENCE', 0.7),
]
```

**Estimated Size:** ~200-250 lines + tests

---

### 2. `SongClusteringService`

**Location:** `app/Services/SongClusteringService.php`

**Responsibilities:**
- Group visual samples into song candidates
- Handle edge cases (gaps, short songs, flickering)
- Smoothing and validation

**Key Methods:**
```php
class SongClusteringService
{
    public function clusterSongPeriods(array $visualSamples): array;
    private function smoothClassifications(array $samples): array; // Reduce flickering
    private function groupConsecutiveSamples(array $samples): array;
    private function filterByMinimumDuration(array $clusters, int $minDuration): array;
    private function mergeCloseGaps(array $clusters, int $maxGap): array;
}
```

**Configuration Options:**
```php
'clustering' => [
    'min_song_duration' => env('MIN_SONG_DURATION', 60),
    'max_gap_seconds' => env('MAX_SONG_GAP', 30),
    'smoothing_window' => env('CLASSIFICATION_SMOOTHING', 3), // Require 3 consecutive samples to change
]
```

**Estimated Size:** ~150-200 lines + tests

---

### 3. Modified `VideoSegmentationService`

**Location:** `app/Services/VideoSegmentationService.php` (EXISTING - MODIFICATIONS)

**New Methods to Add:**
```php
public function calibratePerSongThreshold(
    string $rmsLogPath,
    array $songCluster
): array;

public function detectBoundariesForCluster(
    string $rmsLogPath,
    array $cluster,
    float $threshold
): LivestreamSegment;

private function extractRmsForTimestamps(
    string $rmsLogContent,
    array $timestamps
): array;

private function extractRmsForRegion(
    string $rmsLogContent,
    float $startTime,
    float $endTime
): array;

private function validateBoundariesAgainstVisual(
    float $startTime,
    float $endTime,
    array $visualSamples
): array; // Returns adjusted boundaries
```

**Modified Methods:**
- `analyzeSegments()`: Accept optional `$visualClusters` parameter, use per-song thresholds if provided
- Update logging to include visual analysis metadata

**Estimated Changes:** ~200-250 lines of new code within existing service

---

### 4. New Job: `PerformVisualAnalysis`

**Location:** `app/Jobs/PerformVisualAnalysis.php`

**Responsibilities:**
- Run visual analysis as part of processing pipeline
- Store results in `media_processing_logs` table
- Handle failures gracefully (fallback to RMS-only)

**Structure:**
```php
class PerformVisualAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $processingId,
        public string $videoPath
    ) {}

    public function handle(
        VisualAnalysisService $visual,
        SongClusteringService $clustering
    ): void {
        // 1. Perform visual analysis
        $samples = $visual->analyzeVideo($this->videoPath);

        // 2. Cluster song periods
        $clusters = $clustering->clusterSongPeriods($samples);

        // 3. Store in processing log
        $this->storeVisualAnalysis($samples, $clusters);
    }
}
```

**Estimated Size:** ~100-150 lines + tests

---

### 5. Modified Job: `AnalyzeSegments`

**Location:** `app/Jobs/AnalyzeSegments.php` (EXISTING - MODIFICATIONS)

**Changes:**
```php
public function handle(
    VideoSegmentationService $segmentation,
    VisualAnalysisService $visual // NEW dependency
): void {
    // 1. Retrieve visual analysis results from processing log
    $visualClusters = $this->getVisualClusters();

    // 2. Generate RMS log (existing)
    $rmsLogPath = $segmentation->generateRmsLog($this->videoPath);

    // 3. If visual clusters available, use per-song thresholds
    if ($visualClusters) {
        $segments = $this->analyzeWithVisualGuidance($rmsLogPath, $visualClusters);
    } else {
        // Fallback to existing RMS-only analysis
        $segments = $segmentation->analyzeSegments($rmsLogPath);
    }

    // 4. Store segments (existing)
    $this->storeSegments($segments);
}

private function analyzeWithVisualGuidance(string $rmsLogPath, array $clusters): array
{
    $segments = [];

    foreach ($clusters as $cluster) {
        // Calibrate threshold for this song
        $calibration = $this->segmentation->calibratePerSongThreshold($rmsLogPath, $cluster);

        // Detect precise boundaries
        $segment = $this->segmentation->detectBoundariesForCluster(
            $rmsLogPath,
            $cluster,
            $calibration['threshold']
        );

        $segments[] = $segment;
    }

    // Identify sermon from gaps between songs
    $sermonSegment = $this->identifySermonFromGaps($segments, $totalDuration);
    $segments[] = $sermonSegment;

    return $segments;
}
```

**Estimated Changes:** ~100-150 lines of modifications

---

### 6. Database Schema Changes

**Table:** `media_processing_logs` (add columns)

```php
Schema::table('media_processing_logs', function (Blueprint $table) {
    $table->json('visual_samples')->nullable()->after('rms_stats');
    $table->json('song_clusters')->nullable()->after('visual_samples');
    $table->integer('visual_sample_count')->nullable()->after('song_clusters');
    $table->float('visual_processing_time')->nullable()->after('visual_sample_count');
});
```

**Table:** `livestream_segments` (add columns)

```php
Schema::table('livestream_segments', function (Blueprint $table) {
    $table->float('visual_confidence')->nullable()->after('peak_rms');
    $table->integer('visual_sample_count')->nullable()->after('visual_confidence');
    $table->string('calibration_method')->nullable()->after('visual_sample_count');
    // 'calibration_method' values: 'per_song_visual', 'adaptive', 'fixed', 'fallback'
});
```

**Migration File:** `YYYY_MM_DD_HHMMSS_add_visual_analysis_columns.php`

---

### 7. Updated Processing Pipeline

**Location:** `app/Services/ProcessingPipelineBuilder.php` (EXISTING - MODIFICATIONS)

**Change livestream pipeline:**

```php
// BEFORE
public function buildLivestreamPipeline(): array
{
    return [
        GenerateRmsLog::class,
        AnalyzeSegments::class,
        ExtractSermon::class,
        TranscribeAudio::class,
        ProcessTranscriptWithAI::class,
        SendCompletionNotification::class,
    ];
}

// AFTER
public function buildLivestreamPipeline(): array
{
    $jobs = [];

    // Visual analysis (if enabled)
    if (config('media-processing.visual_analysis.enabled')) {
        $jobs[] = PerformVisualAnalysis::class;
    }

    // RMS generation (always)
    $jobs[] = GenerateRmsLog::class;

    // Segment analysis (uses visual results if available)
    $jobs[] = AnalyzeSegments::class;

    // Continue with extraction/transcription
    $jobs[] = ExtractSermon::class;
    $jobs[] = TranscribeAudio::class;
    $jobs[] = ProcessTranscriptWithAI::class;
    $jobs[] = SendCompletionNotification::class;

    return $jobs;
}
```

---

## Implementation Phases

### Phase 1: Visual Analysis Foundation (Week 1)
**Goal:** Get frame extraction and basic classification working

**Tasks:**
1. Create `VisualAnalysisService` with FFmpeg frame extraction
2. Implement brightness/contrast/edge detection
3. Create classification logic with confidence scoring
4. Add configuration options
5. Write unit tests for visual classification
6. Test manually with sample livestream videos

**Deliverables:**
- [ ] `VisualAnalysisService` class
- [ ] Configuration in `media-processing.php`
- [ ] Unit tests with sample frames
- [ ] Manual testing documentation

**Success Criteria:**
- Can extract frames at 10-second intervals
- Can classify frames as song/speech with >85% accuracy on test videos
- Processing time <30 seconds for 90-minute video

---

### Phase 2: Clustering & Calibration (Week 2)
**Goal:** Group visual samples into songs and calculate thresholds

**Tasks:**
1. Create `SongClusteringService`
2. Implement smoothing and gap-merging logic
3. Add per-song threshold calibration to `VideoSegmentationService`
4. Create database migration for new columns
5. Write unit tests for clustering logic
6. Integration tests with real RMS logs + visual samples

**Deliverables:**
- [ ] `SongClusteringService` class
- [ ] Modified `VideoSegmentationService` methods
- [ ] Database migration
- [ ] Unit + integration tests
- [ ] Test with multiple livestream recordings

**Success Criteria:**
- Correctly identifies 3-5 song clusters in typical service
- Filters out false positives (announcements, verses)
- Per-song thresholds vary appropriately (±5dB typical range)

---

### Phase 3: Pipeline Integration (Week 3)
**Goal:** Integrate visual analysis into processing pipeline

**Tasks:**
1. Create `PerformVisualAnalysis` job
2. Modify `AnalyzeSegments` job to use visual results
3. Update `ProcessingPipelineBuilder`
4. Add visual metadata to processing logs
5. Update status/progress tracking
6. Feature flag for gradual rollout

**Deliverables:**
- [ ] `PerformVisualAnalysis` job
- [ ] Modified `AnalyzeSegments` job
- [ ] Updated pipeline builder
- [ ] Feature flag configuration
- [ ] End-to-end tests

**Success Criteria:**
- Pipeline runs visual analysis before segmentation
- Gracefully falls back to RMS-only if visual fails
- Processing logs contain visual analysis metadata
- Can toggle feature on/off via config

---

### Phase 4: Boundary Refinement & Validation (Week 4)
**Goal:** Fine-tune boundary detection and validate results

**Tasks:**
1. Implement `detectBoundariesForCluster()` with tolerance logic
2. Add `validateBoundariesAgainstVisual()` method
3. Handle edge cases (quiet intros, long bridges, adjacent songs)
4. Comprehensive testing with diverse livestreams
5. Compare results: visual-guided vs. RMS-only
6. Tune thresholds and parameters based on results

**Deliverables:**
- [ ] Complete boundary detection logic
- [ ] Edge case handling
- [ ] Comparison analysis (before/after)
- [ ] Tuned configuration parameters
- [ ] Documentation of edge cases

**Success Criteria:**
- Songs boundaries accurate within ±5 seconds
- Correctly handles quiet intros/outros
- No songs included in sermon segments
- Sermon candidate correctly identified in >95% of cases

---

### Phase 5: Monitoring & Rollout (Week 5)
**Goal:** Deploy to production with monitoring

**Tasks:**
1. Add detailed logging for debugging
2. Create admin dashboard view for visual analysis results
3. Add alerts for processing failures
4. Deploy to production with feature flag disabled
5. Enable for 10% of uploads (gradual rollout)
6. Monitor processing times and accuracy
7. Collect feedback and iterate

**Deliverables:**
- [ ] Enhanced logging
- [ ] Admin dashboard updates
- [ ] Monitoring/alerting
- [ ] Gradual rollout plan
- [ ] Performance metrics
- [ ] User feedback process

**Success Criteria:**
- Processing time increase acceptable (<40 seconds for 90min video)
- Accuracy improvement measurable (reduction in manual corrections)
- No degradation in sermon extraction quality
- Zero processing failures due to visual analysis

---

## Configuration Options Summary

### New Config File Section

**Location:** `config/media-processing.php`

```php
'visual_analysis' => [
    // Master toggle
    'enabled' => env('VISUAL_ANALYSIS_ENABLED', true),

    // Frame sampling
    'sample_interval_seconds' => env('VISUAL_SAMPLE_INTERVAL', 10),

    // Visual classification thresholds
    'brightness_threshold' => env('VISUAL_BRIGHTNESS_THRESHOLD', 0.6),
    'contrast_threshold' => env('VISUAL_CONTRAST_THRESHOLD', 0.7),
    'edge_density_threshold' => env('VISUAL_EDGE_THRESHOLD', 0.5),
    'min_confidence' => env('VISUAL_MIN_CONFIDENCE', 0.7),

    // Clustering parameters
    'min_song_duration' => env('MIN_SONG_DURATION', 60),
    'max_gap_seconds' => env('MAX_SONG_GAP', 30),
    'smoothing_window' => env('CLASSIFICATION_SMOOTHING', 3),

    // Boundary detection
    'intro_search_buffer' => env('SONG_INTRO_BUFFER', 120), // Look 2min before first visual sample
    'outro_search_buffer' => env('SONG_OUTRO_BUFFER', 60),  // Look 1min after last visual sample
    'quiet_section_tolerance' => env('QUIET_SECTION_TOLERANCE', 10), // Allow 10sec dips below threshold

    // Per-song calibration
    'calibration_speech_buffer' => env('CALIBRATION_SPEECH_BUFFER', 60), // Use 60sec of adjacent speech
    'threshold_safety_floor' => env('THRESHOLD_FLOOR', -80.0),
    'threshold_safety_ceiling' => env('THRESHOLD_CEILING', -20.0),

    // Fallback behavior
    'fallback_to_rms_on_failure' => env('VISUAL_FALLBACK_ENABLED', true),
    'require_min_clusters' => env('MIN_SONG_CLUSTERS', 1), // Fail if no songs detected
],
```

### Environment Variable Template

```bash
# Visual Song Detection
VISUAL_ANALYSIS_ENABLED=true
VISUAL_SAMPLE_INTERVAL=10
VISUAL_BRIGHTNESS_THRESHOLD=0.6
VISUAL_CONTRAST_THRESHOLD=0.7
VISUAL_EDGE_THRESHOLD=0.5
VISUAL_MIN_CONFIDENCE=0.7

MIN_SONG_DURATION=60
MAX_SONG_GAP=30
CLASSIFICATION_SMOOTHING=3

SONG_INTRO_BUFFER=120
SONG_OUTRO_BUFFER=60
QUIET_SECTION_TOLERANCE=10

CALIBRATION_SPEECH_BUFFER=60
THRESHOLD_FLOOR=-80.0
THRESHOLD_CEILING=-20.0

VISUAL_FALLBACK_ENABLED=true
MIN_SONG_CLUSTERS=1
```

---

## Testing Strategy

### Unit Tests

**`VisualAnalysisServiceTest.php`:**
- [ ] Frame extraction produces correct intervals
- [ ] Brightness calculation accurate
- [ ] Contrast detection works
- [ ] Edge detection identifies horizontal lines
- [ ] Classification logic correct for known samples
- [ ] Confidence scoring reasonable

**`SongClusteringServiceTest.php`:**
- [ ] Groups consecutive samples correctly
- [ ] Filters short duration clusters
- [ ] Merges close gaps
- [ ] Handles edge cases (single sample, alternating classifications)
- [ ] Smoothing reduces flickering

**`VideoSegmentationServiceTest.php` (additions):**
- [ ] Per-song threshold calibration correct
- [ ] RMS extraction for timestamps accurate
- [ ] Boundary detection with tolerance works
- [ ] Visual validation adjusts boundaries correctly

### Integration Tests

**`VisualSegmentationIntegrationTest.php`:**
- [ ] Full pipeline with visual analysis completes
- [ ] Visual clusters stored in database
- [ ] Per-song thresholds calculated correctly
- [ ] Segments match visual + RMS boundaries
- [ ] Sermon candidate correctly identified
- [ ] Processing times acceptable

**Test Data:**
- Use existing test livestream videos from production
- Create synthetic test videos with known patterns
- Edge cases: quiet songs, loud preaching, adjacent songs, single song services

### Manual Testing Checklist

- [ ] Upload livestream with 3 songs (before, middle, after sermon)
- [ ] Upload with 2 songs (before and after)
- [ ] Upload with 1 song (before sermon only)
- [ ] Upload with quiet hymn vs. loud contemporary worship
- [ ] Upload with adjacent songs (morning prayer between)
- [ ] Upload with sermon-only (no songs)
- [ ] Verify processing times reasonable
- [ ] Verify segments stored correctly in database
- [ ] Verify sermon extraction works
- [ ] Verify transcription still works
- [ ] Check admin interface shows visual analysis data

---

## Rollback Plan

### If Visual Analysis Causes Issues

**Feature Flag Disable:**
```bash
# In production .env
VISUAL_ANALYSIS_ENABLED=false
```

This immediately reverts to RMS-only processing without code changes.

**Database Rollback:**
- Migration adds nullable columns, so rollback is non-destructive
- Old code continues working even with new columns present

**Code Rollback:**
- All changes are additive (new services, optional job)
- Can revert to previous pipeline configuration
- No breaking changes to existing services

**Monitoring Alerts:**
- Processing time exceeds 2 minutes
- Visual analysis failure rate >10%
- Segmentation accuracy drops
- Queue backlog increases

---

## Performance Considerations

### Expected Processing Times (90-minute livestream)

| Phase | Current | With Visual | Delta |
|-------|---------|-------------|-------|
| RMS Generation | 5-8s | 5-8s | 0s |
| Visual Analysis | 0s | 20-30s | +20-30s |
| Segmentation | 2-3s | 3-5s | +1-2s |
| **Total** | **7-11s** | **28-43s** | **+21-32s** |

### Optimization Opportunities (Future)

1. **Parallel Processing:** Run `PerformVisualAnalysis` and `GenerateRmsLog` in parallel
   - Both read same video file independently
   - Could reduce total time by ~5-8 seconds

2. **Adaptive Sampling:**
   - Dense sampling (10s) during uncertain periods
   - Sparse sampling (30s) during obvious sermon sections
   - Could reduce frame count by 30-50%

3. **Frame Extraction Optimization:**
   - Use FFmpeg's `-ss` seeking instead of frame skipping
   - Extract multiple frames in single FFmpeg call
   - Cache frame metrics if reprocessing needed

4. **GPU Acceleration:**
   - FFmpeg can use GPU for frame extraction/analysis
   - Probably overkill for current needs, but available

5. **Early Termination:**
   - If pattern is clear (e.g., 10 consecutive speech samples), skip remaining samples
   - Reduces processing for sermon-only uploads

---

## Risks & Mitigations

### Risk 1: Visual Analysis Too Slow
**Impact:** Users complain about processing time
**Probability:** Low (30s is reasonable for 90min video)
**Mitigation:**
- Implement parallel processing (visual + RMS together)
- Add progress indicators so users know it's working
- Optimize frame extraction if needed
- Feature flag allows quick disable

### Risk 2: False Positives (Detecting Non-Songs as Songs)
**Impact:** Sermon incorrectly segmented
**Probability:** Medium (Bible verses, announcements could have white text)
**Mitigation:**
- Require minimum duration (60s filters most false positives)
- Use confidence thresholds (reject low-confidence classifications)
- Manual review tools in admin interface
- Tune brightness/contrast thresholds based on production data

### Risk 3: Missed Songs (Quiet Songs Not Detected)
**Impact:** Song included in sermon segment (current problem)
**Probability:** Low-Medium (visual should catch these, but intros might be missed)
**Mitigation:**
- Intro/outro search buffers allow catching quiet starts
- Per-song thresholds adapt to quiet songs
- Validation against visual samples adjusts boundaries
- Fallback to RMS-only if visual completely fails

### Risk 4: Presentation Format Changes
**Impact:** Visual detection breaks if church changes lyric display
**Probability:** Low (current format stable for years)
**Mitigation:**
- Configurable thresholds allow tuning without code changes
- Monitoring alerts if detection rate drops
- Fallback to RMS-only always available
- Document expected visual format for future reference

### Risk 5: Increased Server Load
**Impact:** Queue backlog, slower processing
**Probability:** Low (30s additional processing manageable)
**Mitigation:**
- Queue workers can be scaled horizontally
- Processing happens async (doesn't block users)
- Can disable visual analysis temporarily if needed
- Monitor queue depth and processing times

---

## Success Metrics

### Accuracy Improvements
- **Target:** Reduce manual corrections by 80%
- **Measure:** Track admin edits to auto-generated segments
- **Baseline:** Current ~30-40% of uploads require manual fixing
- **Goal:** <10% require manual fixing

### Processing Performance
- **Target:** Processing time <60 seconds for 90-minute livestream
- **Measure:** Track job completion times in database
- **Baseline:** Current ~10 seconds
- **Goal:** ~30-40 seconds (acceptable)

### Reliability
- **Target:** >95% successful segmentation without manual intervention
- **Measure:** Percentage of uploads where sermon candidate correctly identified
- **Baseline:** ~60-70% (current RMS-only)
- **Goal:** >95%

### System Impact
- **Target:** No degradation in overall system performance
- **Measure:** Queue depth, server CPU/memory, user-facing response times
- **Goal:** All metrics remain within normal ranges

---

## Future Enhancements

### Phase 6+ (Not in Initial Implementation)

1. **OCR Validation:**
   - Add actual text recognition to confirm white boxes contain lyrics
   - Would reduce false positives from Bible verses
   - Higher computational cost, only use if needed

2. **Machine Learning Classifier:**
   - Train model on visual + RMS + temporal features
   - Learn service structure patterns
   - Could predict sermon location without segmentation
   - Requires labeled training data

3. **Speaker Recognition:**
   - Analyze audio for speaker changes
   - Different preachers have different vocal characteristics
   - Could distinguish song leader from preacher

4. **Frequency Domain Analysis:**
   - Music has different frequency signature than speech
   - Could supplement RMS analysis
   - More computationally expensive

5. **Multi-Modal Confidence Scoring:**
   - Combine visual + RMS + frequency + duration signals
   - Weighted confidence score per segment
   - Flag low-confidence segments for manual review

6. **Batch Reprocessing Tool:**
   - Re-analyze historical livestreams with new algorithm
   - Identify improvements vs. current segments
   - Update sermon boundaries retroactively

---

## Documentation Requirements

### Developer Documentation
- [ ] Architecture decision record (ADR) for visual-first approach
- [ ] Service class documentation (PHPDoc)
- [ ] Configuration options reference
- [ ] Testing guide for new features
- [ ] Troubleshooting guide

### User Documentation
- [ ] Update admin guide with visual analysis explanation
- [ ] Screenshot examples of visual detection
- [ ] Guidance on what to do if segmentation incorrect
- [ ] Best practices for recording (ensure lyrics visible)

### Operational Documentation
- [ ] Deployment checklist
- [ ] Rollback procedure
- [ ] Monitoring dashboard setup
- [ ] Alert configuration
- [ ] Performance tuning guide

---

## Open Questions / Decisions Needed

1. **Frame extraction format:**
   - Option A: Extract actual frame images to temp directory (more flexible, easier debugging)
   - Option B: Use FFmpeg filters to extract metrics only (faster, less storage)
   - **Recommendation:** Option B for production, Option A for development/debugging

2. **Visual confidence threshold:**
   - What minimum confidence to require for song classification?
   - Too high: miss valid songs
   - Too low: false positives
   - **Recommendation:** Start at 0.7, tune based on production data

3. **Handling sermon-only uploads:**
   - If zero song clusters detected, should we fail or continue?
   - **Recommendation:** Continue with fallback to RMS-only, log for monitoring

4. **Admin interface changes:**
   - Should visual analysis results be visible in admin UI?
   - Show confidence scores, frame samples for debugging?
   - **Recommendation:** Yes, add debug view for admins

5. **Parallel processing priority:**
   - Implement in initial version or defer as optimization?
   - **Recommendation:** Defer, implement sequentially first for simplicity

---

## Estimated Timeline

### Total: 5 weeks (assuming 1 developer, part-time)

**Week 1:** Visual Analysis Foundation
**Week 2:** Clustering & Calibration
**Week 3:** Pipeline Integration
**Week 4:** Boundary Refinement & Validation
**Week 5:** Monitoring & Rollout

### Accelerated Timeline: 3 weeks (full-time)

Could combine phases if needed:
- Week 1: Visual + Clustering (Phases 1-2)
- Week 2: Integration + Refinement (Phases 3-4)
- Week 3: Testing + Rollout (Phase 5)

---

## Summary

This plan replaces RMS-only song detection with a hybrid visual-first approach:

1. **Visual analysis** identifies songs by detecting on-screen lyrics (10-second frame sampling)
2. **Clustering** groups visual samples into distinct song candidates
3. **Per-song calibration** calculates optimal RMS threshold for each song
4. **Boundary detection** finds precise start/end using calibrated thresholds
5. **Validation** ensures RMS boundaries align with visual classification

**Expected Benefits:**
- ✅ Accurate song identification (solves current miss/false positive problem)
- ✅ Handles volume variation between songs
- ✅ Precise boundaries (frame-level accuracy)
- ✅ Minimal processing overhead (+20-30 seconds)
- ✅ Graceful fallback to RMS-only if visual fails

**Implementation:** 5 new/modified services, 2 new jobs, ~800-1000 lines of code, 5 weeks part-time

**Risk:** Low - feature flag allows quick rollback, additive changes don't break existing functionality

---

## Next Steps

1. Review and approve this plan
2. Set up development environment with sample livestream videos
3. Begin Phase 1: Visual Analysis Foundation
4. Schedule weekly progress reviews
5. Plan production deployment after Phase 4 completion

---

*Document created: 2025-11-16*
*Author: Claude (based on discussion with Gareth)*
*Version: 1.0*
