<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\ClassifyServiceAudio;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtendSongsOverOwnLyrics;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\RecordCorpusRerunTranscription;
use App\Jobs\RecordDeferredCorpusRerunMedia;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;

/**
 * ProcessingPipelineBuilder - Unified job chains for all media processing types
 *
 * The livestream and auto-trim pipelines use the shared
 * full-service transcription and structure-detection seam.
 */
class ProcessingPipelineBuilder
{
    /**
     * Build job pipeline for audio processing
     *
     * @return array<int, object>
     */
    public function buildAudioPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for direct video processing
     *
     * @return array<int, object>
     */
    public function buildDirectVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for sermon video uploads that should be auto-trimmed
     * before entering the standard sermon-processing flow.
     *
     * @return array<int, object>
     */
    public function buildAutoTrimVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new TranscribeFullService($log),
            new ClassifyServiceAudio($log),
            new DetectServiceStructure($log),
            new ExtractSermon($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Jobs to run in parallel at the start of the livestream pipeline.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamParallelJobs(MediaProcessingLog $log, bool $resuming = false): array
    {
        return [new GenerateRmsLog($log, $resuming)];
    }

    /**
     * Sequential jobs that run after the parallel phase completes.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamChainJobs(MediaProcessingLog $log, bool $resuming = false): array
    {
        return [
            new AnalyzeSegments($log),
            new TranscribeFullService($log, $resuming),
            new ClassifyServiceAudio($log, $resuming),
            new DetectServiceStructure($log),
            // Provisional: song matching has not run, so this pass can only anchor
            // on automated title text and its merge findings are working guesses.
            // It has to run here because SongContinuationMerger reads
            // church_service_item_id to decide what it may absorb.
            new ProjectLivestreamServiceStructure($log, refining: false),
            new MatchSongsFromTranscript($log),
            new MergeSongContinuations($log),
            // Needs both songs' identities settled, and runs before any clip is cut.
            new ExtendSongsOverOwnLyrics($log),
            // Refining: catalogue songs are resolved and continuations have
            // settled, so this pass can anchor on song identity — and it is the
            // one that reports on the quality of the merge.
            new ProjectLivestreamServiceStructure($log, refining: true),
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new EnhanceAudio($log),
            new IdentifySpeaker($log),
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            ...$this->historicSermonVideoStorageGate($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * The corpus re-run's detection round (plan §4.0): the livestream chain up to the refining
     * projection, with no media cut.
     *
     * A detection round is re-run after every detector fix, and nothing it judges needs media,
     * so cutting clips each round would only produce work the next round throws away.
     * {@see RecordDeferredCorpusRerunMedia} records what would be cut instead, and the
     * sermonless tail completes the run. The media is cut once, on the frozen commit, by
     * re-extraction ({@see ProcessingRunOrchestrator::reExtract()}).
     *
     * Sliced from the full chain rather than listed, so the two can never disagree on the jobs
     * a re-detection's offset counts over.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamDetectionOnlyChainJobs(MediaProcessingLog $log, bool $resuming = false): array
    {
        $jobs = $this->buildLivestreamChainJobs($log, $resuming);
        $firstMediaJob = array_search(ExtractSermon::class, array_map(static fn (object $job): string => $job::class, $jobs), true);

        if (! is_int($firstMediaJob)) {
            throw new \LogicException('The livestream chain has no extraction step to stop before.');
        }

        return [
            ...array_slice($jobs, 0, $firstMediaJob),
            new RecordDeferredCorpusRerunMedia($log),
            ...$this->buildSermonlessServiceChainJobs($log),
        ];
    }

    /**
     * The corpus re-run's Tier A round (plan §4.0; operator, 2026-10-01): transcribe afresh and
     * stop before detection.
     *
     * The run then joins a Tier B round on whichever commit is frozen, so a rule commit never
     * throws away a Tier A detection. The recording is unchanged, so its RMS log
     * ({@see self::buildLivestreamTranscriptionOnlyParallelJobs()}) and audio timeline are reused:
     * measuring them again would only occupy the ffmpeg worker the cuts need.
     * {@see RecordCorpusRerunTranscription} records the text written, and the sermonless tail
     * completes the run.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamTranscriptionOnlyChainJobs(MediaProcessingLog $log): array
    {
        return [
            new AnalyzeSegments($log),
            new TranscribeFullService($log),
            new ClassifyServiceAudio($log, mayReuseRecordedTimeline: true),
            new RecordCorpusRerunTranscription($log),
            ...$this->buildSermonlessServiceChainJobs($log),
        ];
    }

    /**
     * The parallel phase of {@see self::buildLivestreamTranscriptionOnlyChainJobs()}.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamTranscriptionOnlyParallelJobs(MediaProcessingLog $log): array
    {
        return [new GenerateRmsLog($log, mayReuseRecordedRmsLog: true)];
    }

    /**
     * The job classes of the current mode's livestream chain, in order.
     *
     * ProcessingPhaseRegistry resolves its retry offsets from this list, so a
     * mode change can never leave the retry table pointing at the wrong job.
     *
     * @return non-empty-list<class-string>
     */
    public function livestreamChainJobClasses(): array
    {
        return array_map(
            static fn (object $job): string => $job::class,
            $this->buildLivestreamChainJobs(new MediaProcessingLog)
        );
    }

    /**
     * Resume chain for livestream runs after manual sermon segment confirmation.
     * Starts at ExtractSermon, skipping all upstream segmentation and analysis steps.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamPostReviewChainJobs(MediaProcessingLog $log): array
    {
        return [
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new EnhanceAudio($log),
            new IdentifySpeaker($log),
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            ...$this->historicSermonVideoStorageGate($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Resume chain for auto-trimmed video runs after manual sermon segment confirmation.
     *
     * @return non-empty-list<object>
     */
    public function buildAutoTrimVideoPostReviewChainJobs(MediaProcessingLog $log): array
    {
        return [
            new ExtractSermon($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * The tail a run takes when its service genuinely held no sermon.
     *
     * Everything between extraction and notification is sermon-shaped — there is
     * no media to enhance, no sermon row to create, nothing to transcribe,
     * analyse or thumbnail — so those stages are not skipped so much as
     * inapplicable. What remains is the custody transition, and it remains in
     * full: a sermon-less service still has song sections whose videos live in
     * staging, and {@see PromoteHistoricAssets} is what moves them and records
     * the byte accounting the pass-level measures are summed from. Cleanup then
     * marks the run completed, which is the point — the run produced a real
     * service, just not a sermon (D1, 2026-09-03).
     *
     * @return non-empty-list<object>
     */
    public function buildSermonlessServiceChainJobs(MediaProcessingLog $log): array
    {
        return [
            new PromoteHistoricAssets($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Keep the detached video copy out of the live chain while making every
     * historic media-output step wait for its operation-owned completion row.
     *
     * @return list<object>
     */
    private function historicSermonVideoStorageGate(MediaProcessingLog $log): array
    {
        if ($log->historic_import_operation_id === null) {
            return [];
        }

        return [new AwaitHistoricSermonVideoStorage($log)];
    }
}
