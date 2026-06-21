<?php

declare(strict_types=1);

use App\Actions\DeleteLivestreamUpload;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;

// Deletes one local regression run and its generated media.
// Usage:
// vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-cleanup.php");'

$scenario ??= 'may24';

$scenarios = require base_path('scripts/section-extraction/scenarios.php');

if (! isset($scenarios[$scenario])) {
    throw new InvalidArgumentException(
        sprintf('Unknown scenario "%s". Available: %s', $scenario, implode(', ', array_keys($scenarios)))
    );
}

$cfg = $scenarios[$scenario];

config([
    'media-processing.storage.sermon_disk' => 'local',
    'media-processing.storage.transcript_disk' => 'local',
    'queue.default' => 'sync',
]);

$pidPath = base_path('storage/scratch/'.$cfg['pid_file']);
$baselinePath = base_path("storage/scratch/{$scenario}_post_transcription_baseline.json");

if (! is_file($pidPath)) {
    throw new RuntimeException("Missing {$cfg['pid_file']}; there is no recorded run to clean up.");
}

$processingId = trim((string) file_get_contents($pidPath));
$processingLog = MediaProcessingLog::query()
    ->where('processing_id', $processingId)
    ->first();

if (! $processingLog instanceof MediaProcessingLog) {
    unlink($pidPath);

    if (is_file($baselinePath)) {
        unlink($baselinePath);
    }

    echo "Processing run {$processingId} no longer exists; removed stale scratch pointers.".PHP_EOL;

    return;
}

if (in_array($processingLog->status, [
    ProcessingStatus::Pending,
    ProcessingStatus::Started,
    ProcessingStatus::Processing,
], true)) {
    $cancelled = app(ProcessingRunOrchestrator::class)->cancel($processingLog);

    if (! $cancelled) {
        throw new RuntimeException("Unable to cancel active processing run {$processingId}.");
    }

    $processingLog = $processingLog->fresh();
    if (! $processingLog instanceof MediaProcessingLog) {
        throw new RuntimeException("Processing run {$processingId} disappeared after cancellation.");
    }
}

$result = app(DeleteLivestreamUpload::class)->execute($processingLog);

unlink($pidPath);

if (is_file($baselinePath)) {
    unlink($baselinePath);
}

echo "Deleted processing run {$result['processing_id']}.".PHP_EOL;
echo "Deleted sermons: {$result['deleted_sermons']}".PHP_EOL;
echo "Deleted projected items: {$result['deleted_projected_items']}".PHP_EOL;
echo "Deleted empty services: {$result['deleted_services']}".PHP_EOL;
echo 'Removed scratch pid and baseline files.'.PHP_EOL;
