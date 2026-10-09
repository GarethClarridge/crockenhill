<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FlagSermonAudioLengthMismatch;
use App\Actions\FlagSermonAudioLoudnessMissed;
use App\Actions\FlagSermonAudioPartUntreated;
use App\Data\ServiceSectionMetadata;
use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Enums\AudioProfile;
use App\Mail\ManualReviewRequired;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\AudioTreatmentSettings;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Processing\SermonMetadataIntegrationService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\MediaProcessingVersion;
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

            $extractionPlan = $planResolver->resolve($this->processingLog);
            if (app(EnsembleReviewGate::class)->requiresReview($this->processingLog, $extractionPlan['segments'])) {
                $reason = 'Service structure ensemble evidence needs review before any sermon extraction or no-sermon conclusion.';
                $this->markProcessingRunForManualReview($this->processingLog, 'service_structure_ensemble_review', $reason);
                $this->processingLog->refresh();
                $this->notifyManualReviewRequired($reason, []);
                $this->keepSectionCandidatePreparation();
                $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, $reason);

                return;
            }

            if ($this->concludeWithoutSermon()) {
                return;
            }

            $extractionPlan = $this->guardAutoExtractionPolicy($extractionPlan);

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
                /**
                 * One encode makes both files: every part's sound normalised on its own
                 * (a quiet reading must not stay 20 LU under the sermon it joins), and
                 * the MP3 split from that same treated sound, never transcoded from the
                 * AAC. It is the video's whole audio track by construction: the MP3 used
                 * to be cut again, and 12 historic sermons lost their closing words.
                 */
                $media = $videoExtractor->extractMedia(
                    $videoPath,
                    $extractionPlan['mode'] === 'concat_spans'
                        ? $extractionPlan['segments']
                        : [['start_time' => (float) $firstSegment['start_time'], 'end_time' => (float) $firstSegment['end_time']]],
                    AudioProfile::Speech,
                    $this->processingLog->processing_id.'_sermon.mp4',
                    treatmentOverrides: AudioTreatmentSettings::overridesFor($this->processingLog, AudioProfile::Speech),
                    withPublicAudio: true,
                );
                $sermonVideoPath = $media->videoPath;

                $sermonVideoAbsolutePath = Storage::disk($tempDisk)->path($sermonVideoPath);
                $observedDuration = $durationProbe->durationOf($sermonVideoAbsolutePath);

                $audioExtractionResult = $videoExtractor->storePublicAudio(
                    (string) $media->audioPath,
                    $this->processingLog->processing_id.'_sermon.mp3',
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
            (new FlagSermonAudioPartUntreated)($this->processingLog, $media);
            (new FlagSermonAudioLoudnessMissed)($this->processingLog, $media);

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
                        'public_audio' => [
                            'size_mb' => round($audioExtractionResult['size'] / 1024 / 1024, 1),
                            'sample_rate' => (int) config('media-processing.audio_treatment.public_mp3.sample_rate'),
                            'bitrate_kbps' => (int) config('media-processing.audio_treatment.public_mp3.bitrate_kbps'),
                        ],
                        'audio_treatment' => $media->audio,
                    ]
                ),
            ]);

            $this->authoriseReplacementIfCutChanged(
                $supersededVideoDuration,
                $observedDuration,
                $supersededVideoSpans,
                $extractionPlan['segments'],
            );

            Log::info('Sermon extraction completed', [
                'processing_id' => $this->processingLog->processing_id,
                'video_path' => $sermonVideoPath,
                'audio_path' => $sermonAudioPath,
                'observed_duration' => $observedDuration,
                'audio_full_path' => $audioExtractionResult['full_path'],
                'audio_size_mb' => round($audioExtractionResult['size'] / 1024 / 1024, 1),
                'untreated_audio_parts' => $media->untreatedParts(),
                'loudness_misses' => $media->loudnessMisses(),
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
     * a boundary written one minute late produces. Missing or older processing
     * provenance independently requires replacement under the current media version.
     *
     * The tolerance absorbs container rounding — a stored duration and a fresh
     * probe of the same cut differ in the third decimal — without absorbing any
     * real span change; the smallest genuine divergence measured across the
     * corpus was 1.0 s.
     *
     * @param  list<array{start: float, end: float}>|null  $supersededSpans
     * @param  list<array{start_time: float, end_time: float}>  $plannedSegments
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

        $storedVideo = data_get($this->processingLog->processing_metadata?->toArray(), 'stored_video');
        $versionChanged = ! MediaProcessingVersion::matches($storedVideo['media_processing'] ?? null)
            || ($storedVideo['audio_overrides'] ?? []) != AudioTreatmentSettings::overridesFor($this->processingLog, AudioProfile::Speech);

        if (! $spansChanged && ! $durationChanged && ! $versionChanged) {
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
     * @param  list<array{start_time: float, end_time: float}>  $plannedSegments
     */
    private function spansDiffer(array $supersededSpans, array $plannedSegments): bool
    {

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
     * @param  list<array{start_time: float, end_time: float}>  $segments
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
     *     mode: 'single_span'|'concat_spans',
     *     source: 'service_sections',
     *     segments: list<array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }  $extractionPlan
     * @return array{
     *     mode: 'single_span'|'concat_spans',
     *     source: 'service_sections',
     *     segments: list<array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }|null
     */
    private function guardAutoExtractionPolicy(
        array $extractionPlan
    ): ?array {
        $this->recordSermonBoundaryEvidence($extractionPlan);

        if (($extractionPlan['metadata']['reason'] ?? null) === 'edge_word_timings_missing') {
            $reason = $extractionPlan['metadata']['edge_word_timings_error'];
            $this->markProcessingRunForManualReview($this->processingLog, 'edge_word_timings_missing', $reason);
            $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, $reason);
            $this->keepSectionCandidatePreparation();

            return null;
        }
        if (($extractionPlan['metadata']['reason'] ?? null) === 'sermon_section_content_held') {
            $this->parkForHeldSermon($extractionPlan['metadata']['held_sermon_section_ids'] ?? []);

            return null;
        }

        if (($extractionPlan['metadata']['requires_review'] ?? false) === true || $extractionPlan['segments'] === []) {
            $reason = 'Resolve the identified sermon sections and uncovered speech before extraction.';
            $this->markProcessingRunForManualReview($this->processingLog, 'sermon_composition_review', $reason);
            $this->processingLog->refresh();
            $this->notifyManualReviewRequired($reason, []);
            $this->keepSectionCandidatePreparation();

            return null;
        }

        return $extractionPlan;
    }

    /**
     * Record the resolved sermon-boundary evidence on the sermon section, and
     * mark it for review when that evidence names a material risk.
     *
     * @param  array{metadata: array<string, mixed>, mode: string, source: string, segments: list<array{start_time: float, end_time: float}>}  $extractionPlan
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

        Log::warning('Sermon composition evidence requires review before extraction', [
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

    private function keepSectionCandidatePreparation(): void
    {
        // A parked sermon does not park unrelated clips or run sermon-only jobs without media.
        $this->chained = array_values(array_filter($this->chained,
            static fn (string $job): bool => str_contains($job, PrepareSectionPublicationCandidates::class)));
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
        $this->keepSectionCandidatePreparation();

        Log::warning('Sermon extraction halted: the sermon section is under a content hold', [
            'processing_id' => $this->processingLog->processing_id,
            'held_sermon_section_ids' => $sectionIds,
        ]);
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
     * later inspect the selected sections, bounds and processing version.
     *
     * The run-level sermon bounds follow the selected source window because
     * SubmitToProcessing/SermonMetadataIntegration persist these fields on the sermon.
     *
     * The bounds stay the true source window — first span start to last span
     * end — so segment_end_time remains a real livestream timestamp (it is
     * surfaced as one by SermonMetadataIntegrationService::getLivestreamInfo).
     * A concat plan joins several spans across a gap, so end minus start would
     * overstate the requested media duration. The planned sum remains available
     * through MediaProcessingLog::extractedSermonMediaDuration(); the emitted
     * video's FFprobe result is stored separately as observed duration.
     *
     * @param  array{mode: string, source: string, segments: list<array{start_time: float, end_time: float}>, metadata: array<string, mixed>}  $extractionPlan
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
            'cue_edge_widening' => $extractionPlan['metadata']['cue_edge_widening'] ?? [],
            'selected_section_ids' => $extractionPlan['metadata']['selected_section_ids'] ?? [],
            'media_processing' => MediaProcessingVersion::signature(),
            'resolved_at' => now()->toIso8601String(),
        ];

        $segments = $extractionPlan['segments'];
        $lastSegment = $segments[count($segments) - 1];

        $this->processingLog->forceFill([
            'sermon_start_time' => (float) $segments[0]['start_time'],
            'sermon_end_time' => (float) $lastSegment['end_time'],
            'processing_metadata' => $metadata,
        ])->save();
    }
}
