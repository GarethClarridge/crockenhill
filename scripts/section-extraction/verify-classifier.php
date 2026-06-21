<?php

declare(strict_types=1);

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SpeechSectionClassificationService;
use App\Support\ServiceSectionConfidence;

// Read-only classifier probe against persisted transcripts from one scenario.
// Usage:
// vendor/bin/sail artisan tinker --execute '$scenario="jun14"; require base_path("scripts/section-extraction/verify-classifier.php");'

$scenario ??= 'jun14';
$scenarios = require base_path('scripts/section-extraction/scenarios.php');

if (! isset($scenarios[$scenario])) {
    throw new InvalidArgumentException(
        sprintf('Unknown scenario "%s". Available: %s', $scenario, implode(', ', array_keys($scenarios)))
    );
}

$cfg = $scenarios[$scenario];
$pidPath = base_path('storage/scratch/'.$cfg['pid_file']);

if (! is_file($pidPath)) {
    throw new RuntimeException("Missing {$cfg['pid_file']}. Run the scenario first.");
}

$processingId = trim((string) file_get_contents($pidPath));
$processingLog = MediaProcessingLog::query()
    ->where('processing_id', $processingId)
    ->first();

if (! $processingLog instanceof MediaProcessingLog) {
    throw new RuntimeException("No MediaProcessingLog found for pid={$processingId}.");
}

echo "scenario               = {$scenario}".PHP_EOL;
echo "processing id          = {$processingId}".PHP_EOL;
echo 'classification model   = '.config('media-processing.section_classification.model').PHP_EOL;
echo 'analysis service       = '.config('media-processing.analysis.service').PHP_EOL.PHP_EOL;

$targets = ServiceSection::query()
    ->where('media_processing_log_id', $processingLog->id)
    ->whereIn('section_type', [
        ServiceSectionType::Sermon->value,
        ServiceSectionType::ChildrensTalk->value,
    ])
    ->orderBy('section_order')
    ->get()
    ->filter(static function (ServiceSection $section): bool {
        $transcript = $section->metadata['transcript'] ?? null;

        return is_string($transcript) && trim($transcript) !== '';
    });

if ($targets->isEmpty()) {
    throw new RuntimeException(
        'No persisted sermon or children\'s-talk section transcripts were found. Run run-downstream.php first.'
    );
}

$classifier = app(SpeechSectionClassificationService::class);

foreach ($targets as $section) {
    $transcript = (string) ($section->metadata['transcript'] ?? '');
    $duration = max(0.0, (float) $section->end_time - (float) $section->start_time);
    $label = $section->section_type->value." (order {$section->section_order})";

    echo "== {$label} (dur ".round($duration / 60, 1).' min, '.strlen($transcript).' transcript chars) =='.PHP_EOL;

    $results = $classifier->classify($section);

    foreach ($results as $index => $result) {
        $confidence = ServiceSectionConfidence::resolve(
            is_numeric($result['confidence'] ?? null) ? (float) $result['confidence'] : null,
            $result['metadata'] ?? []
        );
        $isHighConfidence = $result['needs_manual_review'] === false
            && $confidence >= ServiceSectionConfidence::HIGH_THRESHOLD;

        printf(
            "  [%d] type=%-14s conf=%-5s level=%-5s classifier_review=%s high-confidence=%s%s\n",
            $index,
            $result['section_type'],
            (string) round($confidence, 2),
            (string) ($result['metadata']['confidence_level'] ?? '-'),
            $result['needs_manual_review'] ? 'true' : 'false',
            $isHighConfidence ? 'YES' : 'no',
            isset($result['metadata']['review_reason']) ? ' reason='.$result['metadata']['review_reason'] : ''
        );

        if (! empty($result['metadata']['ai_anomalies'])) {
            echo '       anomalies: '.json_encode($result['metadata']['ai_anomalies']).PHP_EOL;
        }

        if (! empty($result['metadata']['ai_notes'])) {
            echo '       notes:     '.json_encode($result['metadata']['ai_notes']).PHP_EOL;
        }
    }

    echo PHP_EOL;
}
