<?php

declare(strict_types=1);

/**
 * Capture ruled endings as self-contained regression fixtures for RuledEndingsRegressionTest
 * (plan §4.5 regression set, 2026-10-14). Read-only against the live database; needs this
 * machine's endings listening rulings in storage/scratch.
 *
 *   RMS_WINDOW=35 vendor/bin/sail php scripts/ruled-endings/capture.php <ids.txt> tests/Fixtures/RuledEndings
 *   vendor/bin/sail php scripts/ruled-endings/capture.php <ids.txt> <review-dir> --current --review-only
 *
 * --review-only exports immutable recorded-output identities and executed spans, without planning
 * or modifying a run. These records identify the media to play; their stored hash must match the
 * file used to build a listening clip. Raw section bounds are never substituted for an output.
 *
 * An id is either an endings-listening id (end-<run>-<sermon|section id>) or a ruling id from
 * tests/Fixtures/Rulings/edge-rulings.json (plan §4.6; built by scripts/rulings/gather.py), whose
 * record becomes the fixture's ruling. A section ruling with no section id takes the run's section
 * of that type whose edge is nearest the cut heard. Capture only between rounds: the planner is
 * resolved inside a rolled-back transaction on the live database.
 *
 * Each fixture holds what the cut planner reads for that run: the run row, every section, the
 * transcript and audio timeline, sound levels in windows around the section edges (with the
 * threshold the whole file gives, so the trim cannot move it), the banked edge words, sentence
 * answers and sermon-song introduction answers (register K16), the operator's ruling, and the cut
 * the current code gives (`live_cut`).
 *
 * A sermon beside a song whose introduction question was never asked on the live run (any run
 * not through the edge step since K16, 2026-10-16) captures no answer, and its fixture fails on
 * missing evidence: the script warns. The fixtures captured before then carry the model's answers
 * in a `song_introduction` block, which the test replays; keep that block when re-capturing one
 * of them until its live run has been asked.
 */

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\CutReviewEvidence;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ServiceArtifactDisk;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The full pass banked its edge evidence under these (endings-rule/replay.php).
$current = in_array('--current', $argv, true);
$config = [
    'media-processing.media_processing_version' => $current ? (int) config('media-processing.media_processing_version') : 8,
    'media-processing.video_extraction.encoder' => $current ? config('media-processing.video_extraction.encoder') : 'libx264',
    'media-processing.service_structure.transcription_service' => (string) config('media-processing.service_structure.transcription_service'),
    'media-processing.transcription.local_whisper_model' => (string) config('media-processing.transcription.local_whisper_model'),
];
config($config);
// The banked evidence's version signature hashes these whole groups (MediaProcessingVersion::signature()).
foreach (['audio_enhancement', 'audio_treatment', 'video_extraction'] as $group) {
    $config["media-processing.{$group}"] = config("media-processing.{$group}");
}

$keyFile = base_path('storage/scratch/endings-listening/answer-key.json');
$key = json_decode((string) file_get_contents($keyFile), true);
$joined = collect(json_decode((string) file_get_contents(base_path('storage/scratch/endings-rule/joined.json')), true))->keyBy('id');
$ids = array_values(array_filter(array_map('trim', file($argv[1]))));
$outDir = $argv[2];
@mkdir($outDir, 0777, true);

$windowSeconds = (float) (getenv('RMS_WINDOW') ?: 45);
$dropMetadata = ['service_structure_ensemble', 'corpus_rerun', 'media_outputs', 'historic_import', 'processing_fingerprint',
    'video_quality', 'stored_video', 'historic_promotion', 'speaker_identification'];
$sectionColumns = ['id', 'section_type', 'start_time', 'end_time', 'confidence', 'status', 'metadata', 'section_order',
    'publication_status', 'song_match_type', 'title', 'needs_manual_review', 'duration', 'summary', 'source_segment_ids'];

$edgeRulings = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/Rulings/edge-rulings.json')), true)['rulings'])->keyBy('id');
if ($current) {
    $edgeRulings = $edgeRulings->merge(collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/Rulings/recut-20261019.json')), true)['rulings'])->keyBy('id'));
}

$cutFor = static function (MediaProcessingLog $log, string $which, string $edge = 'end'): array {
    if ($which === 'sermon') {
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);
        $audits = $plan['metadata']['cue_edge_widening'] ?? [];
    } else {
        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail((int) $which));
        $audits = $plan['cue_edge_widening'];
    }
    $edges = array_values(array_filter($audits, static fn ($a) => ($a['edge'] ?? null) === $edge));
    $audit = $edges === [] ? null : ($edge === 'start' ? $edges[0] : end($edges));
    $cut = match (true) {
        $plan['segments'] === [] => null,
        $edge === 'start' => (float) reset($plan['segments'])['start_time'],
        default => (float) end($plan['segments'])['end_time'],
    };

    return ['kind' => 'planned_cut', 'cut' => $cut, 'reason' => $audit['reason'] ?? null, 'segments' => $plan['segments']];
};

/**
 * The ruling, the run, the output and the edge for an id; for an edge-rulings.json section ruling
 * without a section id, the run's section of that type with the edge nearest the cut heard.
 *
 * @return array{0: array<string, mixed>, 1: int, 2: string, 3: string, 4: float}
 */
$resolve = static function (string $id) use ($edgeRulings, $key, $joined): array {
    if (! $edgeRulings->has($id)) {
        [, $runId, $which] = explode('-', $id, 3);

        return [$key[$id] + ['ruled_cut' => (float) $joined[$id]['cut']], (int) $runId, $which, 'end', (float) $joined[$id]['cut']];
    }

    $ruling = $edgeRulings[$id];
    $which = $ruling['output'] === 'sermon' ? 'sermon' : (string) $ruling['section_id'];

    if ($ruling['output'] === 'section' && $ruling['section_id'] === null) {
        $column = $ruling['edge'] === 'start' ? 'start_time' : 'end_time';
        $section = ServiceSection::query()->where('media_processing_log_id', $ruling['run'])
            ->when($ruling['section_type'], fn ($query, $type) => $query->where('section_type', $type))
            ->get()->sortBy(fn (ServiceSection $s) => abs((float) $s->{$column} - $ruling['heard']))->first()
            ?? throw new RuntimeException("{$id}: no section to rule on");
        $which = (string) $section->id;
        $ruling['section_id'] = $section->id;
    }

    return [$ruling, (int) $ruling['run'], $which, $ruling['edge'], (float) $ruling['heard']];
};

foreach ($ids as $id) {
    [$ruling, $runId, $which, $edge, $ruledCut] = $resolve($id);
    if (in_array('--review-only', $argv, true)) {
        $log = MediaProcessingLog::findOrFail($runId);
        $review = app(CutReviewEvidence::class)->recorded($log, $which === 'sermon' ? null : (int) $which);
        $review['edge'] = $edge;
        $review['recorded_edge'] = $edge === 'start' ? $review['segments'][0]['start_time'] : $review['segments'][array_key_last($review['segments'])]['end_time'];
        $review['previously_heard'] = $ruling['heard'] ?? $ruledCut;
        file_put_contents($outDir.'/'.str_replace(':', '-', $id).'.json', json_encode($review, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));

        continue;
    }
    DB::beginTransaction();
    try {
        $log = MediaProcessingLog::findOrFail($runId);
        $live = $cutFor($log, $which, $edge);
    } catch (Throwable $exception) {
        if (! $current) {
            throw $exception;
        }
        fwrite(STDERR, "UNASSESSABLE {$id}: {$exception->getMessage()}\n");

        continue;
    } finally {
        DB::rollBack();
    }

    if ($current && ($live['cut'] === null || $ruling['expect'] === null)) {
        fwrite(STDERR, "UNASSESSABLE {$id}: no executable cut or no ruled expectation\n");

        continue;
    }

    $log = MediaProcessingLog::findOrFail((int) $runId);
    $metadata = $log->processing_metadata?->toArray() ?? [];
    $files = [];
    $keepArtifacts = [];

    foreach (ServiceArtifactStorage::recordedFor($log) as $entry) {
        if (! preg_match('/^(edge-(words|sentence)|sermon-song-introduction)-/', $entry['kind'])) {
            continue;
        }
        $raw = rescue(fn () => Storage::disk($entry['disk'])->get($entry['path']), null, false);
        if (is_string($raw)) {
            $files[] = ['disk' => $entry['disk'], 'path' => $entry['path'], 'content' => $raw];
            $keepArtifacts[$entry['path']] = true;
        }
    }
    $metadata[ServiceArtifactStorage::METADATA_KEY] = array_values(array_filter(
        $metadata[ServiceArtifactStorage::METADATA_KEY] ?? [],
        static fn ($entry) => is_array($entry) && isset($keepArtifacts[$entry['path'] ?? '']),
    ));

    foreach ([$metadata['service_transcript_path'] ?? null, $log->audio_timeline_path] as $path) {
        if (is_string($path) && $path !== '') {
            $files[] = ['disk' => ServiceArtifactDisk::for($path), 'path' => $path, 'content' => Storage::disk(ServiceArtifactDisk::for($path))->get($path)];
        }
    }

    $sections = ServiceSection::query()->where('media_processing_log_id', $log->id)->orderBy('start_time')->orderBy('id')->get();

    if ($which === 'sermon') {
        $selected = $metadata['sermon_composition']['selected_section_ids'] ?? [];
        $spans = $sections->whereIn('id', $selected)->map(static fn (ServiceSection $s): array => ['start_time' => (float) $s->start_time, 'end_time' => (float) $s->end_time])->values()->all();
        $introduction = app(SermonSongIntroductionCheck::class);
        if ($introduction->songStart($log, $spans) !== null && $introduction->read($log, $spans) === null) {
            fwrite(STDERR, "WARNING {$id}: a song follows the sermon, but the live run has no banked introduction answer (K16); this fixture will fail on missing evidence unless it carries a song_introduction block.\n");
        }
    }
    // Levels only around the edges this ruling depends on: the ruled cut, the target section's
    // own edges (every sermon section's, for a sermon edge), and any edge near the cut.
    $edges = [$ruledCut];
    foreach ($sections as $section) {
        $isTarget = $which === 'sermon'
            ? $section->section_type->value === 'sermon'
            : $section->id === (int) $which;
        foreach ([(float) $section->start_time, (float) $section->end_time] as $edge) {
            if ($isTarget || abs($edge - $ruledCut) <= 120.0) {
                $edges[] = $edge;
            }
        }
    }

    $rmsContent = Storage::disk(ServiceArtifactDisk::for($log->rms_log_path))->get($log->rms_log_path);
    $rmsService = app(RmsAnalysisService::class);
    $threshold = (float) $rmsService->determineThreshold($rmsContent)['threshold'];
    $samples = [];
    foreach ($rmsService->extractRmsData($rmsContent) as $sample) {
        foreach ($edges as $edge) {
            if (abs($sample['time'] - $edge) <= $windowSeconds) {
                $samples[] = [round($sample['time'], 3), is_finite($sample['rms']) ? round($sample['rms'], 2) : -999.0];
                break;
            }
        }
    }

    $fixture = [
        'id' => $id,
        'ruling' => $ruling,
        'open_defect' => $current && $ruling['expect'] !== 'keep',
        'live' => $live + ['code' => trim((string) shell_exec('git -c safe.directory=/var/www/html -C /var/www/html rev-parse --short HEAD'))],
        'config' => $config,
        'run' => [
            'id' => $log->id,
            'processing_id' => $log->processing_id,
            'processing_type' => $log->processing_type->value,
            'status' => $log->status->value,
            'duration' => (float) $log->duration,
            'extracted_date' => $log->extracted_date?->toDateString(),
            'extracted_service' => $log->extracted_service?->value,
            'audio_timeline_path' => $log->audio_timeline_path,
            'rms_log_path' => $log->rms_log_path,
            'processing_metadata' => array_diff_key($metadata, array_flip($dropMetadata)),
        ],
        // A section's own transcript copy and AI notes are not read by the planner (the transcript file is).
        'sections' => $sections->map(function (ServiceSection $s) use ($sectionColumns): array {
            $row = collect($sectionColumns)->mapWithKeys(fn ($c) => [$c => $s->getRawOriginal($c)])->all();
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            unset($meta['transcript'], $meta['ai_notes']);
            $row['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

            return $row;
        })->all(),
        'rms' => ['disk' => ServiceArtifactDisk::for($log->rms_log_path), 'threshold' => $threshold, 'window_seconds' => $windowSeconds, 'samples' => $samples],
        'files' => $files,
    ];

    $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $fileName = str_replace(':', '-', $id);
    file_put_contents("{$outDir}/{$fileName}.json.gz", gzencode($json, 9));
    printf("%s live=%s %s %d KB\n", $id, $live['cut'] === null ? 'null' : number_format($live['cut'], 2), $live['reason'] ?? '-', strlen(gzencode($json, 9)) / 1024);
}
