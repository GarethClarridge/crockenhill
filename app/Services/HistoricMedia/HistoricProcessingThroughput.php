<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use RuntimeException;

/**
 * Routes historic work to independently calibrated resource stages. The
 * normal weekly queues retain their existing scheduling; only an approved
 * historic manifest is routed through these dedicated queues.
 */
final class HistoricProcessingThroughput
{
    /** @var array<string, list<class-string>> */
    private const JOB_STAGES = [
        'ffmpeg' => [
            AnalyzeSegments::class,
            AssessSermonVideoQuality::class,
            EnhanceAudio::class,
            ExtractAudioFromVideo::class,
            ExtractSermon::class,
            GenerateRmsLog::class,
            GenerateThumbnail::class,
            // Extracts per-section audio/video candidates, so it binds on CPU
            // like the rest of this stage rather than on the orchestration pool.
            PrepareSectionPublicationCandidates::class,
        ],
        'whisper' => [
            CreateSermonTranscriptFromService::class,
            TranscribeAudio::class,
            TranscribeFullService::class,
        ],
        'llm' => [
            DetectServiceStructure::class,
            MatchSongsFromTranscript::class,
            MergeSongContinuations::class,
            ProcessTranscriptWithAI::class,
            ProjectLivestreamServiceStructure::class,
        ],
    ];

    public function queueFor(object $job): string
    {
        return $this->queueForClass($job::class);
    }

    /**
     * For jobs dispatched from inside another job, where only the class name is
     * available. Those dispatches would otherwise keep their default queue and
     * be served by the weekly workers.
     *
     * @param  class-string  $jobClass
     */
    public function queueForClass(string $jobClass): string
    {
        foreach (self::JOB_STAGES as $stage => $jobs) {
            if (in_array($jobClass, $jobs, true)) {
                return $this->stage($stage)['queue'];
            }
        }

        return $this->stage('orchestration')['queue'];
    }

    public function fanOutQueue(): string
    {
        return $this->stage('ffmpeg')['queue'];
    }

    /**
     * The calibrated queue for a job about to be dispatched from inside another
     * job, or null when no historic batch is running. Returning null rather than
     * a fallback lets each call site keep its existing routing untouched on the
     * weekly path — this must not change how a Sunday livestream is scheduled.
     *
     * The registry is resolved late rather than injected: it is scoped per queue
     * job, so a copy captured by a longer-lived collaborator would go stale.
     *
     * @param  class-string  $jobClass
     */
    public function historicQueueFor(string $jobClass): ?string
    {
        if (! app(HistoricStagingContextRegistry::class)->isActive()) {
            return null;
        }

        return $this->queueForClass($jobClass);
    }

    /**
     * This is part of the historic processing fingerprint. The configured width
     * is reproducibility data for a calibrated bulk pass, even though it does
     * not itself alter bytes emitted by an individual job.
     *
     * Width buys concurrency *across* services, never within one: a service's
     * pipeline is a single serial `Bus::chain`, so routing its jobs to different
     * queues changes which pool executes each step, not how many run at once.
     * The number of services in flight is set by the importer's `--parallel`,
     * and these widths cap how many of them may sit in a given stage together.
     *
     * @return array<string, array{routing_fingerprint:string,worker_width:int}>
     */
    public function fingerprint(): array
    {
        $stages = [];

        foreach (array_keys(self::JOB_STAGES + ['orchestration' => []]) as $stage) {
            $configuration = $this->stage($stage);
            $stages[$stage] = [
                'routing_fingerprint' => hash('sha256', $configuration['queue']),
                'worker_width' => $configuration['workers'],
            ];
        }

        ksort($stages);

        return $stages;
    }

    /** @return array{queue:string,workers:int} */
    private function stage(string $stage): array
    {
        $configuration = config("media-processing.historic_import.stages.{$stage}");
        $queue = is_array($configuration) ? $configuration['queue'] ?? null : null;
        $workers = is_array($configuration) ? $configuration['workers'] ?? null : null;

        if (! is_string($queue) || trim($queue) === '' || ! is_int($workers) || $workers < 1) {
            throw new RuntimeException("Historic processing stage '{$stage}' has an invalid queue or worker width.");
        }

        return ['queue' => $queue, 'workers' => $workers];
    }
}
