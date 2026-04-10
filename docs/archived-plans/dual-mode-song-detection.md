# Dual-Mode Visual Song Detection

## Problem

Livestream song slides have changed style. The old style used **white highlight boxes** behind dark text; the new style uses **white text directly on a blurred background** with no boxes. The current `VisualAnalysisService` classifier is tuned entirely for the old style — it relies on YHIGH being ≥ 0.75 (normalized), which only the white-box style achieves. New-style songs go undetected.

## FFmpeg signalstats semantics

The current code has misleading variable names. Before describing the plan, here are the actual definitions (source: [FFmpeg signalstats docs](https://ffmpeg.org/ffmpeg-filters.html#signalstats)):

| Metric | FFmpeg definition | Range | Current code name |
|--------|-------------------|-------|-------------------|
| **YAVG** | Average Y (luminance) value of all pixels | 0-255 | `brightness` |
| **YLOW** | Y value at the **10th percentile** (10% of pixels are darker than this) | 0-255 | *not parsed* |
| **YHIGH** | Y value at the **90th percentile** (90% of pixels are darker than this) | 0-255 | `edge_density` |
| **YDIF** | Average Y difference **from the previous frame** (temporal, not spatial) | 0-255 | `contrast` |
| **YMIN/YMAX** | Absolute min/max Y values | 0-255 | *not parsed* |

Key corrections from the original plan:
- **YLOW/YHIGH are percentile luminance values**, not proportions of dark/bright pixels. Low YLOW means the 10th-percentile pixel is dark (dark background present). High YHIGH means the 90th-percentile pixel is bright.
- **YDIF is temporal** (frame-to-frame difference), not spatial contrast. Static lyric slides produce *low* YDIF. It is not useful for detecting steady-state song frames and must not be weighted in Mode B.

## Visual signatures (corrected semantics)

All values normalized to 0.0-1.0 (raw value / 255).

| Metric | Old style (white boxes) | New style (text on blur) | Normal speech |
|--------|------------------------|-------------------------|---------------|
| **YAVG** (frame avg luminance) | High (white boxes raise avg) | Low (dark bg dominates) | Moderate |
| **YHIGH** (90th percentile Y) | Very high (90th %ile is in a white box) | Moderate (90th %ile near text/bg boundary) | Moderate |
| **YLOW** (10th percentile Y) | Moderate (boxes raise floor) | Low (dark blurred bg) | Variable |
| **YHIGH - YLOW** (percentile span) | Moderate | High (dark bg + bright text) | Low-moderate |
| **YDIF** (temporal diff) | Low (static slide) | Low (static slide) | Variable |

> **Important**: These are educated estimates. Task 1 extracts real values for calibration.

## Scope

Primary changes in `app/Services/VisualAnalysisService.php`. Minor annotation updates in:
- `VisualAnalysisService.php` — new metric parsing, dual-mode classifier, updated array shapes
- `PerformVisualAnalysis.php` — PHPDoc array shape update (line 228) to include new fields in stored samples
- `SongClusteringService.php` — PHPDoc `VisualSample` type alias (line 11) to include new fields

No logic changes needed in `SongClusteringService`, `VideoSegmentationService`, or downstream services — they consume `classification` and `confidence` which remain unchanged.

## Tasks

### 1. Parse YLOW from signalstats output

In `parseMetricsLog()` (line 372), add parsing for `lavfi.signalstats.YLOW`. The FFmpeg `signalstats` filter already outputs this value — we just don't capture it.

```php
if (preg_match('/lavfi\.signalstats\.YLOW=(\d+(?:\.\d+)?)/', $line, $matches)) {
    if ($currentMetric !== null) {
        $currentMetric['ylow'] = (float) $matches[1] / 255.0;
    }
}
```

Update the metric array initialisation to include `'ylow' => 0.0`.

Update `@return` PHPDoc annotations on:
- `parseMetricsLog()` — add `ylow: float` to the array shape
- `extractFrameMetrics()` — same
- `extractFrameMetricsInRegion()` — same

### 2. Extract real metrics from sample videos

Before changing any thresholds, extract raw signalstats values from videos with both song styles. Now that YLOW is parsed (Task 1), `extractFrameMetrics()` will return all the metrics we need.

**Approach**: Write a temporary artisan command that calls `extractFrameMetrics()` on a given video file, dumping all values (YAVG, YHIGH, YLOW, YDIF, and the derived percentile span) to CSV. Run against:
- A video with the **new** song style (white text on blur)
- A video with the **old** song style (white highlight boxes), if available
- Note the timestamps of known song sections, compare metrics at song vs speech timestamps

This step produces the real threshold values for Task 5. Without it, threshold selection is guesswork.

### 3. Compute percentile span metric

After both YHIGH and YLOW are parsed, add a derived metric in `parseMetricsLog()`:

```php
$currentMetric['percentile_span'] = $currentMetric['edge_density'] - $currentMetric['ylow'];
```

This is the gap between the 90th and 10th percentile Y values. A large span within a single frame indicates a wide luminance distribution — characteristic of bright text on a dark background (both styles, but especially the new one).

Add `percentile_span: float` to the metrics array shape annotations.

### 4. Propagate new fields through stored samples

In `analyzeVideo()` (line 56-67), the sample-building loop manually selects which fields to store. Add the new fields:

```php
$visualSamples[] = [
    'timestamp' => $metric['timestamp'],
    'classification' => $classification['classification'],
    'confidence' => $classification['confidence'],
    'brightness' => $metric['brightness'],
    'contrast' => $metric['contrast'],
    'edge_density' => $metric['edge_density'],
    'ylow' => $metric['ylow'],                       // new
    'percentile_span' => $metric['percentile_span'],  // new
    'detection_mode' => $classification['detection_mode'], // new
];
```

Update PHPDoc annotations that describe this stored shape:
- `VisualSample` type alias in `SongClusteringService.php` (line 11) — add optional `ylow`, `percentile_span`, `detection_mode` fields
- `storeVisualAnalysis()` parameter PHPDoc in `PerformVisualAnalysis.php` (line 228) — add new fields to the visual sample shape

`SongClusteringService` only reads `classification` and `confidence` from samples, so no logic changes are needed there despite the annotation update.

### 5. Implement dual-mode classifier

Restructure `classifyFrame()` (line 445) into two detection paths. The frame is classified as Song if **either** mode triggers. Confidence is `max(modeAConfidence, modeBConfidence)`.

#### Mode A — white boxes (old style)

**Preserve the exact current behaviour.** Keep the existing weighted-average formula and `MIN_CONFIDENCE` threshold:

```
confidence_A = 0.2 * brightnessScore + 0.8 * yhighScore
isSong_A = confidence_A >= MIN_CONFIDENCE (0.35)
```

Where `brightnessScore` and `yhighScore` are computed from their respective thresholds exactly as today. This ensures zero regression for old-style videos.

#### Mode B — white text on dark blur (new style)

A frame matches Mode B when it has a dark background (low YLOW, low YAVG) but bright content at the top of the distribution (moderate YHIGH) producing a wide percentile span:

```
isSong_B =
    YLOW ≤ YLOW_CEILING_NEW         (10th percentile is dark → dark background)
    AND YAVG ≤ BRIGHTNESS_CEILING_NEW  (average luminance is low → bg dominates)
    AND percentile_span ≥ SPAN_THRESHOLD_NEW  (wide dynamic range → text + bg)
```

**Threshold constants to add** (placeholder values — calibrate from Task 1 data):

```php
// Mode B — new style (calibrate from real data)
private const YLOW_CEILING_NEW = 0.20;         // 10th percentile must be dark
private const BRIGHTNESS_CEILING_NEW = 0.50;    // Average must be dark-to-moderate
private const SPAN_THRESHOLD_NEW = 0.35;        // 90th-10th percentile gap must be wide
```

**Mode B confidence**: Scale linearly with percentile span above the threshold:

```php
$spanRange = 1.0 - self::SPAN_THRESHOLD_NEW;
$confidence_B = min(1.0, ($percentileSpan - self::SPAN_THRESHOLD_NEW) / $spanRange);
```

#### YDIF (temporal difference)

Do **not** use YDIF in either mode. It measures frame-to-frame temporal change, which is low for all static slides (songs, title cards, announcements) and tells us nothing about the spatial content of a single frame. It remains parsed for backwards compatibility but stays at weight 0.0.

#### Return shape

Add `detection_mode` to the return value:

```php
return [
    'classification' => $classification,
    'confidence' => round($confidence, 3),
    'detection_mode' => $mode, // 'old_style' | 'new_style' | 'none'
];
```

### 6. Update debug logging

Update the frame classification debug log block (line 488-511) to include:
- `ylow` raw value
- `percentile_span` raw value
- Which detection mode matched (`old_style` / `new_style` / `none`)
- Mode A and Mode B individual confidence scores

### 7. Update tests

#### Regression tests (Mode A / old style)
- Existing test cases in `VisualAnalysisServiceTest` must produce identical `classification` and `confidence` values after the refactor. Structure assertions (e.g. array key checks) will need updating to account for the new `detection_mode` key — that's expected, not a regression.
- Add explicit borderline test: a frame that currently passes with confidence just above 0.35 must still pass after the refactor — verify the weighted-average formula is truly unchanged
- Test that the returned array now includes `detection_mode => 'old_style'` for these cases

#### New-style tests (Mode B)
- Add test cases with metric values representative of new-style frames (low YAVG, low YLOW, moderate YHIGH, high percentile span)
- Verify they classify as Song with `detection_mode => 'new_style'`
- Test the Mode B confidence scales correctly with percentile span

#### False positive prevention
- Test that normal speech frames (moderate YAVG, moderate YLOW, low percentile span) do NOT trigger Mode B
- Test that a uniformly dark frame (low everything including YHIGH) does NOT trigger Mode B
- Test that a brightly lit speech frame (high YAVG, high YLOW) triggers neither mode B nor mode A (unless YHIGH is genuinely high)

#### Array shape tests
- Verify the returned classification array includes `detection_mode`
- Verify parsed metrics include `ylow` and `percentile_span`

### 8. Run quality checks

- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail composer phpstan` (metric array shape changes require PHPDoc updates in VisualAnalysisService, SongClusteringService, PerformVisualAnalysis)
- `vendor/bin/sail artisan test --compact --parallel`
- `vendor/bin/sail artisan dusk`
