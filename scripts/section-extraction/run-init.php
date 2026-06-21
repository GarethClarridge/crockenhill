<?php

declare(strict_types=1);

use App\Enums\MediaType;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingInitiator;
use Illuminate\Http\UploadedFile;

// Initialise a new processing run: store the video to the temp disk,
// extract metadata, and create a MediaProcessingLog.
// Usage:
// vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
//
// Writes the processing_id to storage/scratch/{pid_file} for use by run-step2.php and run-downstream.php.

$scenario ??= 'may24';

$scenarios = require base_path('scripts/section-extraction/scenarios.php');

if (! isset($scenarios[$scenario])) {
    throw new InvalidArgumentException(
        sprintf('Unknown scenario "%s". Available: %s', $scenario, implode(', ', array_keys($scenarios)))
    );
}

$cfg = $scenarios[$scenario];
echo '== INIT: '.$cfg['label'].' =='.PHP_EOL;

config([
    'media-processing.storage.sermon_disk' => 'local',
    'media-processing.storage.transcript_disk' => 'local',
    'queue.default' => 'sync',
]);

$abs = base_path('storage/scratch/'.$cfg['video']);
if (! is_file($abs)) {
    throw new RuntimeException("Missing fixture {$cfg['video']}.");
}

$file = new UploadedFile($abs, $cfg['video'], 'video/mp4', null, true);

$storage = app(VideoStorageService::class);
$seg = app(VideoSegmentationService::class);
$initiator = app(ProcessingInitiator::class);

$up = $storage->storeUploadedVideo($file);
$meta = $seg->getVideoMetadata($up['full_path']);

$log = $initiator->initiateProcessing(
    $file,
    MediaType::Livestream,
    $cfg['date'],
    [
        'source_file_path' => $up['temp_path'],
        'file_size' => $up['file_size'],
        'duration' => $meta['duration'] ?? null,
        'file_hash' => null,
        'dedup_key' => null,
        'processing_metadata' => [
            'upload_time' => now()->toISOString(),
            'format_details' => $meta,
            'mime_type' => $up['mime_type'],
            'file_format' => 'mp4',
        ],
    ],
    serviceOverride: $cfg['service'],
);

$pidPath = base_path('storage/scratch/'.$cfg['pid_file']);
if (file_put_contents($pidPath, $log->processing_id) === false) {
    throw new RuntimeException("Unable to write {$cfg['pid_file']}.");
}

$baselinePath = base_path("storage/scratch/{$scenario}_post_transcription_baseline.json");
if (is_file($baselinePath)) {
    unlink($baselinePath);
}

$svc = $log->extracted_service instanceof BackedEnum ? $log->extracted_service->value : $log->extracted_service;
echo 'processing_id='.$log->processing_id.'  log_id='.$log->id.PHP_EOL;
echo 'processing_type='.$log->processing_type->value.PHP_EOL;
echo 'extracted_date='.($log->extracted_date?->toDateString() ?? 'NULL').'  extracted_service='.($svc ?? 'NULL').PHP_EOL;
echo 'church_service_id='.($log->church_service_id ?? 'NULL (resolved later by AlignWithOos)').PHP_EOL;
echo 'duration='.round((float) ($meta['duration'] ?? 0), 1).'s  (~'.round(((float) ($meta['duration'] ?? 0)) / 60, 1).' min)'.PHP_EOL;
echo 'source(temp_disk=local)='.$up['temp_path'].PHP_EOL;
echo 'status='.($log->status instanceof BackedEnum ? $log->status->value : $log->status).'  current_step='.($log->current_step ?? 'NULL').PHP_EOL;
echo 'pid written to '.$cfg['pid_file'].PHP_EOL;
echo 'cleared baseline '.basename($baselinePath).PHP_EOL;
