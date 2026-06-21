<?php

declare(strict_types=1);

use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\GenerateRmsLog;
use App\Jobs\PerformVisualAnalysis;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

// Runs GenerateRmsLog → PerformVisualAnalysis → AnalyzeSegments → ClassifyServiceSections.
// Usage:
// vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1

$scenario ??= 'may24';
$scenarios = require base_path('scripts/section-extraction/scenarios.php');

if (! isset($scenarios[$scenario])) {
    throw new InvalidArgumentException(
        sprintf('Unknown scenario "%s". Available: %s', $scenario, implode(', ', array_keys($scenarios)))
    );
}

$cfg = $scenarios[$scenario];

echo "== STEP 2 (audio analysis): {$cfg['label']} ==".PHP_EOL;

config([
    'media-processing.storage.sermon_disk' => 'local',
    'media-processing.storage.transcript_disk' => 'local',
    'queue.default' => 'sync',
]);

$formatTime = static function (?float $seconds): string {
    if ($seconds === null) {
        return '--:--';
    }

    $roundedSeconds = (int) round($seconds);

    return sprintf('%d:%02d', intdiv($roundedSeconds, 60), $roundedSeconds % 60);
};

$pidPath = base_path('storage/scratch/'.$cfg['pid_file']);
if (! is_file($pidPath)) {
    throw new RuntimeException("Missing {$cfg['pid_file']}. Run run-init.php first.");
}

$processingId = trim((string) file_get_contents($pidPath));
$logId = MediaProcessingLog::query()
    ->where('processing_id', $processingId)
    ->value('id');

if (! is_numeric($logId)) {
    throw new RuntimeException("No MediaProcessingLog found for pid={$processingId}. Run run-init.php first.");
}

$logId = (int) $logId;
$reload = static fn (): ?MediaProcessingLog => MediaProcessingLog::query()->find($logId);

$run = static function (string $class) use ($reload): void {
    $processingLog = $reload();
    if (! $processingLog instanceof MediaProcessingLog) {
        throw new RuntimeException('MediaProcessingLog disappeared during Step 2.');
    }

    $startedAt = microtime(true);
    echo 'Running '.class_basename($class).'... ';

    try {
        dispatch_sync(new $class($processingLog));
        printf('ok (%.0fs)%s', microtime(true) - $startedAt, PHP_EOL);
    } catch (Throwable $exception) {
        printf(
            'THREW %s: %s (%.0fs)%s',
            class_basename($exception),
            $exception->getMessage(),
            microtime(true) - $startedAt,
            PHP_EOL
        );

        throw $exception;
    }
};

$startedAt = microtime(true);

$run(GenerateRmsLog::class);

if ((bool) config('media-processing.visual_analysis.enabled', true)) {
    $run(PerformVisualAnalysis::class);
}

$run(AnalyzeSegments::class);
$run(ClassifyServiceSections::class);

printf('%sDone in %.1fs%s', PHP_EOL, microtime(true) - $startedAt, PHP_EOL);

$log = $reload();
if (! $log instanceof MediaProcessingLog) {
    throw new RuntimeException('MediaProcessingLog disappeared before Step 2 reporting.');
}

echo PHP_EOL.'== LOG =='.PHP_EOL;
echo "current_step={$log->current_step}  status={$log->status->value}".PHP_EOL;

$segments = LivestreamSegment::query()
    ->where('media_processing_log_id', $logId)
    ->orderBy('start_time')
    ->get();

echo PHP_EOL.'== LIVESTREAM SEGMENTS ('.$segments->count().') =='.PHP_EOL;
printf("%-3s %-13s %7s  %-22s %-6s %-6s %s\n", '#', 'start-end', 'dur', 'classification', 'serm?', 'cand?', 'rms/vis');

foreach ($segments as $segment) {
    printf(
        "%-3s %5s-%-6s %6ss  %-22s %-6s %-6s %s\n",
        $segment->segment_index,
        $formatTime((float) $segment->start_time),
        $formatTime((float) $segment->end_time),
        (int) round((float) $segment->duration),
        substr($segment->classification->value, 0, 22),
        $segment->is_sermon_segment ? 'Y' : '-',
        $segment->is_sermon_candidate ? 'Y' : '-',
        'rms='.round((float) $segment->avg_rms, 1)
            .' vis='.($segment->visual_confidence !== null ? round((float) $segment->visual_confidence, 2) : '-')
    );
}

$sections = ServiceSection::query()
    ->where('media_processing_log_id', $logId)
    ->orderBy('section_order')
    ->get();

echo PHP_EOL.'== SERVICE SECTIONS ('.$sections->count().') =='.PHP_EOL;

foreach ($sections as $section) {
    printf(
        "%-2s %5s-%-6s %-16s conf=%-4s%s\n",
        $section->section_order,
        $formatTime((float) $section->start_time),
        $formatTime((float) $section->end_time),
        $section->section_type->value,
        $section->confidence !== null ? round((float) $section->confidence, 2) : '-',
        $section->needs_manual_review ? '  [review]' : ''
    );
}
