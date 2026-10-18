<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\DeadFeedExcision;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\ServiceArtifactDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only E20 replay over recorded cuts; never resolves a composition, probes or writes rows.
 * The optional graded-picture projection is retrospective evidence only: it is never banked
 * or consumed by production planning, which requires the source-window artifact instead.
 */
class ReplayDeadFeedExcisionCommand extends Command
{
    protected $signature = 'service:replay-dead-feed
        {--all : Replay every run with a talk dropout flag}
        {--run=* : Processing run ids to replay}
        {--graded-picture : Project existing graded-clip picture evidence into source time}
        {--json : Emit JSON}';

    protected $description = 'Preview E20 excisions using recorded cuts and banked evidence without writes or processing';

    public function handle(DeadFeedExcision $excision, HistoricStagingContextRegistry $contexts, DeadFeedInsideSection $audio): int
    {
        if (! $this->option('all') && $this->option('run') === []) {
            $this->error('Select --all or --run.');

            return self::FAILURE;
        }
        $query = MediaProcessingLog::query()->with('serviceSections')->orderBy('id');
        if ($this->option('all')) {
            $query->whereHas('serviceSections', static fn ($sections) => $sections->whereJsonContains('metadata->review_flags', ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT));
        } else {
            $query->whereIn('id', $this->option('run'));
        }
        $report = ['evidence' => $this->option('graded-picture') ? 'graded_clip_projection' : 'source_window_artifact', 'screened' => 0, 'missing_cut_or_evidence' => 0, 'unassessable_runs' => [], 'unassessable_reasons' => [], 'unchanged_runs' => [], 'affected' => []];
        foreach ($query->lazy() as $log) {
            $report['screened']++;
            $reason = 'recorded_cut_missing';
            $inspect = function () use ($log, $excision, $audio, &$reason): ?array {
                $quality = $log->processing_metadata->videoQuality ?? [];
                $old = $quality['graded_output']['provenance']['segments'] ?? $log->processing_metadata?->raw['sermon_extraction_plan']['segments'] ?? $log->processing_metadata?->raw['trim']['segments'] ?? null;
                if (! is_array($old) || $old === []) {
                    return null;
                }
                $spans = [];
                foreach ($old as $span) {
                    if (! is_array($span) || ! is_numeric($span['start_time'] ?? null) || ! is_numeric($span['end_time'] ?? null)
                        || $span['end_time'] <= $span['start_time']) {
                        return null;
                    }
                    $spans[] = ['start_time' => (float) $span['start_time'], 'end_time' => (float) $span['end_time']];
                }
                $reason = 'rms_missing';
                $path = $log->rms_log_path;
                $rms = $path === null ? null : Storage::disk(ServiceArtifactDisk::for($path))->get($path);
                if (! is_string($rms)) {
                    return null;
                }
                $hasInteriorDropout = array_any($audio->dropouts($rms), static fn (array $dropout): bool => array_any(
                    $spans, static fn (array $span): bool => min($dropout[1], $span['end_time']) - max($dropout[0], $span['start_time']) >= DeadFeedInsideSection::MINIMUM_SECONDS,
                ));
                if (! $hasInteriorDropout) {
                    return ['run' => $log->id, 'old_segments' => $spans, 'new_segments' => $spans, 'excisions' => []];
                }
                $evidence = null;
                if ($this->option('graded-picture')) {
                    $reason = 'graded_picture_missing';
                    if (! is_array($quality['metrics']['dead_intervals'] ?? null)
                        || ! is_array($quality['graded_output']['provenance']['segments'] ?? null)) {
                        return null;
                    }
                    $dead = [];
                    $offset = 0.0;
                    foreach ($spans as $span) {
                        $duration = $span['end_time'] - $span['start_time'];
                        foreach ($quality['metrics']['dead_intervals'] as $interval) {
                            if (! is_array($interval) || ! is_numeric($interval[0] ?? null) || ! is_numeric($interval[1] ?? null)) {
                                return null;
                            }
                            $start = max($offset, (float) $interval[0]);
                            $end = min($offset + $duration, (float) $interval[1]);
                            if ($end > $start) {
                                $dead[] = [$span['start_time'] + $start - $offset, $span['start_time'] + $end - $offset];
                            }
                        }
                        $offset += $duration;
                    }
                    $evidence = ['source' => $excision->sourceIdentity($log), 'rms_sha256' => hash('sha256', $rms),
                        'windows' => [['start' => 0.0, 'end' => $log->duration ?? max(array_column($spans, 'end_time')), 'dead_intervals' => $dead]],
                        'basis' => 'graded_clip_projection'];
                } elseif ($excision->identity($log) === null) {
                    $reason = 'source_picture_artifact_missing';

                    return null;
                }
                $plan = $excision->apply($log, array_values($log->serviceSections->all()), $spans, $evidence);

                return ['run' => $log->id, 'old_segments' => $spans, 'new_segments' => $plan['segments'], 'excisions' => $plan['dead_feed_excisions']];
            };
            $context = $log->historicStagingContext();
            $result = $context === null ? $inspect() : $contexts->within($context, $inspect);
            if ($result === null) {
                $report['missing_cut_or_evidence']++;
                $report['unassessable_runs'][] = $log->id;
                $report['unassessable_reasons'][$log->id] = $reason;
            } elseif ($result['excisions'] !== []) {
                $report['affected'][] = $result;
            } else {
                $report['unchanged_runs'][] = $log->id;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->line(sprintf('Read-only: %d screened; %d missing cut/evidence; %d affected (%s).', $report['screened'], $report['missing_cut_or_evidence'], count($report['affected']), $report['evidence']));
            foreach ($report['affected'] as $result) {
                $this->line('Run '.$result['run'].': '.json_encode($result['old_segments']).' -> '.json_encode($result['new_segments']));
            }
        }

        return self::SUCCESS;
    }
}
