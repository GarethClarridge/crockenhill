<?php

declare(strict_types=1);

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\AlignWithOos;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\ExtractSermon;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\ReclassifyIntroOutroSections;
use App\Jobs\ResolveReadingReferences;
use App\Jobs\TranscribeSpeechSegments;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use Illuminate\Support\Str;

// Section-extraction regression runner.
// Set $includeTranscription = true after run-step2.php to create a clean
// post-transcription baseline. Later runs restore that baseline before
// re-running the classifier and downstream section-extraction jobs.

$scenario ??= 'may24';
$includeTranscription ??= false;

$scenarios = require base_path('scripts/section-extraction/scenarios.php');

if (! isset($scenarios[$scenario])) {
    throw new InvalidArgumentException(
        sprintf('Unknown scenario "%s". Available: %s', $scenario, implode(', ', array_keys($scenarios)))
    );
}

$cfg = $scenarios[$scenario];
$baselinePath = base_path("storage/scratch/{$scenario}_post_transcription_baseline.json");
$gitCommit = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));
$gitCommit = $gitCommit !== '' ? $gitCommit : 'unknown';

config([
    'media-processing.storage.sermon_disk' => 'local',
    'media-processing.storage.transcript_disk' => 'local',
    'queue.default' => 'sync',
]);

$analysisService = (string) config('media-processing.analysis.service');
$transcriptionService = (string) config('media-processing.transcription.service');
$classificationModel = (string) config('media-processing.section_classification.model');

if ($analysisService !== 'openai') {
    throw new RuntimeException('Set ANALYSIS_SERVICE=openai before running section-extraction regressions.');
}

if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
    throw new RuntimeException('Set OPENAI_API_KEY before running section-extraction regressions.');
}

if ($includeTranscription && $transcriptionService !== 'local') {
    throw new RuntimeException('Set TRANSCRIPTION_SERVICE_TYPE=local before creating a transcription baseline.');
}

echo '== DOWNSTREAM: '.$cfg['label'].($includeTranscription ? ' [create baseline]' : ' [restore baseline]').' =='.PHP_EOL;
echo "git_commit={$gitCommit}".PHP_EOL;
echo "classification_model={$classificationModel}".PHP_EOL;
echo "analysis_service={$analysisService}".PHP_EOL;
echo "transcription_service={$transcriptionService}".PHP_EOL.PHP_EOL;

$fmt = static function (?float $seconds): string {
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

$pid = trim((string) file_get_contents($pidPath));
$id = MediaProcessingLog::query()->where('processing_id', $pid)->value('id');

if (! is_numeric($id)) {
    throw new RuntimeException("No MediaProcessingLog found for pid={$pid}. Run run-init.php and run-step2.php first.");
}

$id = (int) $id;
$reload = static fn (): ?MediaProcessingLog => MediaProcessingLog::query()->find($id);

$run = static function (string $class) use ($reload): void {
    $processingLog = $reload();
    if (! $processingLog instanceof MediaProcessingLog) {
        throw new RuntimeException('MediaProcessingLog disappeared during the regression run.');
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

$writeBaseline = static function () use (
    $baselinePath,
    $classificationModel,
    $gitCommit,
    $id,
    $pid,
    $reload
): void {
    $processingLog = $reload();
    if (! $processingLog instanceof MediaProcessingLog) {
        throw new RuntimeException('Cannot write a baseline for a missing processing log.');
    }

    $sections = ServiceSection::query()
        ->where('media_processing_log_id', $id)
        ->orderBy('section_order')
        ->get()
        ->map(static fn (ServiceSection $section): array => [
            'church_service_item_id' => $section->church_service_item_id,
            'section_type' => $section->section_type->value,
            'section_order' => $section->section_order,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
            'duration' => (float) $section->duration,
            'confidence' => (float) $section->confidence,
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'source_segment_ids' => $section->source_segment_ids,
            'metadata' => $section->metadata?->toArray() ?? [],
        ])
        ->all();

    if ($sections === []) {
        throw new RuntimeException('Cannot write a baseline without service sections.');
    }

    $payload = [
        'version' => 1,
        'processing_id' => $pid,
        'created_at' => now()->toIso8601String(),
        'git_commit' => $gitCommit,
        'classification_model' => $classificationModel,
        'log' => [
            'church_service_id' => $processingLog->church_service_id,
            'processing_metadata' => $processingLog->processing_metadata?->toArray() ?? [],
        ],
        'sections' => $sections,
    ];

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($baselinePath, $json.PHP_EOL) === false) {
        throw new RuntimeException("Unable to write baseline {$baselinePath}.");
    }

    echo 'Wrote baseline '.basename($baselinePath).' with '.count($sections).' sections.'.PHP_EOL;
};

$restoreBaseline = static function () use ($baselinePath, $id, $pid, $reload): void {
    if (! is_file($baselinePath)) {
        throw new RuntimeException(
            'Missing '.basename($baselinePath).'. Run with $includeTranscription=true after run-step2.php first.'
        );
    }

    $contents = file_get_contents($baselinePath);
    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read baseline {$baselinePath}.");
    }

    $baseline = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (($baseline['processing_id'] ?? null) !== $pid) {
        throw new RuntimeException(
            'The saved baseline belongs to a different processing run. Recreate it with $includeTranscription=true.'
        );
    }

    $sectionPayloads = $baseline['sections'] ?? null;
    if (! is_array($sectionPayloads) || $sectionPayloads === []) {
        throw new RuntimeException('The saved baseline contains no service sections.');
    }

    $hasPublicationArtifacts = ServiceSection::query()
        ->where('media_processing_log_id', $id)
        ->where(function ($query): void {
            $query
                ->where('publication_status', '!=', ServiceSectionPublicationStatus::NotApplicable->value)
                ->orWhereNotNull('extracted_video_path')
                ->orWhereNotNull('extracted_audio_path')
                ->orWhereNotNull('published_sermon_id');
        })
        ->exists();

    if ($hasPublicationArtifacts) {
        throw new RuntimeException(
            'This run has section-publication artifacts. Use run-cleanup.php and create a fresh run before restoring its baseline.'
        );
    }

    $processingLog = $reload();
    if (! $processingLog instanceof MediaProcessingLog) {
        throw new RuntimeException('Cannot restore a baseline for a missing processing log.');
    }

    app(ServiceSectionSyncService::class)->sync($processingLog, $sectionPayloads);

    foreach ($sectionPayloads as $sectionPayload) {
        if (! is_array($sectionPayload) || ! isset($sectionPayload['section_order'])) {
            throw new RuntimeException('The saved baseline contains an invalid service-section payload.');
        }

        $section = ServiceSection::query()
            ->where('media_processing_log_id', $id)
            ->where('section_order', $sectionPayload['section_order'])
            ->first();

        if (! $section instanceof ServiceSection) {
            throw new RuntimeException("Unable to restore section order {$sectionPayload['section_order']}.");
        }

        $section->forceFill(array_merge($sectionPayload, [
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'published_sermon_id' => null,
            'published_at' => null,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'unpublished_expires_at' => null,
        ]));
        $section->save();
    }

    $processingLog->update([
        'church_service_id' => $baseline['log']['church_service_id'] ?? null,
        'processing_metadata' => $baseline['log']['processing_metadata'] ?? [],
    ]);

    echo 'Restored baseline '.basename($baselinePath).' (commit '
        .($baseline['git_commit'] ?? 'unknown').', model '
        .($baseline['classification_model'] ?? 'unknown').').'.PHP_EOL;
};

if ($includeTranscription) {
    $run(TranscribeSpeechSegments::class);
    $writeBaseline();
} else {
    $restoreBaseline();
}

$processingLog = $reload();
if (! $processingLog instanceof MediaProcessingLog) {
    throw new RuntimeException('MediaProcessingLog disappeared before classification.');
}

$processingLog->update([
    'status' => ProcessingStatus::Processing,
    'current_step' => ProcessingStep::TranscribeSpeechSegments->value,
    'error_message' => null,
]);

echo 'Reset run status -> processing'.PHP_EOL.PHP_EOL;

$run(ClassifySpeechSections::class);
$run(ProjectLivestreamServiceStructure::class);
$run(AlignWithOos::class);
$run(ResolveReadingReferences::class);
$run(MatchSongsFromTranscript::class);
$run(ReclassifyIntroOutroSections::class);
$run(ExtractSermon::class);

$log = $reload();
if (! $log instanceof MediaProcessingLog) {
    throw new RuntimeException('MediaProcessingLog disappeared before reporting.');
}

$actualStatus = $log->status->value;
$actualStep = $log->current_step ?? 'NULL';
$actualServiceId = $log->church_service_id;

$sections = ServiceSection::query()
    ->where('media_processing_log_id', $id)
    ->orderBy('section_order')
    ->get();

$confirmedSongCount = $sections
    ->filter(
        static fn (ServiceSection $section): bool => $section->song_match_type === ServiceSectionSongMatchType::Confirmed
    )
    ->count();
$childrensTalkCount = $sections
    ->filter(
        static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::ChildrensTalk
    )
    ->count();
$sermonSection = $sections
    ->first(
        static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon
    );

echo PHP_EOL.'== LOG =='.PHP_EOL;
echo "current_step={$actualStep}  status={$actualStatus}".PHP_EOL;
echo 'church_service_id='.($actualServiceId ?? 'NULL').' (expect '.$cfg['expected_service_id'].')'.PHP_EOL;
echo 'error_message='.($log->error_message ?? '-').PHP_EOL;

$assertionFailures = [];
$recordAssertion = static function (
    string $label,
    bool $passed,
    string $actual,
    string $expected
) use (&$assertionFailures): void {
    echo str_pad($label, 18).': '.($passed ? 'PASS' : "FAIL (got {$actual}, want {$expected})").PHP_EOL;

    if (! $passed) {
        $assertionFailures[] = "{$label}: got {$actual}, want {$expected}";
    }
};

echo PHP_EOL.'== STABLE ASSERTIONS =='.PHP_EOL;

if ($cfg['expected_status'] !== null) {
    $recordAssertion('status', $actualStatus === $cfg['expected_status'], $actualStatus, $cfg['expected_status']);
    $recordAssertion('step', $actualStep === $cfg['expected_step'], $actualStep, $cfg['expected_step']);
}

$recordAssertion(
    'service id',
    $actualServiceId === $cfg['expected_service_id'],
    (string) ($actualServiceId ?? 'NULL'),
    (string) $cfg['expected_service_id']
);

if ($cfg['expected_section_count'] !== null) {
    $recordAssertion(
        'section count',
        $sections->count() === $cfg['expected_section_count'],
        (string) $sections->count(),
        (string) $cfg['expected_section_count']
    );
    $recordAssertion(
        'confirmed songs',
        $confirmedSongCount === $cfg['expected_confirmed_songs'],
        (string) $confirmedSongCount,
        (string) $cfg['expected_confirmed_songs']
    );
    $recordAssertion(
        'children\'s talks',
        $childrensTalkCount === $cfg['expected_childrens_talks'],
        (string) $childrensTalkCount,
        (string) $cfg['expected_childrens_talks']
    );
}

if (is_array($cfg['expected_sermon_range'])) {
    $toleranceSeconds = 15.0;
    $expectedStart = (float) $cfg['expected_sermon_range'][0];
    $expectedEnd = (float) $cfg['expected_sermon_range'][1];
    $sermonRangePassed = $sermonSection instanceof ServiceSection
        && abs((float) $sermonSection->start_time - $expectedStart) <= $toleranceSeconds
        && abs((float) $sermonSection->end_time - $expectedEnd) <= $toleranceSeconds;
    $actualRange = $sermonSection instanceof ServiceSection
        ? $fmt((float) $sermonSection->start_time).'-'.$fmt((float) $sermonSection->end_time)
        : 'missing';
    $expectedRange = $fmt($expectedStart).'-'.$fmt($expectedEnd).' ±15s';

    $recordAssertion('sermon range', $sermonRangePassed, $actualRange, $expectedRange);
}

echo PHP_EOL.'== SERVICE SECTIONS ('.$sections->count().') =='.PHP_EOL;

foreach ($sections as $section) {
    $matchedTitle = '';
    if ($section->matched_item_id) {
        $matchedItem = ChurchServiceItem::query()->find($section->matched_item_id);
        $matchedTitle = $matchedItem
            ? ' -> "'.Str::limit((string) $matchedItem->title, 30).'"'
                .($matchedItem->song_id ? ' [song#'.$matchedItem->song_id.']' : '')
            : '';
    }

    $metadata = $section->metadata?->toArray() ?? [];
    $anchor = isset($metadata['transcript_alignment']) ? ' align='.$metadata['transcript_alignment'] : '';
    $readingReference = ! empty($metadata['reading_reference'])
        ? ' READING='.$metadata['reading_reference'].'('.($metadata['reading_reference_source'] ?? '?').')'
        : '';
    $flags = ! empty($metadata['review_flags'])
        ? ' flags='.implode(',', (array) $metadata['review_flags'])
        : '';
    $transcript = isset($metadata['transcript'])
        ? Str::limit(trim((string) $metadata['transcript']), 48)
        : '';

    printf(
        "%-2s %5s-%-6s %-14s match=%-12s item=%-5s conf=%-4s%s%s%s%s%s\n     \"%s\"\n",
        $section->section_order,
        $fmt((float) $section->start_time),
        $fmt((float) $section->end_time),
        $section->section_type->value,
        $section->song_match_type?->value ?? '-',
        (string) ($section->matched_item_id ?? '-'),
        $section->confidence !== null ? (string) round((float) $section->confidence, 2) : '-',
        $section->needs_manual_review ? ' [review]' : ' [ok]',
        $anchor,
        $matchedTitle,
        $readingReference,
        $flags,
        $transcript !== '' ? $transcript : '(none)'
    );
}

echo PHP_EOL.'== ASSOCIATED SERMON/CHILDREN RECORDS =='.PHP_EOL;

$mainSermons = Sermon::query()
    ->where('livestream_processing_id', $log->processing_id)
    ->get();
$publishedSectionSermons = Sermon::query()
    ->whereIn('id', $sections->pluck('published_sermon_id')->filter()->all())
    ->get();
$associatedSermons = $mainSermons
    ->concat($publishedSectionSermons)
    ->unique('id')
    ->values();

if ($associatedSermons->isEmpty()) {
    echo '(none associated with this processing run)'.PHP_EOL;
}

foreach ($associatedSermons as $sermon) {
    echo 'Sermon#'.$sermon->id
        .' content_type='.$sermon->content_type->value
        .' service='.$sermon->service->value
        .' title="'.Str::limit((string) $sermon->title, 50).'"'
        .PHP_EOL;
}

echo PHP_EOL.'== ExtractSermon metadata =='.PHP_EOL;
$processingMetadata = $log->processing_metadata?->toArray() ?? [];

foreach (['trim', 'sermon_extraction', 'extraction', 'manual_review', 'sermon_candidate', 'sermon_candidates'] as $key) {
    if (isset($processingMetadata[$key])) {
        echo $key.': '.json_encode($processingMetadata[$key], JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
}

if ($assertionFailures !== []) {
    throw new RuntimeException(
        'Stable section-extraction assertions failed: '.implode('; ', $assertionFailures)
    );
}
