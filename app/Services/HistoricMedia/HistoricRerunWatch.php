<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\RetranscribeForCorpusRerun;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SongVideo;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One look at a running corpus re-run against the full pass's machine-checkable stopping
 * conditions (plan VIDEO-BOUNDARY-CONSISTENCY §6.3.9).
 *
 * Read-only apart from a probe file on each historic disk. A stop is something that must
 * not go on happening while nobody watches: custody (anything public, a live hold gone,
 * extracted or published media gone), a crashed job, lost draws above the bar, speaker
 * identification, an unreachable disk or a full internal disk. Held and failed runs are
 * progress, not stops: a hold is containment working, and a crash shows as a failed job.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class HistoricRerunWatch
{
    public const LOST_DRAW_RATE = 0.10;

    /** The lost-draw rate is not read below this many ensembles, where one loss is already 10%+. */
    public const LOST_DRAW_MINIMUM_ENSEMBLES = 10;

    public const PROBED_DISKS = ['historic_staging', 'historic_temp', 'historic_quarantine'];

    private const LOST_DRAW_STATUSES = ['unavailable', 'interrupted'];

    /** Diff attention that stops the pass; anything else it lists is reported for review. */
    private const STOPPING_ATTENTION = [
        '/became public/',
        '/hold on .+ is gone/',
        '/lost its extracted media/',
        '/superseded by the re-run/',
        '/became published with nothing recording where it is shown/',
        '/could not be captured/',
    ];

    public function __construct(
        private readonly HistoricRerunState $state,
        private readonly HistoricRerunDiff $diff,
        private readonly HistoricProcessingThroughput $throughput,
    ) {}

    /**
     * The state a watch starts from: when it began, and the published files already missing
     * then, which are reported but cannot be what this pass lost.
     *
     * @return array{started_at: string, missing_at_start: list<string>}
     */
    public function begin(HistoricRerunSnapshot $snapshot): array
    {
        return [
            'started_at' => now()->toIso8601String(),
            'missing_at_start' => $this->missingPublishedFiles($snapshot),
        ];
    }

    /**
     * A stop the operator has acknowledged in `watch-state.json` (a lasting state they have looked at
     * and can redo, which would otherwise stop every look) is reported as a warning instead.
     *
     * Runs listed under `parked` in `watch-state.json` (their jobs pulled on purpose, such as a
     * hold list) count as parked rather than processing, so a pass that is waiting on them does
     * not look unfinished.
     *
     * @param  array{started_at: string, missing_at_start: list<string>, acknowledged?: list<string>, parked?: list<int>}  $state
     * @return array{stops: list<string>, warnings: list<string>, progress: array<string, mixed>}
     */
    public function check(HistoricRerunSnapshot $snapshot, array $state, float $minimumFreeGigabytes): array
    {
        $since = Carbon::parse($state['started_at']);
        $stops = [];
        $warnings = [];

        [$custodyStops, $custodyWarnings, $runs] = $this->custody($snapshot, $state['parked'] ?? []);
        $stops = [...$stops, ...$custodyStops];
        $warnings = [...$warnings, ...$custodyWarnings];

        $missing = $this->missingPublishedFiles($snapshot);
        foreach (array_diff($missing, $state['missing_at_start']) as $file) {
            $stops[] = "published file missing: {$file}";
        }

        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $since)->orderBy('id')->get(['queue', 'payload', 'exception']);
        foreach ($failedJobs as $job) {
            $payload = json_decode((string) $job->payload, true);
            $failure = sprintf('job failed on %s: %s — %s', $job->queue, $payload['displayName'] ?? 'unknown job', strtok((string) $job->exception, "\n"));
            $runIds = $this->runsNamedBy($payload);

            // Two watches run side by side (main and Tier B); each stops for its own runs only.
            // A job whose run cannot be read still stops it.
            if ($runIds !== [] && array_intersect($runIds, $snapshot->membership) === []) {
                $warnings[] = $failure.' (run '.implode(', ', $runIds).' is not in this pass)';
            } else {
                $stops[] = $failure;
            }
        }

        $draws = $this->draws($snapshot, $since);
        if ($draws['ensembles'] >= self::LOST_DRAW_MINIMUM_ENSEMBLES && $draws['losing'] / $draws['ensembles'] > self::LOST_DRAW_RATE) {
            $stops[] = sprintf('%d of %d ensembles lost a draw after the retry (bar %d%%)', $draws['losing'], $draws['ensembles'], (int) (self::LOST_DRAW_RATE * 100));
        }

        if ((bool) config('media-processing.speaker_identification.enabled', false)) {
            $stops[] = 'speaker identification is enabled';
        }

        $speakerQueue = (string) config('media-processing.queues.speaker_identification', 'speaker-identification');
        if (Queue::size($speakerQueue) > 0) {
            $stops[] = "speaker identification jobs are queued on {$speakerQueue}";
        }

        foreach (self::PROBED_DISKS as $disk) {
            $problem = $this->probe($disk);
            if ($problem !== null) {
                $stops[] = "{$disk}: {$problem}";
            }
        }

        $freeGigabytes = $this->freeGigabytes();
        if ($freeGigabytes !== null && $freeGigabytes < $minimumFreeGigabytes) {
            $stops[] = sprintf('internal disk has %.1f GB free (stop below %.1f)', $freeGigabytes, $minimumFreeGigabytes);
        }

        $acknowledged = $state['acknowledged'] ?? [];

        foreach (array_intersect($stops, $acknowledged) as $stop) {
            $warnings[] = 'acknowledged stop: '.$stop;
        }

        return [
            'stops' => array_values(array_diff($stops, $acknowledged)),
            'warnings' => $warnings,
            'progress' => [
                'checked_at' => now()->toIso8601String(),
                'members' => count($snapshot->membership),
                'runs' => $runs,
                'failed_jobs_since_start' => count($failedJobs),
                'draws' => $draws,
                'published_files_missing_at_start' => count($state['missing_at_start']),
                'internal_free_gb' => $freeGigabytes === null ? null : round($freeGigabytes, 1),
            ],
        ];
    }

    /**
     * Every queue a worker in this application reads, so a stop halts all of them.
     *
     * @return list<string>
     */
    public function queues(): array
    {
        // Horizon's supervisors name the queues the local workers read, `sermon-processing` among them.
        $supervised = collect((array) config('horizon.defaults', []))->pluck('queue')->flatten()->all();

        $queues = [
            ...array_values($this->throughput->configuredQueues()),
            ...array_values((array) config('media-processing.queues', [])),
            ...$supervised,
        ];

        return array_values(array_unique(array_filter($queues, 'is_string')));
    }

    /**
     * Pause every worker queue. A worker finishes the job it holds and takes no other until
     * `queue:continue`; nothing queued is lost.
     *
     * @return list<string>
     */
    public function pauseWorkers(): array
    {
        $connection = (string) config('queue.default');
        $paused = [];

        foreach ($this->queues() as $queue) {
            Queue::pause($connection, $queue);
            $paused[] = "{$connection}:{$queue}";
        }

        return $paused;
    }

    /**
     * The processing runs a failed job's payload names, its chained jobs included.
     *
     * @param  mixed  $payload  The decoded `failed_jobs.payload`
     * @return list<int>
     */
    private function runsNamedBy(mixed $payload): array
    {
        $command = is_array($payload) ? ($payload['data']['command'] ?? null) : null;

        if (! is_string($command) || preg_match_all('/"'.preg_quote(MediaProcessingLog::class, '/').'";s:2:"id";i:(\d+);/', $command, $matches) === 0) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * @param  list<int>  $parked
     * @return array{0: list<string>, 1: list<string>, 2: array<string, int>}
     */
    private function custody(HistoricRerunSnapshot $snapshot, array $parked): array
    {
        $stops = [];
        $warnings = [];
        $runs = ['transcribed_only' => 0, 'detected' => 0, 'cut' => 0, 'processing' => 0, 'parked' => 0, 'held_or_failed' => 0, 'not_started' => 0];

        foreach ($snapshot->membership as $runId) {
            $run = MediaProcessingLog::find($runId);
            $result = $this->diff->compare($snapshot->runs[$runId], $run instanceof MediaProcessingLog ? $this->state->capture($run) : null);

            foreach ($result['attention'] as $attention) {
                if ($this->stopsThePass($attention)) {
                    $stops[] = "run {$runId}: {$attention}";
                } else {
                    $warnings[] = "run {$runId}: {$attention}";
                }
            }

            if ($run instanceof MediaProcessingLog) {
                $stage = $this->stage($run, $snapshot);
                $runs[in_array($stage, ['processing', 'not_started'], true) && in_array($runId, $parked, true) ? 'parked' : $stage]++;
            }
        }

        return [$stops, $warnings, $runs];
    }

    private function stopsThePass(string $attention): bool
    {
        foreach (self::STOPPING_ATTENTION as $pattern) {
            if (preg_match($pattern, $attention) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Where a member stands in this pass, from its stamps on the snapshot's code and its status. */
    private function stage(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): string
    {
        $stamps = array_values(array_filter($run->corpusRerunStamps(), $snapshot->stampedOnItsCode(...)));

        if (in_array($run->status, [ProcessingStatus::Processing, ProcessingStatus::Pending], true) && $stamps !== []) {
            return 'processing';
        }

        if ($run->status === ProcessingStatus::Failed && $stamps !== []) {
            return 'held_or_failed';
        }

        $rounds = array_filter($stamps, static fn (array $stamp): bool => ! RetranscribeForCorpusRerun::transcribedOnly($stamp));

        if (array_filter($rounds, static fn (array $stamp): bool => ($stamp['media'] ?? null) === 'extracted') !== []) {
            return 'cut';
        }

        if ($rounds !== []) {
            return 'detected';
        }

        return $stamps === [] ? 'not_started' : 'transcribed_only';
    }

    /**
     * Ensembles drawn for members since the watch began, and how many lost a draw after the
     * one retry the runner makes.
     *
     * @return array{ensembles: int, losing: int}
     */
    private function draws(HistoricRerunSnapshot $snapshot, Carbon $since): array
    {
        $ensembles = 0;
        $losing = 0;

        foreach (MediaProcessingLog::query()->whereIn('id', $snapshot->membership)->get() as $run) {
            foreach ((array) data_get($run->processing_metadata?->toArray() ?? [], 'service_structure_ensemble', []) as $evidence) {
                if (! is_array($evidence) || ! isset($evidence['outcomes'], $evidence['started_at']) || Carbon::parse($evidence['started_at'])->lt($since)) {
                    continue;
                }

                $ensembles++;
                $statuses = array_column((array) $evidence['outcomes'], 'status');

                if (array_intersect($statuses, self::LOST_DRAW_STATUSES) !== []) {
                    $losing++;
                }
            }
        }

        return ['ensembles' => $ensembles, 'losing' => $losing];
    }

    /**
     * Published or quarantined sermon and song files of members that are not on their disk.
     *
     * @return list<string>
     */
    private function missingPublishedFiles(HistoricRerunSnapshot $snapshot): array
    {
        $runs = MediaProcessingLog::query()->whereIn('id', $snapshot->membership)->get(['id', 'processing_id', 'processing_metadata']);
        $batchRoots = $runs->mapWithKeys(static fn (MediaProcessingLog $run): array => [$run->id => $run->historicStagingContext()?->batchRoot]);
        $runIdsByProcessingId = $runs->pluck('id', 'processing_id');
        $missing = [];

        $sermons = Sermon::query()->whereIn('livestream_processing_id', $runs->pluck('processing_id')->filter())->whereNotNull('asset_disk')->get();
        foreach ($sermons as $sermon) {
            $batchRoot = $batchRoots[$runIdsByProcessingId[$sermon->livestream_processing_id] ?? null] ?? null;

            foreach (['video_file_path', 'audio_file_path'] as $field) {
                $missing[] = $this->missingFile((string) $sermon->asset_disk, $sermon->{$field}, $batchRoot, "sermon {$sermon->id} {$field}");
            }
        }

        $songVideos = SongVideo::query()
            ->with('serviceSection:id,media_processing_log_id')
            ->whereNotNull('asset_disk')
            ->whereHas('serviceSection', static fn ($query) => $query->whereIn('media_processing_log_id', $runs->pluck('id')))
            ->get();
        foreach ($songVideos as $video) {
            $batchRoot = $batchRoots[$video->serviceSection?->media_processing_log_id] ?? null;
            $missing[] = $this->missingFile((string) $video->asset_disk, $video->video_file_path, $batchRoot, "song video {$video->id}");
        }

        $missing = array_values(array_filter($missing));
        sort($missing);

        return $missing;
    }

    /**
     * The staging disk holds a run's files under its batch root, which a worker only sees while
     * scoped to that batch; read from the disk root, live files look missing.
     */
    private function missingFile(string $disk, mixed $path, ?string $batchRoot, string $label): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $located = $disk === config('media-processing.storage.historic_staging_disk') && $batchRoot !== null
            ? $batchRoot.'/'.$path
            : $path;

        try {
            return Storage::disk($disk)->exists($located) ? null : "{$label} ({$disk}:{$located})";
        } catch (Throwable) {
            // An unreachable disk is the probe's stop, not one missing file per row.
            return null;
        }
    }

    private function probe(string $disk): ?string
    {
        $path = '.rerun-watch-probe-'.getmypid();

        try {
            $storage = Storage::disk($disk);

            if (! $storage->put($path, 'probe') || $storage->get($path) !== 'probe') {
                return 'probe file could not be written and read back';
            }

            $storage->delete($path);

            return null;
        } catch (Throwable $exception) {
            return 'probe failed: '.$exception->getMessage();
        }
    }

    private function freeGigabytes(): ?float
    {
        try {
            $free = disk_free_space(Storage::disk(ServiceArtifactDisk::name())->path(''));
        } catch (Throwable) {
            return null;
        }

        return $free === false ? null : $free / 1_000_000_000;
    }
}
