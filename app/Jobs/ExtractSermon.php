<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FlagSermonAudioLengthMismatch;
use App\Data\LivestreamSegment;
use App\Data\ServiceSectionMetadata;
use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Enums\LivestreamSegmentClassification;
use App\Mail\ManualReviewRequired;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Processing\SermonMetadataIntegrationService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ExtractSermon extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    /**
     * How far a fresh probe may sit from the recorded stored duration before the
     * cut counts as changed. See {@see self::authoriseReplacementIfCutChanged()}.
     */
    private const StoredVideoToleranceSeconds = 0.5;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VideoExtractionService $videoExtractor,
        VideoStorageService $storageService,
        StorageAdapterHelper $storageHelper,
        SermonExtractionPlanResolver $planResolver,
        SermonCandidateConfidenceService $sermonConfidenceService,
        ExtractedMediaDurationProbe $durationProbe,
    ): void {
        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;
            $this->startProcessingJob($this->processingLog, $this->job ?? null, $this->attempts());

            if ($this->processingLog->isCancelled()) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, 'Processing cancelled');

                Log::info('ExtractSermon job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            $this->logStepStart(ChurchServiceProcessingTimeline::EXTRACT_SERMON);

            // Update status to show sermon extraction is starting
            $this->markProcessingRunAsProcessing($this->processingLog, 'extraction');

            if ($this->concludeWithoutSermon()) {
                return;
            }

            $extractionPlan = $planResolver->resolve($this->processingLog);
            $extractionPlan = $this->guardAutoExtractionPolicy(
                $extractionPlan,
                $sermonConfidenceService
            );

            if ($extractionPlan === null) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, 'Awaiting manual sermon review');

                return;
            }

            $firstSegment = $extractionPlan['segments'][0] ?? null;
            if (! is_array($firstSegment)) {
                throw new \Exception('Invalid sermon extraction plan: no extraction segments were resolved');
            }

            $plannedDuration = $this->totalPlannedDuration($extractionPlan['segments']);
            $this->recordExtractionPlanAudit($extractionPlan);

            Log::info('Starting sermon extraction', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start_time' => $firstSegment['start_time'],
                'sermon_end_time' => $firstSegment['end_time'],
                'bounds_source' => $extractionPlan['source'],
                'mode' => $extractionPlan['mode'],
                'segment_count' => count($extractionPlan['segments']),
            ]);

            $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
            $isS3TempDisk = $this->isS3Disk($tempDisk);
            $sourceFilePath = $this->requireSourceFilePath();

            if ($isS3TempDisk) {
                if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                    throw new \Exception('Original video file not found on S3: '.$sourceFilePath);
                }

                $videoPath = $storageHelper->downloadToTemp(
                    $sourceFilePath,
                    $tempDisk,
                    'local',
                    'temp/extraction'
                );
            } else {
                $videoPath = Storage::disk($tempDisk)->path($sourceFilePath);

                // Wait for file to be available (handles async upload/storage delays)
                $maxAttempts = 5;
                $attempt = 0;
                while (! file_exists($videoPath) && $attempt < $maxAttempts) {
                    $attempt++;
                    Log::warning('Video file not yet available, waiting...', [
                        'processing_id' => $this->processingLog->processing_id,
                        'attempt' => $attempt,
                        'expected_path' => $videoPath,
                    ]);
                    sleep(2);
                }

                if (! file_exists($videoPath)) {
                    throw new \Exception('Original video file not found after waiting: '.$videoPath);
                }
            }

            try {
                if ($extractionPlan['mode'] === 'concat_spans') {
                    $sermonVideoPath = $videoExtractor->extractConcatenatedSegmentAsFile(
                        $videoPath,
                        $extractionPlan['segments'],
                        $this->processingLog->processing_id.'_sermon.mp4'
                    );
                } else {
                    $sermonVideoPath = $videoExtractor->extractSegmentAsFile(
                        $videoPath,
                        $this->createSermonSegment(
                            (float) $firstSegment['start_time'],
                            (float) $firstSegment['end_time']
                        ),
                        $this->processingLog->processing_id.'_sermon.mp4'
                    );
                }

                $sermonVideoAbsolutePath = Storage::disk($tempDisk)->path($sermonVideoPath);
                $observedDuration = $durationProbe->durationOf($sermonVideoAbsolutePath);

                /**
                 * The MP3 is the final video's whole audio track, in both modes.
                 * It used to be cut again: from the stream-copied join to the
                 * planned length for a concat plan, and independently from the
                 * source for a single span. The copied video runs past the plan
                 * from its keyframe, so either cut could drop the closing words,
                 * and 12 historic sermons lost them.
                 */
                $audioExtractionResult = $videoExtractor->extractOptimizedAudio(
                    $sermonVideoAbsolutePath,
                    $this->createSermonSegment(0.0, $observedDuration),
                    $this->processingLog->processing_id.'_sermon.mp3'
                );

                $sermonAudioPath = $audioExtractionResult['audio_path'];
            } finally {
                // Clean up temporary S3 download file if we created one
                if ($isS3TempDisk) {
                    $storageHelper->cleanupTempFile($videoPath);
                }
            }

            // DEFENSIVE: Verify audio file actually exists before storing path in database
            $audioFullPath = $audioExtractionResult['full_path'];
            $audioFileExists = $this->verifyAudioFileExists($storageService, $sermonAudioPath, $audioFullPath);

            if (! $audioFileExists) {
                throw new \Exception("Audio extraction claimed success but file does not exist: {$audioFullPath}");
            }

            Log::info('Audio file existence verified before database update', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $sermonAudioPath,
                'full_path' => $audioFullPath,
                'file_exists' => $audioFileExists,
                'verification_method' => $this->isS3Path($audioFullPath) ? 's3_storage' : 'local_filesystem',
            ]);

            $audioDuration = $this->measuredAudioDuration($durationProbe, $audioFullPath);
            (new FlagSermonAudioLengthMismatch)($this->processingLog, $observedDuration, $audioDuration);

            /**
             * Read before the update: the trim block about to be overwritten is
             * the only record of what the stored video was cut from on runs that
             * predate the stored_video signature.
             */
            $supersededVideoDuration = $this->processingLog->storedSermonVideoDuration();
            $supersededVideoSpans = $this->processingLog->storedSermonVideoSpans();

            $this->processingLog->update([
                'video_file_path' => $sermonVideoPath,
                'audio_file_path' => $sermonAudioPath,
                'current_step' => 'extraction_complete',
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata?->toArray() ?? [],
                    [
                        'extracted_segment_path' => $sermonVideoPath,
                        'extracted_audio_path' => $sermonAudioPath,
                        'trim' => [
                            'original_duration' => $this->processingLog->duration,
                            'final_duration' => $plannedDuration,
                            'observed_duration' => $observedDuration,
                            'audio_duration' => $audioDuration,
                            'trim_start' => (float) $extractionPlan['segments'][0]['start_time'],
                            'trim_end' => (float) $extractionPlan['segments'][count($extractionPlan['segments']) - 1]['end_time'],
                            'segments' => $extractionPlan['segments'],
                            'source' => $extractionPlan['source'],
                            'strategy' => $extractionPlan['metadata']['strategy'] ?? null,
                            'mode' => $extractionPlan['mode'],
                        ],
                        'audio_compression' => [
                            'original_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                            'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                            'compression_applied' => $audioExtractionResult['compression_applied'],
                            'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                            'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                        ],
                    ]
                ),
            ]);

            $this->authoriseReplacementIfCutChanged(
                $supersededVideoDuration,
                $observedDuration,
                $supersededVideoSpans,
                $extractionPlan['segments'],
            );

            if (! $audioExtractionResult['valid_for_transcription']) {
                Log::warning('Audio file still too large after compression', [
                    'processing_id' => $this->processingLog->processing_id,
                    'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                    'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                ]);
            }

            Log::info('Sermon extraction completed', [
                'processing_id' => $this->processingLog->processing_id,
                'video_path' => $sermonVideoPath,
                'audio_path' => $sermonAudioPath,
                'observed_duration' => $observedDuration,
                'audio_full_path' => $audioExtractionResult['full_path'],
                'compression_applied' => $audioExtractionResult['compression_applied'],
                'original_audio_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                'final_audio_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                'compression_ratio' => $audioExtractionResult['compression_ratio'],
                'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                'file_exists_check' => $audioFileExists,
            ]);

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::EXTRACT_SERMON,
                sprintf('Extracted sermon media using %s plan', $extractionPlan['mode'])
            );

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepFailed(ChurchServiceProcessingTimeline::EXTRACT_SERMON, $e->getMessage());

            Log::error('Sermon extraction failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->markProcessingRunAsFailed($this->processingLog, 'Sermon extraction failed: '.$e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    /**
     * Stop before extraction when the accepted structure says this service held
     * no sermon, handing the run to the custody tail that completes it.
     *
     * Absence is a content fact and the projection is the instrument that reads
     * content; the confidence service reads RMS speech duration, which cannot
     * tell "no sermon happened" from "one long block of speech". Left to it, the
     * 2024-02-11 mission-presentation evening failed on
     * `candidate_exceeds_maximum_duration` — a right answer reached through the
     * wrong instrument, and reported as a defect (D1, 2026-09-03).
     *
     * The structure has already passed the deterministic gate by the time this
     * runs, and {@see ServiceStructure::fromSections()} drops any
     * assertion that sits beside a detected sermon section, so an assertion
     * reaching here is one nothing in the run contradicts.
     */
    /**
     * A cut that no longer matches the stored video is a replacement, whoever asked for it.
     *
     * The re-cut path already exists and works: {@see MediaProcessingLog::isReExtraction()}
     * carries a run past the "already completed" store guard, past
     * {@see SermonMetadataIntegrationService::organizeVideoFile()}'s
     * overwrite refusal, and on into promotion's own guard. What was missing is
     * that only an operator calling `reExtract()` or structure re-detection ever
     * raised the flag. An ordinary retry that resumes at extraction produces a
     * new cut just as decisively, and produced 44 sermons whose stored video is
     * not the video their own run says it extracted.
     *
     * So extraction raises it itself, on two pieces of evidence: the source spans
     * it just cut against the spans the stored video was cut from, and the
     * duration of the video it just produced against the duration of the video
     * already on disk. Either differing is a new cut. Duration alone misses a
     * span that moved without changing length, which is exactly what correcting
     * a boundary written one minute late produces. A null superseded value means
     * that evidence could not be established, and unestablished evidence
     * authorises nothing.
     *
     * The tolerance absorbs container rounding — a stored duration and a fresh
     * probe of the same cut differ in the third decimal — without absorbing any
     * real span change; the smallest genuine divergence measured across the
     * corpus was 1.0 s.
     *
     * @param  list<array{start: float, end: float}>|null  $supersededSpans
     * @param  array<int, array{start_time: float, end_time: float}>  $plannedSegments
     */
    private function authoriseReplacementIfCutChanged(
        ?float $supersededDuration,
        ?float $observedDuration,
        ?array $supersededSpans,
        array $plannedSegments,
    ): void {
        $spansChanged = $supersededSpans !== null && $this->spansDiffer($supersededSpans, $plannedSegments);
        $durationChanged = $supersededDuration !== null
            && $observedDuration !== null
            && abs($observedDuration - $supersededDuration) > self::StoredVideoToleranceSeconds;

        if (! $spansChanged && ! $durationChanged) {
            return;
        }

        $this->processingLog->markAsReExtraction();

        Log::info('Extraction superseded the stored sermon video; authorising its replacement', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->processingLog->sermon_id,
            'stored_duration' => $supersededDuration,
            'extracted_duration' => $observedDuration,
            'stored_spans' => $supersededSpans,
            'extracted_segments' => $plannedSegments,
        ]);
    }

    /**
     * @param  list<array{start: float, end: float}>  $supersededSpans
     * @param  array<int, array{start_time: float, end_time: float}>  $plannedSegments
     */
    private function spansDiffer(array $supersededSpans, array $plannedSegments): bool
    {
        $plannedSegments = array_values($plannedSegments);

        if (count($supersededSpans) !== count($plannedSegments)) {
            return true;
        }

        foreach ($supersededSpans as $index => $span) {
            if (abs($span['start'] - (float) $plannedSegments[$index]['start_time']) > self::StoredVideoToleranceSeconds
                || abs($span['end'] - (float) $plannedSegments[$index]['end_time']) > self::StoredVideoToleranceSeconds) {
                return true;
            }
        }

        return false;
    }

    private function concludeWithoutSermon(): bool
    {
        $absence = $this->processingLog->assertedSermonAbsence();

        if (! $absence instanceof ServiceSermonAbsence) {
            return false;
        }

        $this->logStepSkipped(
            ChurchServiceProcessingTimeline::EXTRACT_SERMON,
            'The service held no sermon: '.$absence->explanation,
        );

        Log::info('Sermon extraction skipped: the detected structure asserts this service held no sermon', [
            'processing_id' => $this->processingLog->processing_id,
            'occasion' => $absence->occasion?->value,
            'explanation' => $absence->explanation,
        ]);

        // The remaining chained jobs are all sermon-shaped; the orchestrator
        // dispatches the custody tail that still applies in their place.
        $this->chained = [];

        app(ProcessingRunOrchestrator::class)->concludeWithoutSermon($this->processingLog);

        return true;
    }

    private function createSermonSegment(float $startTime, float $endTime): LivestreamSegment
    {
        return new LivestreamSegment(
            startTime: $startTime,
            endTime: $endTime,
            duration: $endTime - $startTime,
            classification: LivestreamSegmentClassification::Speech->value,
            avgRms: 0.0, // Not needed for extraction
            peakRms: 0.0, // Not needed for extraction
            isSermonCandidate: true,
            segmentOrder: 0
        );
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::EXTRACT_SERMON,
            'Sermon extraction failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        $this->markProcessingRunAsFailed(
            $this->processingLog,
            'Sermon extraction failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );
    }

    /**
     * Verify audio file exists using appropriate method for storage type
     */
    private function verifyAudioFileExists(VideoStorageService $storageService, string $audioPath, string $fullPath): bool
    {
        // For S3 URLs, use Storage disk to verify existence
        if ($this->isS3Path($fullPath)) {
            $diskName = config('media-processing.storage.sermon_disk', 'public');

            return Storage::disk($diskName)->exists($audioPath);
        }

        // For local paths, use file_exists
        return file_exists($fullPath);
    }

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \Exception('No source video path found in processing log');
        }

        return $sourceFilePath;
    }

    /**
     * @param  array<int, array{start_time: float, end_time: float}>  $segments
     */
    private function totalPlannedDuration(array $segments): float
    {
        $duration = 0.0;

        foreach ($segments as $segment) {
            $duration += max(0.0, (float) $segment['end_time'] - (float) $segment['start_time']);
        }

        if ($duration <= 0.0) {
            throw new \Exception('Invalid extraction plan duration');
        }

        return $duration;
    }

    /**
     * @param  array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }  $extractionPlan
     * @return array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }|null
     */
    private function guardAutoExtractionPolicy(
        array $extractionPlan,
        SermonCandidateConfidenceService $sermonConfidenceService
    ): ?array {
        $this->recordSermonBoundaryEvidence($extractionPlan);

        if ($extractionPlan['source'] !== 'processing_log') {
            return $extractionPlan;
        }

        if (($extractionPlan['metadata']['reason'] ?? null) === 'sermon_section_content_held') {
            $this->parkForHeldSermon($extractionPlan['metadata']['held_sermon_section_ids'] ?? []);

            return null;
        }

        $evaluation = $sermonConfidenceService->evaluateForProcessingLog($this->processingLog);
        $speechSegments = $evaluation['speech_segments'];

        if ($speechSegments === []) {
            return $extractionPlan;
        }

        if (! $evaluation['is_clear']) {
            $reasonCode = $evaluation['reason'];
            $reasonMessage = $this->manualReviewReason($reasonCode);
            $this->markProcessingRunForManualReview($this->processingLog, $reasonCode, $reasonMessage, $speechSegments);
            $this->processingLog->refresh();
            $this->notifyManualReviewRequired($reasonMessage, $speechSegments);

            // Stop the remaining chained jobs; extraction is intentionally deferred.
            $this->chained = [];

            Log::warning('Sermon extraction halted for manual review', [
                'processing_id' => $this->processingLog->processing_id,
                'reason' => $evaluation['reason'],
                'speech_segment_count' => count($speechSegments),
            ]);

            return null;
        }

        $candidate = $evaluation['candidate'];

        if (! $candidate instanceof \App\Models\LivestreamSegment) {
            return $extractionPlan;
        }

        return [
            'mode' => 'single_span',
            'source' => 'processing_log',
            'segments' => [[
                'start_time' => (float) $candidate->start_time,
                'end_time' => (float) $candidate->end_time,
            ]],
            'metadata' => array_merge($extractionPlan['metadata'], [
                'strategy' => 'dominant_speech_segment',
                'sermon_segment_id' => $candidate->id,
                'next_longest_duration' => $evaluation['next_longest_duration'],
            ]),
        ];
    }

    /**
     * Record the resolved sermon-boundary evidence on the sermon section, and
     * mark it for review when that evidence names a material risk.
     *
     * The inclusive span is still extracted. M5's asymmetric policy is to
     * preserve an ambiguous conclusion and let a person judge it afterwards, so
     * a boundary risk routes the *section* to a reviewer rather than stopping
     * the run: halting here would abandon the sermon, the songs, the analysis
     * and the boundary evidence itself, and would leave the run's working
     * copies stranded because cleanup is the last link in the chain.
     *
     * @param  array{metadata: array<string, mixed>, mode: string, source: string, segments: array<int, array{start_time: float, end_time: float}>}  $extractionPlan
     */
    private function recordSermonBoundaryEvidence(array $extractionPlan): void
    {
        $evidence = $extractionPlan['metadata']['sermon_boundary'] ?? null;

        if (! is_array($evidence)) {
            return;
        }

        $section = $this->sermonSectionById($evidence['sermon_section_id'] ?? null);

        if (! $section instanceof ServiceSection) {
            return;
        }

        $requiresReview = ($evidence['requires_review'] ?? false) === true;
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['sermon_boundary'] = $evidence;

        if ($requiresReview) {
            $reviewFlags = is_array($metadata['review_flags'] ?? null)
                ? array_values(array_filter($metadata['review_flags'], 'is_string'))
                : [];

            if (! in_array(ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK, $reviewFlags, true)) {
                $reviewFlags[] = ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK;
            }

            $metadata['review_flags'] = $reviewFlags;
            $section->needs_manual_review = true;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        if ($section->isDirty()) {
            $section->save();
        }

        if (! $requiresReview) {
            return;
        }

        Log::warning('Sermon boundary evidence routed the section to review; the inclusive span is still extracted', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_section_id' => $section->id,
            'risks' => $evidence['risks'] ?? [],
        ]);
    }

    /**
     * The length of the MP3 just written, or null when it cannot be measured.
     *
     * Unmeasured is not a mismatch: an audio path the probe cannot reach makes no
     * claim about the MP3, so {@see FlagSermonAudioLengthMismatch} leaves the hold
     * as it was rather than raising or withdrawing it on no evidence.
     */
    private function measuredAudioDuration(ExtractedMediaDurationProbe $durationProbe, string $audioFullPath): ?float
    {
        try {
            return $durationProbe->durationOf($audioFullPath);
        } catch (\RuntimeException $exception) {
            Log::warning('Sermon MP3 length could not be measured against its video', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_full_path' => $audioFullPath,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sermonSectionById(mixed $sectionId): ?ServiceSection
    {
        if (! is_numeric($sectionId)) {
            return null;
        }

        return ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->whereKey((int) $sectionId)
            ->where('section_type', 'sermon')
            ->first();
    }

    /**
     * Stop before cutting anything when the sermon is under a content hold.
     *
     * A clear dominant speech block is no evidence here: the operator has already
     * said the automated account of this sermon is wrong, and a full re-run of run
     * 1314 cut a testimony, a prayer and a reading as its sermon. No speech blocks
     * are offered for confirmation, since confirming one would be that same guess.
     * The repair is a re-cut of the named section once its span is checked.
     */
    private function parkForHeldSermon(mixed $heldSectionIds): void
    {
        $sectionIds = is_array($heldSectionIds) ? array_values(array_filter($heldSectionIds, 'is_int')) : [];
        $suggestion = implode(' or ', array_map(static fn (int $id): string => "--held-section={$id}", $sectionIds));
        $reasonMessage = "The sermon section is under a content hold, so no span was cut. Once its span is checked, re-cut it with `sermons:re-extract {$this->processingLog->processing_id} {$suggestion}`; the hold stays.";

        $this->markProcessingRunForManualReview($this->processingLog, 'sermon_section_content_held', $reasonMessage);
        $this->processingLog->refresh();
        $this->notifyManualReviewRequired($reasonMessage, []);
        $this->chained = [];

        Log::warning('Sermon extraction halted: the sermon section is under a content hold', [
            'processing_id' => $this->processingLog->processing_id,
            'held_sermon_section_ids' => $sectionIds,
        ]);
    }

    private function manualReviewReason(string $reason): string
    {
        return match ($reason) {
            'no_qualifying_speech_block' => 'No speech block met the 20-minute sermon threshold.',
            'multiple_qualifying_speech_blocks' => 'Multiple speech blocks met the 20-minute sermon threshold.',
            'ratio_below_threshold' => 'The longest speech block was not at least 1.5x longer than the next-longest speech block.',
            'candidate_exceeds_maximum_duration' => 'The longest speech block exceeded the maximum plausible sermon duration, indicating under-segmentation.',
            'sermon_shorter_than_typical' => 'The recording is of the sermon alone, but the sermon is shorter than this service usually runs. It may be a genuinely short sermon, or it may not be a sermon at all.',
            default => 'Sermon auto-selection confidence was insufficient.',
        };
    }

    /**
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments
     */
    private function notifyManualReviewRequired(string $reason, array $speechSegments): void
    {
        if (app(ProcessingNotificationRouter::class)->suppressIfHistoric(
            $this->processingLog,
            'manual_review_extraction',
            'warning',
            ['reason' => $reason, 'speech_segments' => $speechSegments],
        )) {
            return;
        }

        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new ManualReviewRequired($this->processingLog->processing_id, $reason, $speechSegments));
        } catch (\Exception $exception) {
            Log::warning('Failed to queue manual review required email, continuing', [
                'processing_id' => $this->processingLog->processing_id,
                'reason' => $reason,
                'email_error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Keep a durable record of which bounds the clip was actually cut from.
     * The "Starting sermon extraction" log line carries the same facts but
     * does not survive log rotation; this metadata is what lets an operator
     * later tell an LLM-structure cut from a silent RMS-baseline fallback.
     *
     * The run-level sermon bounds are aligned to the same plan: the guard can
     * replace a baseline plan with the dominant RMS candidate after any
     * earlier write-back, and SubmitToProcessing/SermonMetadataIntegration
     * persist these fields as the Sermon's segment times — they must describe
     * the media actually cut, whichever source won.
     *
     * The bounds stay the true source window — first span start to last span
     * end — so segment_end_time remains a real livestream timestamp (it is
     * surfaced as one by SermonMetadataIntegrationService::getLivestreamInfo).
     * A concat plan joins several spans across a gap, so end minus start would
     * overstate the requested media duration. The planned sum remains available
     * through MediaProcessingLog::extractedSermonMediaDuration(); the emitted
     * video's FFprobe result is stored separately as observed duration.
     *
     * @param  array{mode: string, source: string, segments: array<int, array{start_time: float, end_time: float}>, metadata: array<string, mixed>}  $extractionPlan
     */
    private function recordExtractionPlanAudit(array $extractionPlan): void
    {
        $metadata = $this->processingLog->processing_metadata?->toArray() ?? [];
        $metadata['sermon_extraction_plan'] = [
            'source' => $extractionPlan['source'],
            'mode' => $extractionPlan['mode'],
            'strategy' => $extractionPlan['metadata']['strategy'] ?? null,
            'reason' => $extractionPlan['metadata']['reason'] ?? null,
            'sermon_boundary' => $extractionPlan['metadata']['sermon_boundary'] ?? null,
            'segments' => $extractionPlan['segments'],
            'resolved_at' => now()->toIso8601String(),
        ];

        $segments = array_values($extractionPlan['segments']);
        $lastSegment = $segments[count($segments) - 1];

        $this->processingLog->forceFill([
            'sermon_start_time' => (float) $segments[0]['start_time'],
            'sermon_end_time' => (float) $lastSegment['end_time'],
            'processing_metadata' => $metadata,
        ])->save();
    }
}
