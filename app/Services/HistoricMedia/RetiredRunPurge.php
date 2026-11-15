<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SermonProcessingStep;
use App\Models\SongVideo;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\CanonicalJson;
use App\Support\CodeRevision;
use App\Support\MediaAssetPath;
use App\Support\Path;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Delete what {@see HistoricRunRetirement} leaves behind for a retired run, so that no stored
 * copy of the run's content remains while the run row itself stays as the tombstone.
 *
 * Retirement withdraws visibility, not content: it moves the sermon's assets under
 * `superseded/` and marks the run superseded, but leaves the staged source, the service
 * transcript and every derived artifact, the section rows with their extracted media, and the
 * run-derived text on the church service. This removes exactly those, by enumeration — every
 * file is a recorded `(disk, path)` pair read from the run's own columns and metadata, never a
 * glob or a directory sweep.
 *
 * Fail closed, in this order: the run must be retired (superseded marker and retirement record
 * both present and agreeing), terminal and absent from the queues, no linked sermon may be
 * published, no section or song clip may be published or released, every superseded asset must
 * match the sha256 retirement recorded, and no other active run on the same service may still
 * reference a file to be deleted. Any refusal writes nothing.
 *
 * Database deletions share one transaction. File deletions follow the commit; the purge record
 * written in that transaction lists every file, so a part-way failure is resumable by running
 * the command again.
 *
 * Deletion trigger: none. This is a lasting maintenance route beside {@see HistoricRunRetirement},
 * and it serves weekly runs as well as historic ones.
 */
class RetiredRunPurge
{
    /** Disk label for a recorded absolute path under `storage/` or the system temp directory. */
    public const ABSOLUTE_DISK = '(absolute)';

    private const IN_FLIGHT = [ProcessingStatus::Pending, ProcessingStatus::Started, ProcessingStatus::Processing];

    private const SERVICE_TEXT_FIELDS = ['summary', 'notices', 'chapter_markers'];

    /**
     * The only `processing_metadata` keys the tombstone keeps: identifiers, hashes, the operation
     * and manifest binding, timestamps, and the retirement and purge records. Everything else is
     * removed and its key name is listed in the purge record.
     */
    public const KEPT_METADATA_KEYS = [
        'historic_import',
        'retirement',
        'purge',
        'upload_time',
        'mime_type',
        'file_format',
        'sermon_creation_completed_at',
        'transcript_content',
        'service_transcript_content',
        'analysed_transcript',
        'sermon_derived_from_service_transcript',
        'concatenated_source_restage',
    ];

    /** Keys removed from inside a kept `historic_import` block: editorial content, not identity. */
    private const HISTORIC_IMPORT_SCRUBBED = ['editorial_facts'];

    /** Retirement-record fields that carry the withdrawn sermon's title. */
    private const RETIREMENT_SERMON_SCRUBBED = ['title', 'slug'];

    /** Content-bearing columns on the run row, set to null. */
    private const SCRUBBED_COLUMNS = ['ai_analysis', 'error_message', 'rms_stats', 'visual_samples', 'song_clusters'];

    public function __construct(
        private readonly QueuedRunJobs $queuedJobs,
        private readonly HistoricStagingContextRegistry $stagingContexts,
    ) {}

    /**
     * Everything a purge of this run would do, enumerated, without writing anything.
     *
     * `state` is `purged` when nothing is left to do, `resume` when an earlier purge committed
     * its database work and still has files to remove, and `fresh` otherwise.
     *
     * @return array{
     *     run: MediaProcessingLog,
     *     state: string,
     *     files: list<array{kind: string, disk: string, path: string, bytes: int, exists: bool, sha256: ?string, expected_sha256: ?string}>,
     *     rows: list<array{table: string, id: int, detail: string}>,
     *     fragments: list<array{table: string, id: int, field: string, detail: string}>,
     *     left: list<string>
     * }
     */
    public function plan(string $processingId, bool $withChecksums = false): array
    {
        $run = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $run instanceof MediaProcessingLog) {
            throw new RuntimeException("No processing run exists for [{$processingId}].");
        }

        $record = $run->purgeRecord();

        if (is_array($record) && ($record['status'] ?? null) === 'completed') {
            return ['run' => $run, 'state' => 'purged', 'files' => [], 'rows' => [], 'fragments' => [], 'left' => []];
        }

        if (is_array($record)) {
            return [
                'run' => $run,
                'state' => 'resume',
                'files' => $this->remainingRecordedFiles($run, $record),
                'rows' => [],
                'fragments' => [],
                'left' => [],
            ];
        }

        $this->guardRetired($run);
        $this->guardTerminal($run);

        $retirement = (array) $run->retirementRecord();
        $left = [];
        $this->guardSermons($run, $retirement, $left);

        /** @var Collection<int, ServiceSection> $sections */
        $sections = ServiceSection::query()->where('media_processing_log_id', $run->id)->orderBy('section_order')->get();
        $sectionIds = $sections->modelKeys();

        /** @var Collection<int, SongVideo> $songVideos */
        $songVideos = $sectionIds === []
            ? new Collection
            : SongVideo::query()->whereIn('service_section_id', $sectionIds)->get();

        $this->guardUnreleased($sections, $songVideos);

        $files = $this->inRunContext($run, function () use ($run, $retirement, $sections, $songVideos, $withChecksums, &$left): array {
            $targets = $this->collectTargets($run, $retirement, $sections, $songVideos, $left);
            $this->guardNotShared($run, $targets, array_map(intval(...), array_values($sections->modelKeys())), array_map(intval(...), array_values($songVideos->modelKeys())));

            $files = [];

            foreach ($targets as $target) {
                $exists = $this->present($target['disk'], $target['path']);
                $sha256 = $exists && ($withChecksums || $target['expected_sha256'] !== null)
                    ? $this->sha256($target['disk'], $target['path'])
                    : null;

                if ($target['expected_sha256'] !== null) {
                    $this->guardChecksum($target, $exists, $sha256);
                }

                $files[] = [
                    'kind' => $target['kind'],
                    'disk' => $target['disk'],
                    'path' => $target['path'],
                    'bytes' => $exists ? $this->size($target['disk'], $target['path']) : 0,
                    'exists' => $exists,
                    'sha256' => $sha256,
                    'expected_sha256' => $target['expected_sha256'],
                ];
            }

            return $files;
        });

        $sourceRecords = $this->ownSourceRecords($run);

        return [
            'run' => $run,
            'state' => 'fresh',
            'files' => $files,
            'rows' => $this->rowsFor($sections, $songVideos, $run, $sourceRecords),
            'fragments' => [
                ...$this->serviceTextPlan($run, $sourceRecords, $left)['fragments'],
                ...$this->runRowFragments($run),
            ],
            'left' => $left,
        ];
    }

    /**
     * Purge the run: commit the database work and the purge record in one transaction, then
     * delete the recorded files. Running it again after a part-way failure finishes the files.
     *
     * @return array{state: string, files_deleted: int, bytes_deleted: int, rows_deleted: int, failures: list<string>}
     */
    public function apply(string $processingId): array
    {
        $plan = $this->plan($processingId, true);
        $run = $plan['run'];

        if ($plan['state'] === 'purged') {
            return ['state' => 'purged', 'files_deleted' => 0, 'bytes_deleted' => 0, 'rows_deleted' => 0, 'failures' => []];
        }

        $rowsDeleted = 0;

        if ($plan['state'] === 'fresh') {
            $this->commitDatabase($plan);
            $rowsDeleted = count($plan['rows']);
        }

        $run = $run->fresh() ?? $run;
        $result = $this->deleteRecordedFiles($run);

        return [
            'state' => $plan['state'],
            'files_deleted' => $result['files_deleted'],
            'bytes_deleted' => $result['bytes_deleted'],
            'rows_deleted' => $rowsDeleted,
            'failures' => $result['failures'],
        ];
    }

    private function guardRetired(MediaProcessingLog $run): void
    {
        $record = $run->retirementRecord();

        if ($record === null || $run->superseded_at === null) {
            throw new RuntimeException(sprintf(
                'Run [%s] is not retired through historic-import:retire-run (%s); retire it first.',
                $run->processing_id,
                $record === null && $run->superseded_at === null
                    ? 'no superseded marker and no retirement record'
                    : ($record === null ? 'superseded marker but no retirement record' : 'retirement record but no superseded marker'),
            ));
        }

        if (! is_array($record['assets'] ?? null)) {
            throw new RuntimeException("Run [{$run->processing_id}] has a retirement record with no asset list; it is not consistent and cannot be purged.");
        }
    }

    private function guardTerminal(MediaProcessingLog $run): void
    {
        if (in_array($run->status, self::IN_FLIGHT, true)) {
            throw new RuntimeException("Run [{$run->processing_id}] is still in flight ({$run->status->value}); it is not terminal.");
        }

        try {
            $queued = $this->queuedJobs->naming($run->id);
        } catch (Throwable $exception) {
            throw new RuntimeException("Could not confirm the queues hold no job for run [{$run->processing_id}]: {$exception->getMessage()}", 0, $exception);
        }

        if ($queued !== []) {
            throw new RuntimeException("Run [{$run->processing_id}] still has queued jobs: ".implode('; ', $queued).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $retirement
     * @param  list<string>  $left
     */
    private function guardSermons(MediaProcessingLog $run, array $retirement, array &$left): void
    {
        $ids = array_values(array_filter([
            $run->sermon_id,
            data_get($retirement, 'sermon.id'),
            ...ServiceSection::query()
                ->where('media_processing_log_id', $run->id)
                ->whereNotNull('published_sermon_id')
                ->pluck('published_sermon_id')
                ->all(),
        ], is_int(...)));

        $sermons = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->when($ids !== [], static fn ($query) => $query->orWhereIn('id', $ids))
            ->get();

        foreach ($sermons as $sermon) {
            if ($sermon->publication_state !== SermonPublicationState::Quarantined) {
                throw new RuntimeException("Sermon [{$sermon->id}] linked to run [{$run->processing_id}] still exists and is {$sermon->publication_state->value}; withdraw it first.");
            }

            $left[] = "sermon #{$sermon->id} (quarantined, still linked to the run): its own files are not this command's to delete";
        }

        
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int, SongVideo>  $songVideos
     */
    private function guardUnreleased(Collection $sections, Collection $songVideos): void
    {
        foreach ($sections as $section) {
            if (
                $section->publication_status === ServiceSectionPublicationStatus::Published
                || $section->published_sermon_id !== null
                || $section->published_at !== null
            ) {
                throw new RuntimeException("Section [{$section->id}] is published or released to members; unpublish it first.");
            }
        }

        foreach ($songVideos as $songVideo) {
            if ($songVideo->publication_state === SermonPublicationState::Published) {
                throw new RuntimeException("Song clip [{$songVideo->id}] is published; unpublish it first.");
            }
        }
    }

    /**
     * Every file this purge would delete, as `(disk, path)` pairs read from the run's own
     * records. `expected_sha256` is set only for the superseded sermon assets, which the
     * retirement record checksummed when it moved them.
     *
     * @param  array<string, mixed>  $retirement
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int, SongVideo>  $songVideos
     * @param  list<string>  $left
     * @return array<string, array{kind: string, disk: string, path: string, expected_sha256: ?string}>
     */
    private function collectTargets(
        MediaProcessingLog $run,
        array $retirement,
        Collection $sections,
        Collection $songVideos,
        array &$left,
    ): array {
        $targets = [];
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $sermonDisk = MediaAssetPath::disk();
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
        $sermonDisks = array_values(array_unique(array_filter([
            $sermonDisk,
            data_get($retirement, 'sermon.asset_disk'),
        ], is_string(...))));
        $archivePaths = $this->archivePaths($run);

        $inventories = [];

        foreach ((array) ($retirement['assets'] ?? []) as $asset) {
            $disk = is_array($asset) ? ($asset['disk'] ?? null) : null;
            $path = is_array($asset) ? ($asset['to'] ?? null) : null;

            if (! is_string($disk) || ! is_string($path) || ! str_starts_with($path, 'superseded/') || Path::isUnsafe($path)) {
                throw new RuntimeException("Run [{$run->processing_id}] has a retirement asset that does not name a path under superseded/; refusing to guess.");
            }

            $sha256 = $asset['sha256'] ?? null;

            if (! is_string($sha256) || $sha256 === '') {
                throw new RuntimeException("Retirement asset [{$disk}:{$path}] has no recorded sha256; it cannot be verified, so nothing was deleted.");
            }

            $this->add($targets, 'superseded_'.(string) ($asset['role'] ?? 'asset'), $disk, $path, $sha256, $archivePaths, $left);
            $inventories[$disk.'::'.dirname($path)] = [$disk, dirname($path).'/retirement.json'];
        }

        foreach ($inventories as [$disk, $path]) {
            $this->add($targets, 'retirement_inventory', $disk, $path, null, $archivePaths, $left);
        }

        $this->add($targets, 'staged_source', $tempDisk, $run->source_file_path, null, $archivePaths, $left);
        $this->add($targets, 'enhanced_audio', $tempDisk, $run->enhanced_audio_file_path, null, $archivePaths, $left);
        $this->add($targets, 'rms_log', ServiceArtifactDisk::for($run->rms_log_path), $run->rms_log_path, null, $archivePaths, $left);
        $this->add($targets, 'audio_timeline', ServiceArtifactDisk::for($run->audio_timeline_path), $run->audio_timeline_path, null, $archivePaths, $left);
        $this->add($targets, 'transcript', $transcriptDisk, $run->transcript_file_path, null, $archivePaths, $left);

        foreach ($sermonDisks as $disk) {
            $this->add($targets, 'run_audio', $disk, $run->audio_file_path, null, $archivePaths, $left);
            $this->add($targets, 'run_video', $disk, $run->video_file_path, null, $archivePaths, $left);
        }

        $transcriptPath = $run->serviceTranscriptPath();
        $this->add($targets, 'service_transcript', ServiceArtifactDisk::for($transcriptPath), $transcriptPath, null, $archivePaths, $left);

        foreach (ServiceArtifactStorage::recordedFor($run) as $artifact) {
            $this->add($targets, 'artifact_'.$artifact['kind'], $artifact['disk'], $artifact['path'], null, $archivePaths, $left);
        }

        foreach ((array) ($metadata['media_outputs'] ?? []) as $key => $output) {
            if (is_array($output)) {
                $this->add($targets, 'media_output_'.(string) $key, $output['disk'] ?? null, $output['path'] ?? null, null, $archivePaths, $left);
            }
        }

        $repair = $metadata['transcript_span_repair'] ?? null;

        if (is_array($repair)) {
            $this->add($targets, 'transcript_span_repair', $repair['disk'] ?? null, $repair['path'] ?? null, null, $archivePaths, $left);
        }

        $graded = data_get($metadata, 'video_quality.graded_output');

        if (is_array($graded)) {
            $this->add($targets, 'graded_output', $graded['disk'] ?? null, $graded['path'] ?? null, null, $archivePaths, $left);
        }

        foreach ((array) ($metadata['service_structure_ensemble'] ?? []) as $attempt) {
            if (! is_array($attempt)) {
                continue;
            }

            $disk = $attempt['artifact_disk'] ?? null;
            $this->add($targets, 'ensemble_input', $disk, $attempt['input_path'] ?? null, null, $archivePaths, $left);

            foreach ((array) ($attempt['slots'] ?? []) as $slot) {
                if (! is_array($slot)) {
                    continue;
                }

                $this->add($targets, 'ensemble_draw', $disk, $slot['path'] ?? null, null, $archivePaths, $left);
                $this->add($targets, 'ensemble_draw', $disk, data_get($slot, 'first_attempt.path'), null, $archivePaths, $left);
            }
        }

        foreach ($sections as $section) {
            $this->add($targets, 'section_video', $section->extractedAssetDisk(), $section->extracted_video_path, null, $archivePaths, $left);
            $this->add($targets, 'section_audio', $section->extractedAssetDisk(), $section->extracted_audio_path, null, $archivePaths, $left);
        }

        foreach ($songVideos as $songVideo) {
            $this->add($targets, 'song_video', filled($songVideo->asset_disk) ? (string) $songVideo->asset_disk : $sermonDisk, $songVideo->video_file_path, null, $archivePaths, $left);
        }

        return $targets;
    }

    /**
     * @param  array<string, array{kind: string, disk: string, path: string, expected_sha256: ?string}>  $targets
     * @param  list<string>  $archivePaths
     * @param  list<string>  $left
     */
    private function add(array &$targets, string $kind, mixed $disk, mixed $path, ?string $expectedSha256, array $archivePaths, array &$left): void
    {
        if (! is_string($path) || trim($path) === '' || ! is_string($disk) || $disk === '') {
            return;
        }

        $path = trim($path);

        if ($disk !== self::ABSOLUTE_DISK && ! $this->diskIsConfigured($disk)) {
            throw new RuntimeException("{$kind} {$path} names the disk [{$disk}], which is not configured; refusing to guess.");
        }

        if (in_array($path, $archivePaths, true)) {
            $left[] = "{$kind} {$path}: names the archive master, which is never touched";

            return;
        }

        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:\\\\/', $path)) {
            if (! $this->isSafeAbsolutePath($path)) {
                $left[] = "{$kind} {$path}: absolute path outside storage and the system temp directory, not this command's to delete";

                return;
            }

            $disk = self::ABSOLUTE_DISK;
        } elseif (Path::isUnsafe($path)) {
            $left[] = "{$kind} {$path}: unsafe path, skipped";

            return;
        }

        $targets[$disk.'::'.$path] ??= ['kind' => $kind, 'disk' => $disk, 'path' => $path, 'expected_sha256' => $expectedSha256];
    }

    /**
     * @param  array{kind: string, disk: string, path: string, expected_sha256: ?string}  $target
     */
    private function guardChecksum(array $target, bool $exists, ?string $sha256): void
    {
        if (! $exists) {
            return;
        }

        if ($sha256 === null || ! hash_equals((string) $target['expected_sha256'], $sha256)) {
            throw new RuntimeException("Checksum mismatch for [{$target['disk']}:{$target['path']}]: retirement recorded {$target['expected_sha256']}, the file is ".($sha256 ?? 'unreadable').'. Nothing was deleted.');
        }
    }

    /**
     * A file another active run of the same service still references is that run's, not ours.
     *
     * @param  array<string, array{kind: string, disk: string, path: string, expected_sha256: ?string}>  $targets
     * @param  list<int>  $sectionIds
     * @param  list<int>  $songVideoIds
     */
    private function guardNotShared(MediaProcessingLog $run, array $targets, array $sectionIds, array $songVideoIds): void
    {
        $paths = array_values(array_unique(array_column($targets, 'path')));

        if ($paths === []) {
            return;
        }

        $columns = ['source_file_path', 'enhanced_audio_file_path', 'audio_file_path', 'video_file_path', 'transcript_file_path', 'rms_log_path', 'audio_timeline_path'];
        $anyRun = MediaProcessingLog::query()
            ->whereKeyNot($run->id)
            ->whereNull('superseded_at')
            ->where(function ($query) use ($columns, $paths): void {
                foreach ($columns as $column) {
                    $query->orWhereIn($column, $paths);
                }
            })
            ->first();

        if ($anyRun instanceof MediaProcessingLog) {
            throw new RuntimeException('A file to delete is still referenced by active run ['.$anyRun->processing_id.'], which is not this run\'s.');
        }

        $others = MediaProcessingLog::query()
            ->whereKeyNot($run->id)
            ->whereNull('superseded_at')
            ->where(function ($query) use ($run): void {
                $query->when($run->church_service_id !== null, fn ($inner) => $inner->orWhere('church_service_id', $run->church_service_id))
                    ->when($run->extracted_date !== null, fn ($inner) => $inner->orWhere(function ($same) use ($run): void {
                        $same->whereDate('extracted_date', $run->extracted_date)->where('extracted_service', $run->extracted_service);
                    }));
            })
            ->get();

        foreach ($others as $other) {
            $haystack = json_encode([
                $other->source_file_path, $other->enhanced_audio_file_path, $other->audio_file_path, $other->video_file_path,
                $other->transcript_file_path, $other->rms_log_path, $other->audio_timeline_path,
                $other->processing_metadata?->toArray(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

            foreach ($paths as $path) {
                if (str_contains($haystack, $path)) {
                    throw new RuntimeException("File [{$path}] is still referenced by active run [{$other->processing_id}] of the same service; it is not this run's to delete.");
                }
            }
        }

        $sharedSection = ServiceSection::query()
            ->whereNotIn('id', $sectionIds)
            ->where(fn ($query) => $query->whereIn('extracted_video_path', $paths)->orWhereIn('extracted_audio_path', $paths))
            ->first();

        if ($sharedSection instanceof ServiceSection) {
            throw new RuntimeException("A file to delete is still used by section [{$sharedSection->id}] of another run.");
        }

        $sharedSong = SongVideo::query()->whereNotIn('id', $songVideoIds)->whereIn('video_file_path', $paths)->first();

        if ($sharedSong instanceof SongVideo) {
            throw new RuntimeException("A file to delete is still used by song clip [{$sharedSong->id}] of another run.");
        }

        $sharedSermon = Sermon::query()
            ->where(fn ($query) => $query->whereIn('video_file_path', $paths)->orWhereIn('audio_file_path', $paths)->orWhereIn('transcript_file_path', $paths))
            ->first();

        if ($sharedSermon instanceof Sermon) {
            throw new RuntimeException("A file to delete is still used by sermon [{$sharedSermon->id}].");
        }
    }

    /**
     * This run's source records on the church service, with the records that supersede them.
     *
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function ownSourceRecords(MediaProcessingLog $run): Collection
    {
        $prefix = strtolower($run->processing_id).'|';

        /** @var Collection<int, ChurchServiceSourceRecord> $records */
        $records = ChurchServiceSourceRecord::query()
            ->where('source', 'livestream')
            ->where('source_key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->get();

        $frontier = $records->modelKeys();

        while ($frontier !== []) {
            $successors = ChurchServiceSourceRecord::query()
                ->whereIn('supersedes_id', $frontier)
                ->whereNotIn('id', $records->modelKeys())
                ->get();

            foreach ($successors as $successor) {
                if (! str_starts_with($successor->source_key, $prefix) || $successor->source->value !== 'livestream') {
                    throw new RuntimeException("Source record [{$successor->id}] of another source supersedes this run's evidence; deleting it would cascade into it.");
                }
            }

            $records = $records->concat($successors);
            $frontier = $successors->modelKeys();
        }

        return $records;
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int, SongVideo>  $songVideos
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @return list<array{table: string, id: int, detail: string}>
     */
    private function rowsFor(Collection $sections, Collection $songVideos, MediaProcessingLog $run, Collection $sourceRecords): array
    {
        $rows = [];

        foreach ($songVideos as $songVideo) {
            $rows[] = ['table' => 'song_videos', 'id' => $songVideo->id, 'detail' => "section {$songVideo->service_section_id}, {$songVideo->video_file_path}"];
        }

        foreach ($sections as $section) {
            $rows[] = ['table' => 'service_sections', 'id' => $section->id, 'detail' => "{$section->section_type->value} #{$section->section_order}"];
        }

        foreach (LivestreamSegment::query()->where('media_processing_log_id', $run->id)->pluck('id') as $id) {
            $rows[] = ['table' => 'livestream_segments', 'id' => (int) $id, 'detail' => 'RMS segment'];
        }

        foreach ($this->mergeProposalIds($sourceRecords) as $id) {
            $rows[] = ['table' => 'church_service_merge_proposals', 'id' => $id, 'detail' => 'triggered by this run\'s source evidence'];
        }

        foreach ($this->assertionIds($sourceRecords) as $id) {
            $rows[] = ['table' => 'church_service_item_assertions', 'id' => $id, 'detail' => 'assertion of this run\'s source evidence'];
        }

        foreach ($sourceRecords as $record) {
            $rows[] = ['table' => 'church_service_source_records', 'id' => $record->id, 'detail' => $record->source_key];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @return list<int>
     */
    private function assertionIds(Collection $sourceRecords): array
    {
        return $sourceRecords->isEmpty() ? [] : array_values(ChurchServiceItemAssertion::query()
            ->whereIn('source_record_id', $sourceRecords->modelKeys())
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @return list<int>
     */
    private function mergeProposalIds(Collection $sourceRecords): array
    {
        return $sourceRecords->isEmpty() ? [] : array_values(DB::table('church_service_merge_proposals')
            ->whereIn('trigger_source_record_id', $sourceRecords->modelKeys())
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * What the run contributed to its church service's own text, and the columns that clear it.
     *
     * Summary, notices and chapter markers carry no run attribution of their own, so a field is
     * cleared only when it equals what this run's source record offered and no surviving record
     * (email, OpenLP, a replacement run) offers the same value. The conflict history and
     * projection marker are cleared only when this was the service's sole run, because their
     * entries name a source, not a run.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @param  list<string>  $left
     * @return array{service: ?ChurchService, fragments: list<array{table: string, id: int, field: string, detail: string}>, attributes: array<string, mixed>}
     */
    private function serviceTextPlan(MediaProcessingLog $run, Collection $sourceRecords, array &$left): array
    {
        $empty = ['service' => null, 'fragments' => [], 'attributes' => []];
        $serviceId = $run->church_service_id ?? $sourceRecords->first()?->church_service_id;
        $service = $serviceId === null ? null : ChurchService::query()->find($serviceId);

        if (! $service instanceof ChurchService) {
            return $empty;
        }

        $survivors = ChurchServiceSourceRecord::query()
            ->where('church_service_id', $service->id)
            ->whereNotIn('id', $sourceRecords->modelKeys())
            ->get();

        $fragments = [];
        $attributes = [];

        foreach (self::SERVICE_TEXT_FIELDS as $field) {
            $current = $service->{$field};

            if ($current === null || $current === '' || $current === []) {
                continue;
            }

            $currentHash = CanonicalJson::hash($current);
            $own = $sourceRecords->first(static fn (ChurchServiceSourceRecord $record): bool => self::contentHash($record, $field) === $currentHash);
            $survivor = $survivors->first(static fn (ChurchServiceSourceRecord $record): bool => self::contentHash($record, $field) === $currentHash);

            if ($own === null) {
                $left[] = "church_services #{$service->id}.{$field}: not offered by this run's source record, kept";

                continue;
            }

            if ($survivor !== null) {
                $left[] = "church_services #{$service->id}.{$field}: also offered by source record #{$survivor->id}, kept";

                continue;
            }

            $attributes[$field] = $field === 'summary' ? null : [];
            $fragments[] = ['table' => 'church_services', 'id' => $service->id, 'field' => $field, 'detail' => "cleared; equals source record #{$own->id}"];
        }

        $soleRun = ! MediaProcessingLog::query()->where('church_service_id', $service->id)->whereKeyNot($run->id)->exists();
        $importMetadata = $service->import_metadata?->toArray() ?? [];
        $history = array_values(array_filter(
            (array) ($importMetadata['canonical_conflict_history'] ?? []),
            static fn (mixed $entry): bool => is_array($entry),
        ));
        $ownHistory = array_keys(array_filter($history, static fn (array $entry): bool => ($entry['incoming_source'] ?? null) === 'livestream'));

        if ($soleRun) {
            foreach ($ownHistory as $index) {
                $fragments[] = ['table' => 'church_services', 'id' => $service->id, 'field' => "import_metadata.canonical_conflict_history[{$index}]", 'detail' => 'livestream conflict entry removed (this was the only run on the service)'];
            }

            if (is_array($importMetadata['livestream_projection'] ?? null)) {
                $fragments[] = ['table' => 'church_services', 'id' => $service->id, 'field' => 'import_metadata.livestream_projection', 'detail' => 'projection marker removed (this was the only run on the service)'];
            }

            if ($ownHistory !== [] || is_array($importMetadata['livestream_projection'] ?? null)) {
                $remaining = array_values(array_diff_key($history, array_flip($ownHistory)));
                unset($importMetadata['livestream_projection']);

                if ($remaining === []) {
                    unset($importMetadata['canonical_conflict_history']);
                } else {
                    $importMetadata['canonical_conflict_history'] = $remaining;
                }

                $attributes['import_metadata'] = $importMetadata;
            }
        } elseif ($ownHistory !== [] || is_array($importMetadata['livestream_projection'] ?? null)) {
            $left[] = "church_services #{$service->id}.import_metadata: livestream history and projection marker kept, because another run also belongs to this service and the entries name a source, not a run";
        }

        $itemCount = $service->items()->where('livestream_processing_id', $run->processing_id)->count();

        if ($itemCount > 0) {
            $left[] = "church_services #{$service->id}: {$itemCount} projected item(s) still carry this run's processing id; the order of service is not this command's to rewrite";
        }

        return ['service' => $service, 'fragments' => $fragments, 'attributes' => $attributes];
    }

    private static function contentHash(ChurchServiceSourceRecord $record, string $field): ?string
    {
        $value = is_array($record->service_content) ? ($record->service_content[$field] ?? null) : null;

        return $value === null || $value === '' || $value === [] ? null : CanonicalJson::hash($value);
    }

    /**
     * @return list<array{table: string, id: int, field: string, detail: string}>
     */
    private function runRowFragments(MediaProcessingLog $run): array
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $fragments = [];

        foreach (self::SCRUBBED_COLUMNS as $column) {
            if ($run->getRawOriginal($column) !== null) {
                $fragments[] = ['table' => 'media_processing_logs', 'id' => $run->id, 'field' => $column, 'detail' => 'set to null'];
            }
        }

        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, self::KEPT_METADATA_KEYS, true)) {
                $fragments[] = ['table' => 'media_processing_logs', 'id' => $run->id, 'field' => "processing_metadata.{$key}", 'detail' => 'removed; only the key name and a hash are kept in the purge record'];
            }
        }

        foreach (self::HISTORIC_IMPORT_SCRUBBED as $key) {
            if (array_key_exists($key, (array) ($metadata['historic_import'] ?? []))) {
                $fragments[] = ['table' => 'media_processing_logs', 'id' => $run->id, 'field' => "processing_metadata.historic_import.{$key}", 'detail' => 'removed'];
            }
        }

        foreach (self::RETIREMENT_SERMON_SCRUBBED as $key) {
            if (data_get($metadata, "retirement.sermon.{$key}") !== null) {
                $fragments[] = ['table' => 'media_processing_logs', 'id' => $run->id, 'field' => "processing_metadata.retirement.sermon.{$key}", 'detail' => 'set to null'];
            }
        }

        $steps = SermonProcessingStep::query()->where('processing_id', $run->processing_id)->whereNotNull('message')->count();

        if ($steps > 0) {
            $fragments[] = ['table' => 'sermon_processing_steps', 'id' => $run->id, 'field' => 'message', 'detail' => "{$steps} step message(s) set to null; the step rows stay"];
        }

        return $fragments;
    }

    /**
     * The one transaction: rows, church service text, run-row scrubbing and the purge record.
     *
     * @param  array{run: MediaProcessingLog, files: list<array<string, mixed>>, rows: list<array{table: string, id: int, detail: string}>, fragments: list<array<string, mixed>>, left: list<string>, state: string}  $plan
     */
    private function commitDatabase(array $plan): void
    {
        $rowIds = [];

        foreach ($plan['rows'] as $row) {
            $rowIds[$row['table']][] = $row['id'];
        }

        DB::transaction(function () use ($plan, $rowIds): void {
            $run = MediaProcessingLog::query()->lockForUpdate()->findOrFail($plan['run']->id);

            if (in_array($run->status, self::IN_FLIGHT, true)) {
                throw new RuntimeException("Run [{$run->processing_id}] went back in flight; nothing was deleted.");
            }

            if ($run->purgeRecord() !== null || $run->superseded_at === null || $run->retirementRecord() === null) {
                throw new RuntimeException("Run [{$run->processing_id}] changed since the inventory was taken (retired or purged elsewhere); nothing was deleted.");
            }

            $sourceRecords = $this->ownSourceRecords($run);
            $left = [];
            $serviceText = $this->serviceTextPlan($run, $sourceRecords, $left);
            $sectionIds = ServiceSection::query()
                ->whereIn('id', $rowIds['service_sections'] ?? [])
                ->where('media_processing_log_id', $run->id)
                ->pluck('id')->all();

            SongVideo::query()->whereIn('id', $rowIds['song_videos'] ?? [])->whereIn('service_section_id', $sectionIds)->delete();
            ServiceSection::query()->whereIn('id', $sectionIds)->delete();
            LivestreamSegment::query()->whereIn('id', $rowIds['livestream_segments'] ?? [])->where('media_processing_log_id', $run->id)->delete();
            DB::table('church_service_merge_proposals')->whereIn('id', $rowIds['church_service_merge_proposals'] ?? [])->delete();
            ChurchServiceItemAssertion::query()->whereIn('id', $rowIds['church_service_item_assertions'] ?? [])->delete();
            ChurchServiceSourceRecord::query()->whereIn('id', $rowIds['church_service_source_records'] ?? [])->delete();

            if ($serviceText['service'] instanceof ChurchService && $serviceText['attributes'] !== []) {
                $serviceText['service']->forceFill($serviceText['attributes'])->saveQuietly();
            }

            $metadata = $run->processing_metadata?->toArray() ?? [];
            $scrubbed = [];
            $kept = [];

            foreach ($metadata as $key => $value) {
                if (in_array($key, self::KEPT_METADATA_KEYS, true)) {
                    $kept[$key] = $value;
                } else {
                    $scrubbed[$key] = CanonicalJson::hash($value);
                }
            }

            $scrubbedFields = [];

            foreach (self::HISTORIC_IMPORT_SCRUBBED as $key) {
                if (is_array($kept['historic_import'] ?? null) && array_key_exists($key, $kept['historic_import'])) {
                    unset($kept['historic_import'][$key]);
                    $scrubbedFields[] = "historic_import.{$key}";
                }
            }

            foreach (self::RETIREMENT_SERMON_SCRUBBED as $key) {
                if (is_array($kept['retirement']['sermon'] ?? null) && ($kept['retirement']['sermon'][$key] ?? null) !== null) {
                    $kept['retirement']['sermon'][$key] = null;
                    $scrubbedFields[] = "retirement.sermon.{$key}";
                }
            }

            $metadata = $kept;
            $stepsCleared = SermonProcessingStep::query()->where('processing_id', $run->processing_id)->whereNotNull('message')->update(['message' => null]);

            $metadata['purge'] = [
                'status' => 'files_pending',
                'recorded_by' => 'operator',
                'recorded_at' => now()->toIso8601String(),
                'code_revision' => CodeRevision::current(),
                'files' => array_map(static fn (array $file): array => [
                    'kind' => $file['kind'],
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'bytes' => $file['bytes'],
                    'sha256' => $file['sha256'],
                    'existed' => $file['exists'],
                    'deleted_at' => null,
                ], $plan['files']),
                'rows' => $plan['rows'],
                'fragments' => $plan['fragments'],
                'scrubbed_metadata_hashes' => $scrubbed,
                'scrubbed_metadata_keys' => array_keys($scrubbed),
                'scrubbed_metadata_fields' => $scrubbedFields,
                'scrubbed_columns' => self::SCRUBBED_COLUMNS,
                'step_messages_cleared' => $stepsCleared,
                'left' => $plan['left'],
            ];

            $run->forceFill(['processing_metadata' => $metadata] + array_fill_keys(self::SCRUBBED_COLUMNS, null))->save();
        });
    }

    /**
     * Delete each file the purge record still lists, then settle the record.
     *
     * @return array{files_deleted: int, bytes_deleted: int, failures: list<string>}
     */
    private function deleteRecordedFiles(MediaProcessingLog $run): array
    {
        $record = $run->purgeRecord();

        if ($record === null) {
            throw new RuntimeException("Run [{$run->processing_id}] has no purge record to resume.");
        }

        $filesDeleted = 0;
        $bytesDeleted = 0;
        $failures = [];

        $this->inRunContext($run, function () use (&$record, &$filesDeleted, &$bytesDeleted, &$failures): void {
            foreach ($record['files'] as $index => $file) {
                if ($file['deleted_at'] !== null) {
                    continue;
                }

                try {
                    $this->assertRecordedFileSafe($file);

                    if ($this->present($file['disk'], $file['path'])) {
                        $this->remove($file['disk'], $file['path']);

                        if ($this->present($file['disk'], $file['path'])) {
                            throw new RuntimeException('the file is still present after the delete');
                        }

                        $filesDeleted++;
                        $bytesDeleted += (int) $file['bytes'];
                    }

                    $record['files'][$index]['deleted_at'] = now()->toIso8601String();
                    $this->removeEmptyOwnedDirectory($file);
                } catch (Throwable $exception) {
                    $failures[] = "{$file['disk']}:{$file['path']}: {$exception->getMessage()}";
                }
            }
        });

        $record['status'] = $failures === [] ? 'completed' : 'files_pending';

        if ($failures === []) {
            $record['purged_at'] = now()->toIso8601String();
            unset($record['failures']);
        } else {
            $record['failures'] = $failures;
        }

        $run->putPurge($record);

        return ['files_deleted' => $filesDeleted, 'bytes_deleted' => $bytesDeleted, 'failures' => $failures];
    }

    /**
     * A section's candidate directory belongs to that section alone, so it goes once its
     * recorded files have; a directory still holding anything else is left alone.
     *
     * @param  array<string, mixed>  $file
     */
    private function removeEmptyOwnedDirectory(array $file): void
    {
        if ($file['disk'] === self::ABSOLUTE_DISK) {
            return;
        }

        $directory = dirname((string) $file['path']);
        $kind = (string) $file['kind'];
        $owned = (in_array($kind, ['section_video', 'section_audio'], true) && str_starts_with($directory, 'section-publications/'))
            || ((str_starts_with($kind, 'superseded_') || $kind === 'retirement_inventory') && substr_count($directory, '/') >= 2 && str_starts_with($directory, 'superseded/'));

        if (! $owned) {
            return;
        }

        $disk = Storage::disk((string) $file['disk']);

        if ($disk->allFiles($directory) === []) {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{kind: string, disk: string, path: string, bytes: int, exists: bool, sha256: ?string, expected_sha256: ?string}>
     */
    private function remainingRecordedFiles(MediaProcessingLog $run, array $record): array
    {
        return $this->inRunContext($run, function () use ($record): array {
            $files = [];

            foreach ((array) ($record['files'] ?? []) as $file) {
                if (! is_array($file) || ($file['deleted_at'] ?? null) !== null) {
                    continue;
                }

                $this->assertRecordedFileSafe($file);
                $exists = $this->present((string) $file['disk'], (string) $file['path']);
                $files[] = [
                    'kind' => (string) $file['kind'],
                    'disk' => (string) $file['disk'],
                    'path' => (string) $file['path'],
                    'bytes' => $exists ? $this->size((string) $file['disk'], (string) $file['path']) : 0,
                    'exists' => $exists,
                    'sha256' => is_string($file['sha256'] ?? null) ? $file['sha256'] : null,
                    'expected_sha256' => null,
                ];
            }

            return $files;
        });
    }

    /**
     * A historic run's keys resolve only under its batch root, so its staging context is opened
     * for every read and delete, as {@see MediaProcessingLog::storedServiceTranscriptContents()}
     * does. The registry nests, so a caller already inside the context keeps it.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function inRunContext(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        return $context === null ? $callback() : $this->stagingContexts->within($context, $callback);
    }

    /**
     * @return list<string>
     */
    private function archivePaths(MediaProcessingLog $run): array
    {
        $paths = [];

        foreach ((array) data_get($run->processing_metadata?->toArray() ?? [], 'historic_import.sources', []) as $source) {
            if (is_array($source) && is_string($source['path'] ?? null)) {
                $paths[] = $source['path'];
            }
        }

        $archive = $run->recordedArchiveSourcePath();

        return array_values(array_unique($archive === null ? $paths : [...$paths, $archive]));
    }

    private function isSafeAbsolutePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '..')) {
            return false;
        }

        return str_starts_with($normalized, str_replace('\\', '/', storage_path()).'/')
            || str_starts_with($normalized, str_replace('\\', '/', sys_get_temp_dir()).'/');
    }

    /**
     * Whether the file is there, refusing to answer "no" for a disk that cannot be seen: an
     * unmounted or missing local root reads as empty, and that must never be recorded as deleted.
     */
    private function present(string $disk, string $path): bool
    {
        if ($this->exists($disk, $path)) {
            return true;
        }

        if ($disk === self::ABSOLUTE_DISK) {
            if (! is_dir(dirname($path)) && ! is_dir(storage_path()) && ! is_dir(sys_get_temp_dir())) {
                throw new RuntimeException("Cannot see [{$path}]: neither its directory nor storage is readable.");
            }

            return false;
        }

        $config = Storage::disk($disk)->getConfig();

        if (($config['driver'] ?? 'local') === 'local') {
            $root = $config['root'] ?? null;

            if (! is_string($root) || $root === '' || ! is_dir($root)) {
                throw new RuntimeException("Cannot see disk [{$disk}]: its root is missing or not mounted, so [{$path}] is unverified, not absent. Nothing was recorded as deleted.");
            }
        }

        return false;
    }

    /**
     * A resumed purge trusts only what it could itself have written: a path that is safe and a
     * disk that is configured, or an absolute path under storage or the system temp directory.
     *
     * @param  array<string, mixed>  $file
     */
    private function assertRecordedFileSafe(array $file): void
    {
        $disk = $file['disk'] ?? null;
        $path = $file['path'] ?? null;

        if (! is_string($disk) || ! is_string($path) || $path === '' || $disk === '') {
            throw new RuntimeException('The purge record lists a file with no disk or path; refusing to resume.');
        }

        $ok = $disk === self::ABSOLUTE_DISK
            ? $this->isSafeAbsolutePath($path)
            : (! Path::isUnsafe($path) && $this->diskIsConfigured($disk));

        if (! $ok) {
            throw new RuntimeException("The purge record lists [{$disk}:{$path}], which is not a path this command could have recorded; refusing to touch it.");
        }
    }

    private function diskIsConfigured(string $disk): bool
    {
        try {
            Storage::disk($disk);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function exists(string $disk, string $path): bool
    {
        return $disk === self::ABSOLUTE_DISK ? is_file($path) : Storage::disk($disk)->exists($path);
    }

    private function size(string $disk, string $path): int
    {
        return $disk === self::ABSOLUTE_DISK ? (int) filesize($path) : (int) Storage::disk($disk)->size($path);
    }

    private function sha256(string $disk, string $path): ?string
    {
        if ($disk === self::ABSOLUTE_DISK) {
            $hash = hash_file('sha256', $path);

            return $hash === false ? null : $hash;
        }

        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function remove(string $disk, string $path): void
    {
        if ($disk === self::ABSOLUTE_DISK) {
            if (! unlink($path)) {
                throw new RuntimeException('unlink failed');
            }

            return;
        }

        if (! Storage::disk($disk)->delete($path)) {
            throw new RuntimeException('the disk refused the delete');
        }
    }
}
