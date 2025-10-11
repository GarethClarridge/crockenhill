<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\VideoSegmentationService;
use Illuminate\Support\Facades\Storage;

// Path to the existing RMS log
$rmsLogPath = 'temp/rms_1308d893-2dfb-41e5-a9b6-0e1206f8980c.log';

echo "Testing segmentation fix with existing RMS log...\n";
echo "RMS Log: {$rmsLogPath}\n";

// Debug: Check file stats
$fullPath = Storage::disk('local')->path($rmsLogPath);
if (file_exists($fullPath)) {
    $lines = file($fullPath);
    $totalLines = count($lines);
    $frameLines = 0;
    $rmsLines = 0;
    $lastPtsTime = 0;

    foreach ($lines as $line) {
        if (preg_match('/^frame:/', $line)) {
            $frameLines++;
        }
        if (preg_match('/RMS_level=/', $line)) {
            $rmsLines++;
        }
        if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $matches)) {
            $lastPtsTime = max($lastPtsTime, (float)$matches[1]);
        }
    }

    echo "Debug Info:\n";
    echo "  Total lines: {$totalLines}\n";
    echo "  Frame lines: {$frameLines}\n";
    echo "  RMS lines: {$rmsLines}\n";
    echo "  Last pts_time found: {$lastPtsTime}s (" . round($lastPtsTime/60, 1) . "m)\n\n";
} else {
    echo "ERROR: File not found at {$fullPath}\n";
    exit(1);
}

$segmentationService = new VideoSegmentationService();

try {
    $result = $segmentationService->analyzeSegments($rmsLogPath);

    $segments = $result['segments'];
    $thresholdMetadata = $result['threshold_metadata'];

    echo "Threshold Method: {$thresholdMetadata['method']}\n";
    echo "Threshold Value: {$thresholdMetadata['threshold']}\n\n";

    echo "Total Segments Found: " . count($segments) . "\n\n";

    echo "Segments:\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-5s %-10s %-12s %-12s %-10s %-8s %-8s %s\n",
        "#", "Type", "Start", "End", "Duration", "Avg RMS", "Peak RMS", "Notes");
    echo str_repeat("-", 100) . "\n";

    foreach ($segments as $i => $segment) {
        printf("%-5d %-10s %02d:%02d:%02d (%4.0fs) %02d:%02d:%02d (%4.0fs) %7.1fm %8.1f %8.1f %s\n",
            $i,
            $segment->classification,
            floor($segment->startTime / 3600),
            floor(($segment->startTime % 3600) / 60),
            $segment->startTime % 60,
            $segment->startTime,
            floor($segment->endTime / 3600),
            floor(($segment->endTime % 3600) / 60),
            $segment->endTime % 60,
            $segment->endTime,
            $segment->duration / 60,
            $segment->avgRms ?? 0,
            $segment->peakRms ?? 0,
            $segment->isSermonCandidate ? '← SERMON CANDIDATE' : ''
        );
    }

    echo str_repeat("-", 100) . "\n\n";

    // Find speech segments
    $speechSegments = array_filter($segments, fn($s) => $s->isSpeech());
    $songSegments = array_filter($segments, fn($s) => $s->isSong());
    $sermonCandidate = array_filter($segments, fn($s) => $s->isSermonCandidate);

    echo "Summary:\n";
    echo "  Speech segments: " . count($speechSegments) . "\n";
    echo "  Song segments: " . count($songSegments) . "\n";
    echo "  Sermon candidates: " . count($sermonCandidate) . "\n\n";

    if (!empty($sermonCandidate)) {
        $sermon = array_values($sermonCandidate)[0];
        echo "Identified Sermon:\n";
        echo "  Start: " . gmdate('H:i:s', $sermon->startTime) . " ({$sermon->startTime}s)\n";
        echo "  End: " . gmdate('H:i:s', $sermon->endTime) . " ({$sermon->endTime}s)\n";
        echo "  Duration: " . round($sermon->duration / 60, 1) . " minutes\n";
    } else {
        echo "WARNING: No sermon candidate identified!\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest completed successfully!\n";
