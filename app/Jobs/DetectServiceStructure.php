<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionType;
use App\Enums\ServiceStructureMode;
use App\Mail\ManualReviewRequired;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\ChurchService\Structure\ValidationResult;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\SermonAutoExtractionPolicy;
use App\Support\ServiceSectionConfidence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * One LLM call turns the full-service transcript plus the order of service
 * into typed, timed sections — then deterministic code takes over: silence
 * snapping, structural validation, and only then persistence.
 *
 * In shadow mode the heuristic sections stay authoritative: the proposal is
 * written to run metadata with a structured diff, and no failure here ever
 * fails the run. In primary mode a hard validation failure routes the run to
 * the existing manual segment-confirmation flow.
 *
 * A reconcile re-run (dispatched by ReconcileServiceSections when OoS items
 * arrive after the run completed) re-detects against the stored transcript
 * artifact with the new items, but never re-opens the completed run: run
 * status is left untouched, and a hard validation failure keeps the existing
 * sections authoritative instead of routing to manual review.
 */
class DetectServiceStructure extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        private MediaProcessingLog $processingLog,
        public readonly bool $reconcile = false,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('detect-service-structure-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
        ServiceSectionSyncService $syncService,
        SermonCandidateConfidenceService $sermonConfidenceService,
    ): void {
        $mode = ServiceStructureMode::fromConfig();

        if ($mode === ServiceStructureMode::Off) {
            return;
        }

        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
                'Structure detection only runs for livestream processing'
            );

            return;
        }

        // A reconcile re-run happens after the run completed; re-marking it as
        // processing would strand it (nothing downstream completes it again).
        if (! $this->reconcile) {
            $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::DetectServiceStructure->value);
        }

        $this->logStepStart(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE);

        if ($mode === ServiceStructureMode::Shadow) {
            $this->runShadow($detector, $snapService, $validator);

            return;
        }

        $this->runPrimary($detector, $snapService, $validator, $syncService, $sermonConfidenceService);
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
            $exception->getMessage()
        );
    }

    /**
     * Shadow: detect, gate, record — never touch service_sections, never fail the run.
     */
    private function runShadow(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
    ): void {
        try {
            $hasAuthoritativeSections = ServiceSection::query()
                ->where('media_processing_log_id', $this->processingLog->id)
                ->exists();

            [$result, $transcript, $boundStructure] = $this->detectShadowCandidate(
                $detector,
                $snapService,
                $validator,
                requireBoundBaseline: ! $hasAuthoritativeSections,
            );

            $classified = $result->structure->toClassifiedSections(
                $this->processingLog,
                $transcript,
                allowSegmentSynthesis: false,
            );

            $diff = $this->diffAgainstAuthoritativeSections($result->structure);

            if (! $hasAuthoritativeSections && $boundStructure instanceof ServiceStructure) {
                $diff = $this->diffAgainstBoundStructure($result->structure, $boundStructure);
            }

            $this->putStructureMetadata(
                'service_structure_shadow',
                $this->proposalPayload($result, $classified) + ['diff' => $diff]
            );

            Log::info('Service structure shadow diff', [
                'processing_id' => $this->processingLog->processing_id,
                'passed_validation' => $result->passed(),
                'hard_failure_codes' => $result->failureCodes(),
                ...$diff,
            ]);

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
                sprintf('Shadow proposal recorded (%d section(s), validation %s)', count($classified), $result->passed() ? 'passed' : 'failed')
            );
        } catch (\Throwable $throwable) {
            Log::warning('Service structure shadow run failed; heuristic pipeline unaffected', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);

            $this->putStructureMetadata('service_structure_shadow', [
                'generated_at' => now()->toIso8601String(),
                'error' => $throwable->getMessage(),
            ]);

            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
                'Shadow structure detection failed: '.$throwable->getMessage()
            );
        }
    }

    /**
     * Primary: the gate decides — sync on pass, manual review on hard failure.
     */
    private function runPrimary(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
        ServiceSectionSyncService $syncService,
        SermonCandidateConfidenceService $sermonConfidenceService,
    ): void {
        [$result, $transcript] = $this->detectAndValidate($detector, $snapService, $validator);

        if (! $result->passed() && $this->detectionWorthRetrying($result)) {
            Log::warning('Service structure output mechanically impossible; retrying detection once', [
                'processing_id' => $this->processingLog->processing_id,
                'failure_codes' => $result->failureCodes(),
            ]);

            $this->putStructureMetadata('service_structure_retry', [
                'generated_at' => now()->toIso8601String(),
                'failure_codes' => $result->failureCodes(),
                'failure_summary' => $result->failureSummary(),
            ]);

            [$result, $transcript] = $this->detectAndValidate($detector, $snapService, $validator);
        }

        if ($result->passed()) {
            $result = $this->recheckMissingPreachedReading($result, $detector, $snapService, $validator);
        }

        if (! $result->passed() && $this->reconcile) {
            // The run completed with validated sections; a failed re-detection
            // must not un-complete it. Keep the existing sections authoritative
            // and record the rejected proposal for diagnosis.
            $this->persistFailedProposal($result, $transcript);

            $reasonMessage = 'Reconcile re-detection failed validation; existing sections retained: '.$result->failureSummary();
            $this->logStepSkipped(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $reasonMessage);

            Log::warning('Service structure reconcile re-detection failed validation; existing sections retained', [
                'processing_id' => $this->processingLog->processing_id,
                'failure_codes' => $result->failureCodes(),
            ]);

            return;
        }

        if (! $result->passed()) {
            $reasonMessage = 'Detected service structure failed validation: '.$result->failureSummary();
            $evaluation = $sermonConfidenceService->evaluateForProcessingLog($this->processingLog);

            $this->persistFailedProposal($result, $transcript);

            $this->markProcessingRunForManualReview(
                $this->processingLog,
                'llm_structure_validation_failed',
                $reasonMessage,
                $evaluation['speech_segments']
            );

            $this->notifyManualReviewRequired($reasonMessage, $evaluation['speech_segments']);

            // Stop the remaining chained jobs; the operator resumes via the
            // existing segment-confirmation flow (post-review chain).
            $this->chained = [];

            $this->logStepFailed(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $reasonMessage);

            Log::warning('Service structure detection routed to manual review', [
                'processing_id' => $this->processingLog->processing_id,
                'failure_codes' => $result->failureCodes(),
            ]);

            return;
        }

        $classified = $result->structure->toClassifiedSections($this->processingLog, $transcript);

        $syncService->sync($this->processingLog, $classified);

        if ($this->reconcile) {
            $this->openServiceReviewFromSyncedSections();
        }

        $this->writeBackSermonBounds($classified);

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
            sprintf('Persisted %d LLM-detected section(s)', count($classified))
        );
    }

    /**
     * ProjectLivestreamServiceStructure does not run on a reconcile re-run, so
     * roll section review state up to the linked service here (additive only —
     * a clean re-detection never closes an open review).
     */
    private function openServiceReviewFromSyncedSections(): void
    {
        $churchService = $this->resolveChurchService();

        if (! $churchService instanceof ChurchService) {
            return;
        }

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->get();

        app(ChurchServiceReviewSynchronizer::class)->openReviewFromSections($churchService, $sections);
    }

    /**
     * Align the run-level sermon fields with the accepted sermon section.
     *
     * sermon_start_time/sermon_end_time are written early by AnalyzeSegments
     * from the longest RMS speech segment — a coarse guess that downstream
     * consumers (SubmitToProcessing, SermonMetadataIntegrationService) and
     * SermonExtractionPlanResolver's baseline fallback read regardless of the
     * validated structure. Once a structure passes the gate, its sermon bounds
     * are authoritative, so the fallback carries them instead of the RMS guess.
     *
     * @param  array<int, array<string, mixed>>  $classified
     */
    private function writeBackSermonBounds(array $classified): void
    {
        // A manually confirmed segment outranks every detector; never move it.
        if ($this->processingLog->manuallyConfirmedSegmentId() !== null) {
            return;
        }

        $sermon = collect($classified)->firstWhere('section_type', ServiceSectionType::Sermon->value);

        if ($sermon === null) {
            return;
        }

        // Only promote bounds the resolver would actually auto-extract from.
        // SermonExtractionPlanResolver::findPreferredSection() excludes sections
        // whose review state disqualifies them (per SermonAutoExtractionPolicy)
        // or that sit below the high-confidence threshold, so such a sermon
        // falls through to the baseline path for manual review. Writing its
        // bounds into the baseline anyway would let that fallback auto-extract
        // the very section the resolver rejected.
        if (! $this->sermonSectionEligibleForAutoExtraction($sermon)) {
            return;
        }

        $metadata = $this->processingLog->processing_metadata?->toArray() ?? [];
        $metadata['sermon_bounds'] = [
            'source' => 'llm_structure',
            'written_at' => now()->toIso8601String(),
            'previous_start' => $this->processingLog->sermon_start_time,
            'previous_end' => $this->processingLog->sermon_end_time,
        ];

        $this->processingLog->forceFill([
            'sermon_start_time' => $sermon['start_time'],
            'sermon_end_time' => $sermon['end_time'],
            'processing_metadata' => $metadata,
        ])->save();
    }

    /**
     * Whether the classified sermon section would be eligible for automatic
     * extraction, mirroring SermonExtractionPlanResolver::findPreferredSection():
     * a review state SermonAutoExtractionPolicy permits (ordering flags alone
     * do not disqualify), at or above the high-confidence threshold, and a
     * positive-duration span.
     *
     * @param  array<string, mixed>  $sermon
     */
    private function sermonSectionEligibleForAutoExtraction(array $sermon): bool
    {
        $metadata = is_array($sermon['metadata'] ?? null) ? $sermon['metadata'] : [];
        $reviewFlags = is_array($metadata['review_flags'] ?? null)
            ? array_values(array_filter($metadata['review_flags'], 'is_string'))
            : [];

        if (! SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(
            (bool) ($sermon['needs_manual_review'] ?? true),
            $reviewFlags,
        )) {
            return false;
        }

        if ((float) ($sermon['confidence'] ?? 0.0) < ServiceSectionConfidence::HIGH_THRESHOLD) {
            return false;
        }

        return (float) ($sermon['end_time'] ?? 0.0) > (float) ($sermon['start_time'] ?? 0.0);
    }

    /**
     * Queue the same admin alert the heuristic manual-review path sends, so a
     * primary-mode gate failure never sits awaiting an operator unnoticed.
     *
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments
     */
    private function notifyManualReviewRequired(string $reason, array $speechSegments): void
    {
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
     * A validated structure with a sermon but no Bible reading anywhere near
     * it almost always means the detector buried the preached passage inside
     * another section (the 2024-11-03 corpus run absorbed the Luke reading
     * into the pastoral prayer) — sermon extraction would then publish audio
     * without its reading. One feedback-guided retry names the anomaly to the
     * detector; its result is adopted only when it validates AND recovers a
     * reading, so reconsideration can never make a passing run worse. When
     * the retry finds nothing the original structure stands, with the sermon
     * flagged for the reviewer (a non-disqualifying flag — see
     * SermonAutoExtractionPolicy).
     */
    private function recheckMissingPreachedReading(
        ValidationResult $result,
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
    ): ValidationResult {
        if (! (bool) config('media-processing.service_structure.reading_recheck', true)) {
            return $result;
        }

        $sermonStart = $this->readinglessSermonStart($result->structure);

        if ($sermonStart === null) {
            return $result;
        }

        $windowSeconds = $this->readingPairingWindowSeconds();

        Log::info('Validated structure lacks a reading near the sermon; retrying detection with feedback', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_start' => $sermonStart,
        ]);

        try {
            [$retry] = $this->detectAndValidate($detector, $snapService, $validator, [sprintf(
                'The previous attempt found a sermon starting at %.0f seconds but no bible_reading section '
                .'in the %.0f minutes before it. The preached passage is usually read shortly before the '
                .'sermon and may be embedded inside another section (often a prayer, or the sermon opening). '
                .'If a distinct Bible reading is present there, return it as its own bible_reading section '
                .'with its reading_reference; do NOT invent one if no reading occurs.',
                $sermonStart,
                $windowSeconds / 60.0,
            )]);
        } catch (\Throwable $exception) {
            // The recheck is a best-effort recovery on an already-passing run; a transient
            // detector error (OpenAI timeout, malformed JSON, a missing artifact) must never
            // downgrade that pass to a job failure. Keep the validated structure, sermon flagged.
            Log::warning('Missing-reading recheck failed; keeping the original validated structure', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start' => $sermonStart,
                'recheck_error' => $exception->getMessage(),
            ]);

            $this->putStructureMetadata('service_structure_reading_recheck', [
                'generated_at' => now()->toIso8601String(),
                'outcome' => 'recheck_errored',
                'sermon_start' => $sermonStart,
                'error' => $exception->getMessage(),
            ]);

            return new ValidationResult(
                structure: $this->withSermonFlagged($result->structure),
                hardFailures: $result->hardFailures,
                unmatchedOosItemIds: $result->unmatchedOosItemIds,
            );
        }

        if ($retry->passed() && $this->readinglessSermonStart($retry->structure) === null) {
            $this->putStructureMetadata('service_structure_reading_recheck', [
                'generated_at' => now()->toIso8601String(),
                'outcome' => 'retry_adopted',
                'sermon_start' => $sermonStart,
            ]);

            return $retry;
        }

        $this->putStructureMetadata('service_structure_reading_recheck', [
            'generated_at' => now()->toIso8601String(),
            'outcome' => 'reading_still_missing',
            'sermon_start' => $sermonStart,
            'retry_passed_validation' => $retry->passed(),
        ]);

        return new ValidationResult(
            structure: $this->withSermonFlagged($result->structure),
            hardFailures: $result->hardFailures,
            unmatchedOosItemIds: $result->unmatchedOosItemIds,
        );
    }

    /**
     * The sermon's start time when the structure has a sermon but no
     * bible_reading section ending within the pairing window before it;
     * null when there is no sermon or a reading sits close enough.
     */
    private function readinglessSermonStart(ServiceStructure $structure): ?float
    {
        $sermons = $structure->sectionsOfType(ServiceSectionType::Sermon);

        if ($sermons === []) {
            return null;
        }

        $sermonStart = $sermons[0]->startTime;
        $windowSeconds = $this->readingPairingWindowSeconds();

        foreach ($structure->sectionsOfType(ServiceSectionType::BibleReading) as $reading) {
            if ($reading->endTime <= $sermonStart && $sermonStart - $reading->endTime <= $windowSeconds) {
                return null;
            }
        }

        return $sermonStart;
    }

    /**
     * The same window SermonExtractionPlanResolver pairs readings within —
     * a reading further out would not reach the published audio anyway.
     */
    private function readingPairingWindowSeconds(): float
    {
        return (float) config('media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds', 900);
    }

    private function withSermonFlagged(ServiceStructure $structure): ServiceStructure
    {
        $sections = array_map(
            static fn ($section) => $section->type === ServiceSectionType::Sermon
                ? $section->withReviewFlags([ServiceStructureValidator::FLAG_MISSING_PREACHED_READING])
                : $section,
            $structure->sections
        );

        return new ServiceStructure($sections, $structure->notes, $structure->model);
    }

    /**
     * Whether the validation failure indicates mechanically impossible detector
     * output rather than a semantic judgement call.
     *
     * A section timestamped beyond the recording cannot be a legitimate reading
     * of the audio — it is corrupted model output (the 2023-02-26 corpus run
     * placed a sermon end at 41410s in a 4408s recording), and one fresh
     * detection attempt is cheap relative to a manual review. Semantic failures
     * (multiple sermons, ordering conflicts) would just re-run the same
     * judgement, so they go straight to the reviewer.
     */
    private function detectionWorthRetrying(ValidationResult $result): bool
    {
        return in_array('timestamps_outside_recording', $result->failureCodes(), true);
    }

    /**
     * @param  list<string>  $feedback
     * @return array{0: ValidationResult, 1: ChurchServiceTranscript}
     */
    private function detectAndValidate(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
        array $feedback = [],
    ): array {
        $transcript = $this->loadTranscript();
        $oosItems = $this->loadOosItems();

        $structure = $detector->detect(
            $transcript,
            $this->oosItemPayloads($oosItems),
            $this->processingLog->processing_id,
            $feedback
        );

        $structure = $this->snapToSilences($structure, $snapService);

        $result = $validator->validate($structure, ValidationContext::for($transcript, $oosItems));

        return [$result, $transcript];
    }

    private function loadTranscript(): ChurchServiceTranscript
    {
        $transcriptPath = $this->processingLog->serviceTranscriptPath();

        if ($transcriptPath === null) {
            throw new \RuntimeException('No full-service transcript recorded for this run; TranscribeFullService must run first.');
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if (! Storage::disk($tempDisk)->exists($transcriptPath)) {
            throw new \RuntimeException("Full-service transcript artifact missing: {$transcriptPath}");
        }

        $transcript = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk($tempDisk)->get($transcriptPath), true)
        );

        if ($transcript->isEmpty()) {
            throw new \RuntimeException('Stored full-service transcript contains no cues.');
        }

        return $transcript;
    }

    /**
     * @return list<ChurchServiceItem>
     */
    private function loadOosItems(): array
    {
        $churchService = $this->resolveChurchService();

        if (! $churchService instanceof ChurchService) {
            return [];
        }

        return array_values($churchService->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function resolveChurchService(): ?ChurchService
    {
        if ($this->processingLog->church_service_id !== null) {
            return $this->processingLog->churchService()->first();
        }

        $identity = app(MediaProcessingIdentityResolver::class)->resolve($this->processingLog);

        if ($identity === null) {
            return null;
        }

        $churchService = ChurchService::query()
            ->where('date', $identity['date'])
            ->where('service', $identity['service']->value)
            ->first();

        if ($churchService instanceof ChurchService) {
            $this->processingLog->forceFill([
                'church_service_id' => $churchService->id,
            ])->saveQuietly();
        }

        return $churchService;
    }

    /**
     * @param  list<ChurchServiceItem>  $items
     * @return array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>
     */
    private function oosItemPayloads(array $items): array
    {
        return array_map(
            static fn (ChurchServiceItem $item): array => [
                'id' => (int) $item->id,
                'position' => (int) $item->position,
                'type' => $item->semanticSectionType()->value,
                'title' => $item->title,
                'song_id' => $item->song_id === null ? null : (int) $item->song_id,
            ],
            $items
        );
    }

    private function snapToSilences(ServiceStructure $structure, SilenceSnapService $snapService): ServiceStructure
    {
        $rmsLogPath = $this->processingLog->rms_log_path;

        if (! is_string($rmsLogPath) || $rmsLogPath === '') {
            return $structure;
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if (! Storage::disk($tempDisk)->exists($rmsLogPath)) {
            return $structure;
        }

        return $snapService->snap($structure, (string) Storage::disk($tempDisk)->get($rmsLogPath));
    }

    /**
     * Structured comparison of the shadow proposal against the authoritative
     * service_sections — the shadow-mode evidence stream.
     *
     * The baseline is whatever wrote those sections: the heuristic cluster
     * today, the bound model's primary output after the flip. The diff records
     * that provenance so reports can tell the two apart; the comparison logic
     * itself is baseline-agnostic and survives the cluster's retirement.
     *
     * @return array<string, mixed>
     */
    private function diffAgainstAuthoritativeSections(ServiceStructure $structure): array
    {
        $authoritativeSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        $baselineSections = array_values($authoritativeSections
            ->map(fn (ServiceSection $section): array => [
                'type' => $section->section_type,
                'start_time' => (float) $section->start_time,
                'end_time' => (float) $section->end_time,
                'oos_item_id' => $section->church_service_item_id,
            ])
            ->all());

        return $this->diffAgainstBaselineSections($structure, $baselineSections, [
            'classification_modes' => array_values($authoritativeSections
                ->map(fn (ServiceSection $section): ?string => $section->metadata?->classificationMode)
                ->filter()
                ->unique()
                ->values()
                ->all()),
            'models' => array_values($authoritativeSections
                ->map(function (ServiceSection $section): ?string {
                    $model = $section->metadata?->raw['model'] ?? null;

                    return is_string($model) ? $model : null;
                })
                ->filter()
                ->unique()
                ->values()
                ->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diffAgainstBoundStructure(ServiceStructure $candidate, ServiceStructure $bound): array
    {
        $baselineSections = array_map(
            static fn ($section): array => [
                'type' => $section->type,
                'start_time' => $section->startTime,
                'end_time' => $section->endTime,
                'oos_item_id' => $section->oosItemId,
            ],
            $bound->sections,
        );

        return $this->diffAgainstBaselineSections($candidate, $baselineSections, [
            'classification_modes' => ['llm_structure'],
            'models' => is_string($bound->model) && $bound->model !== '' ? [$bound->model] : [],
        ]);
    }

    /**
     * @param  list<array{type: ServiceSectionType, start_time: float, end_time: float, oos_item_id: int|null}>  $baselineSections
     * @param  array{classification_modes: list<string>, models: list<string>}  $provenance
     * @return array<string, mixed>
     */
    private function diffAgainstBaselineSections(
        ServiceStructure $structure,
        array $baselineSections,
        array $provenance,
    ): array {
        $baselineTypes = array_map(static fn (array $section): string => $section['type']->value, $baselineSections);
        $llmTypes = array_map(static fn ($section) => $section->type->value, $structure->sections);

        $diff = [
            'baseline' => $provenance,
            'heuristic_section_count' => count($baselineTypes),
            'llm_section_count' => count($llmTypes),
            'heuristic_types' => $baselineTypes,
            'llm_types' => $llmTypes,
            'type_sequence_match' => $baselineTypes === $llmTypes,
            'sermon' => null,
            'boundary_deltas' => null,
            'oos_anchoring' => null,
        ];

        $baselineSermon = null;

        foreach ($baselineSections as $baselineSection) {
            if ($baselineSection['type'] === ServiceSectionType::Sermon) {
                $baselineSermon = $baselineSection;

                break;
            }
        }

        $llmSermons = $structure->sectionsOfType(ServiceSectionType::Sermon);

        if (is_array($baselineSermon) && $llmSermons !== []) {
            $diff['sermon'] = [
                'heuristic_start' => $baselineSermon['start_time'],
                'heuristic_end' => $baselineSermon['end_time'],
                'llm_start' => $llmSermons[0]->startTime,
                'llm_end' => $llmSermons[0]->endTime,
                'start_delta' => round($llmSermons[0]->startTime - $baselineSermon['start_time'], 2),
                'end_delta' => round($llmSermons[0]->endTime - $baselineSermon['end_time'], 2),
            ];
        }

        if ($diff['type_sequence_match']) {
            $boundaryDeltas = [];
            $oosMatches = 0;
            $oosConflicts = 0;

            foreach ($structure->sections as $index => $section) {
                $baseline = $baselineSections[$index] ?? null;

                if (! is_array($baseline)) {
                    continue;
                }

                $boundaryDeltas[] = [
                    'type' => $section->type->value,
                    'start_delta' => round($section->startTime - $baseline['start_time'], 2),
                    'end_delta' => round($section->endTime - $baseline['end_time'], 2),
                ];

                if ($section->oosItemId === $baseline['oos_item_id']) {
                    $oosMatches++;
                } else {
                    $oosConflicts++;
                }
            }

            $diff['boundary_deltas'] = $boundaryDeltas;
            $diff['oos_anchoring'] = ['agreements' => $oosMatches, 'disagreements' => $oosConflicts];
        }

        return $diff;
    }

    /**
     * Shadow detection with the configured candidate model, when one is set.
     *
     * The bound `model` stays authoritative; `shadow_model` lets a candidate
     * (a prompt/model upgrade) run against the same transcript so the diff
     * scores it against the authoritative sections. The override is restored
     * even when detection throws — queue workers reuse the config repository
     * across jobs.
     *
     * @return array{0: ValidationResult, 1: ChurchServiceTranscript, 2: ServiceStructure|null}
     */
    private function detectShadowCandidate(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
        bool $requireBoundBaseline,
    ): array {
        $boundModel = (string) config('media-processing.service_structure.model', 'gpt-5');
        $shadowModel = config('media-processing.service_structure.shadow_model');

        if (! is_string($shadowModel) || trim($shadowModel) === '' || $shadowModel === $boundModel) {
            [$result, $transcript] = $this->detectAndValidate($detector, $snapService, $validator);

            return [$result, $transcript, null];
        }

        $boundStructure = null;

        if ($requireBoundBaseline) {
            [$boundResult] = $this->detectAndValidate($detector, $snapService, $validator);

            if (! $boundResult->passed()) {
                throw new \UnexpectedValueException(
                    'Bound service structure model did not produce a valid shadow baseline: '.$boundResult->failureSummary()
                );
            }

            $boundStructure = ServiceStructure::fromSections(
                $boundResult->structure->sections,
                $boundResult->structure->notes,
                $boundModel,
            );
        }

        config(['media-processing.service_structure.model' => $shadowModel]);

        try {
            [$result, $transcript] = $this->detectAndValidate($detector, $snapService, $validator);

            return [$result, $transcript, $boundStructure];
        } finally {
            config(['media-processing.service_structure.model' => $boundModel]);
        }
    }

    /**
     * A hard validation failure discards nothing: the (often largely correct)
     * proposal is kept in run metadata so the reviewer starts from the
     * detected structure and the run stays scoreable. Persistence problems
     * here must never block the manual-review routing itself.
     */
    private function persistFailedProposal(ValidationResult $result, ChurchServiceTranscript $transcript): void
    {
        try {
            $classified = $result->structure->toClassifiedSections(
                $this->processingLog,
                $transcript,
                allowSegmentSynthesis: false,
            );

            $this->putStructureMetadata('service_structure_proposal', $this->proposalPayload($result, $classified));
        } catch (\Throwable $throwable) {
            Log::warning('Failed to persist rejected service structure proposal, continuing to manual review', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $classified
     * @return array<string, mixed>
     */
    private function proposalPayload(ValidationResult $result, array $classified): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'model' => $result->structure->model,
            'passed_validation' => $result->passed(),
            'hard_failures' => $result->hardFailures,
            'unmatched_oos_item_ids' => $result->unmatchedOosItemIds,
            'sections' => array_map(
                static function (array $section): array {
                    /** @var array<string, mixed> $metadata */
                    $metadata = $section['metadata'] ?? [];

                    return collect($section)->except('metadata')->all()
                        + ['metadata' => collect($metadata)->except('transcript')->all()];
                },
                $classified
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putStructureMetadata(string $key, array $payload): void
    {
        $metadata = $this->processingLog->processing_metadata?->toArray() ?? [];
        $metadata[$key] = $payload;

        $this->processingLog->forceFill(['processing_metadata' => $metadata])->save();
    }
}
