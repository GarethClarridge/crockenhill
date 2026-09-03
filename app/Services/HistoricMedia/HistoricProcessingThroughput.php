<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStep;
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
    /** @var list<string> */
    private const STAGES = ['ffmpeg', 'whisper', 'llm', 'orchestration'];

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

    /**
     * Every step the historic pipeline can expose has an explicit owner. The
     * report deliberately returns 'unknown' for anything not listed here so a
     * new step cannot silently inflate orchestration throughput.
     *
     * @var array<string, string>
     */
    private const STEP_STAGES = [
        'audio_processing_initiated' => 'orchestration',
        'video_processing_initiated' => 'orchestration',
        'initiated_from_livestream' => 'orchestration',
        'validating' => 'ffmpeg',
        'audio_validation_complete' => 'ffmpeg',
        'video_validation_complete' => 'ffmpeg',
        'rms_generation' => 'ffmpeg',
        'segmentation' => 'ffmpeg',
        'segmenting' => 'ffmpeg',
        'analyzing_segments' => 'ffmpeg',
        'extracting_audio' => 'ffmpeg',
        'audio_extraction_complete' => 'ffmpeg',
        'audio_enhancement' => 'ffmpeg',
        'audio_enhancement_complete' => 'ffmpeg',
        'audio_enhancement_skipped' => 'ffmpeg',
        'extraction' => 'ffmpeg',
        'extract_sermon' => 'ffmpeg',
        'extracting_sermon' => 'ffmpeg',
        'extraction_complete' => 'ffmpeg',
        'assessing_video_quality' => 'ffmpeg',
        'generating_thumbnail' => 'ffmpeg',
        'prepare_section_publication_candidates' => 'ffmpeg',
        'preparing_section_publication_candidates' => 'ffmpeg',
        'transcribe_full_service' => 'whisper',
        'transcribing_audio' => 'whisper',
        'transcribing' => 'whisper',
        'transcription_completed' => 'whisper',
        'transcription' => 'whisper',
        'detect_service_structure' => 'llm',
        'project_livestream_service_structure' => 'llm',
        'match_songs_from_transcript' => 'llm',
        'merging_song_continuations' => 'llm',
        'analyzing_transcript' => 'llm',
        'ai_analysis_completed' => 'llm',
        'ai_analysis_fallback' => 'llm',
        'analyzing' => 'llm',
        'sermon_creation' => 'orchestration',
        'creating_sermon' => 'orchestration',
        'creating_sermon_transcript' => 'whisper',
        'creating_sermon_record' => 'orchestration',
        'sermon_record_created' => 'orchestration',
        'creating' => 'orchestration',
        'identifying_speaker' => 'orchestration',
        'sermon_submitted' => 'orchestration',
        'promoting_historic_assets' => 'orchestration',
        'cleanup' => 'orchestration',
        'sending_notification' => 'orchestration',
        'notification_sent' => 'orchestration',
        'notification_skipped' => 'orchestration',
        'notification_failed' => 'orchestration',
        'notification_failed_permanently' => 'orchestration',
        'completed' => 'orchestration',
        'completed_with_basic_data' => 'orchestration',
        'job_chain_failed' => 'orchestration',
        'creating_sermon_record_failed' => 'orchestration',
        'transcribing_audio_failed' => 'whisper',
        'analyzing_transcript_failed' => 'llm',
        'manual_review_required' => 'orchestration',
        'manual_review_confirmed' => 'orchestration',
        'cancelled' => 'orchestration',
        'preparing' => 'orchestration',
        'restarting_from_beginning' => 'orchestration',
        'storage' => 'orchestration',
        'analysis' => 'llm',
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
     * Worker widths and queue routing are execution evidence, not byte-affecting
     * identity. They are retained with each historic run so a later report can
     * explain what actually ran.
     *
     * Width buys concurrency across services, never within one: a service's
     * pipeline is a single serial Bus::chain, so routing its jobs to different
     * queues changes which pool executes each step, not the bytes it emits.
     *
     * @return array<string, array{routing_fingerprint:string,worker_width:int}>
     */
    public function executionProfile(): array
    {
        $stages = [];

        foreach (self::STAGES as $stage) {
            $configuration = $this->stage($stage);
            $stages[$stage] = [
                'routing_fingerprint' => hash('sha256', $configuration['queue']),
                'worker_width' => $configuration['workers'],
            ];
        }

        ksort($stages);

        return $stages;
    }

    /**
     * Kept as a compatibility alias for callers that only need the old
     * execution-evidence shape. New durable fingerprints must not call this.
     *
     * @return array<string, array{routing_fingerprint:string,worker_width:int}>
     */
    public function fingerprint(): array
    {
        return $this->executionProfile();
    }

    /** @return array<string, int> */
    public function configuredWidths(): array
    {
        $widths = [];

        foreach (self::STAGES as $stage) {
            $widths[$stage] = $this->stage($stage)['workers'];
        }

        return $widths;
    }

    /**
     * Every queue the historic pipeline dispatches onto, keyed by stage.
     *
     * Distinct queue names are returned once each, so a configuration that
     * points two stages at one queue cannot double-count its depth.
     *
     * @return array<string, string>
     */
    public function configuredQueues(): array
    {
        $queues = [];

        foreach (self::STAGES as $stage) {
            $queues[$stage] = $this->stage($stage)['queue'];
        }

        return $queues;
    }

    /**
     * Resolve a persisted processing-step name to the resource stage that owns
     * it. No fallback is permitted: an unrecognised step is evidence that the
     * map needs updating, not orchestration work by default.
     */
    public function stageForStep(string $step): string
    {
        $canonicalStep = ProcessingStep::canonicalize($step) ?? $step;

        return self::STEP_STAGES[$canonicalStep] ?? 'unknown';
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
