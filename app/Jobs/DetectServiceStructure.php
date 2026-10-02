<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionType;
use App\Enums\ServiceStructureMode;
use App\Exceptions\UnplacedContentHoldException;
use App\Mail\ManualReviewRequired;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\ContentHoldRechecker;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\CutAwareEnsembleComposer;
use App\Services\ChurchService\Structure\EnsembleComposition;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleInput;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRunner;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\ChurchService\Structure\SoundStage;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\ChurchService\Structure\ValidationResult;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\SermonAutoExtractionPolicy;
use App\Support\ServiceArtifactDisk;
use App\Support\ServiceSectionConfidence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Primary mode collects four independent detector readings of one banked
 * snapshot, then composes typed, timed sections deterministically. Shadow mode
 * retains its single non-authoritative reading.
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
 *
 * A recompose request ({@see self::RECOMPOSE_KEY}) makes no draw: the run's latest banked
 * draws are composed again under the current rules and every answer on the run, and the
 * result goes through the same validation, sync and review as a fresh ensemble. What the
 * operator reviewed is then what is cut, and adopting a rule costs no new draws or questions.
 */
class DetectServiceStructure extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Where a request to recompose the latest banked draws waits for this job, naming the attempt.
     * It is a durable instruction rather than a job argument so a retry recomposes again instead of
     * paying for new draws, and it is withdrawn once the job settles either way.
     */
    public const RECOMPOSE_KEY = 'service_structure_recompose';

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        private MediaProcessingLog $processingLog,
        public readonly bool $reconcile = false,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * This stage bills a provider. Without a backoff the queue retries at once,
     * so a rate limit or a 5xx burns all three attempts in seconds — and pays
     * for each one that reached the model before failing. The delays match
     * {@see ProcessTranscriptWithAI::backoff()}, the pipeline's other
     * paid stage.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [120, 300, 600];
    }

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

        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        if (! $this->processingLog->usesSegmentationPipeline()) {
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
                'Structure detection only runs for segmentation pipelines'
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
        $this->withdrawRecomposeRequest();
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        // A request left behind would turn the run's next fresh detection into a recompose.
        $this->withdrawRecomposeRequest();
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
        $sectionRevision = $this->sectionRevision();
        [$result, $transcript, $ensemble, $input] = $this->detectWithEnsemble($detector, $snapService, $validator);

        if ((! $result->passed() || $ensemble->requiresReview()) && $this->reconcile) {
            // The run completed with validated sections; a failed re-detection
            // must not un-complete it. Keep the existing sections authoritative
            // and record the rejected proposal for diagnosis.
            $this->persistFailedProposal($result, $transcript);

            $reasonMessage = 'Reconcile ensemble needs review; existing sections retained: '.$result->failureSummary();
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

        try {
            // The draws take minutes. Whatever changed meanwhile — a cancellation, an operator's
            // section edit, a new input — is checked under the run's row lock in the same
            // transaction as the write, so nothing can land between the check and the sync.
            $refusal = DB::transaction(function () use ($input, $sectionRevision, $syncService, $classified): ?string {
                $locked = MediaProcessingLog::query()->lockForUpdate()->findOrFail($this->processingLog->id);

                if ($locked->isCancelled()) {
                    return 'cancelled';
                }

                if ($this->sectionRevision() !== $sectionRevision) {
                    return 'sections_changed';
                }

                $this->assertEnsembleInputCurrent($input);
                $syncService->sync($this->processingLog, $classified);

                return null;
            });
        } catch (UnplacedContentHoldException $exception) {
            $this->refuseUnplacedContentHold($exception, $result, $classified);

            return;
        }

        if ($refusal === 'cancelled') {
            $this->chained = [];
            $this->logStepSkipped(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, 'Run was cancelled while the structure draws were running; nothing written.');

            return;
        }

        if ($refusal === 'sections_changed') {
            $this->refuseConcurrentSectionChange($result, $transcript, $sermonConfidenceService);

            return;
        }

        $this->putStructureMetadata('service_structure', $result->structure->toArray());

        // Holds have just followed their content; a check that exists in code now
        // re-tests the content against the transcript this detection read.
        app(ContentHoldRechecker::class)->recheck($this->processingLog);

        if ($ensemble->requiresReview()) {
            $reasonMessage = 'Service structure ensemble has unresolved disagreement or reduced vote coverage.';
            $evaluation = $sermonConfidenceService->evaluateForProcessingLog($this->processingLog);
            $this->markProcessingRunForManualReview(
                $this->processingLog,
                'service_structure_ensemble_review',
                $reasonMessage,
                $evaluation['speech_segments'],
            );
            $this->notifyManualReviewRequired($reasonMessage, $evaluation['speech_segments']);
            $this->chained = [];
            $this->logStepComplete(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, 'Reviewable ensemble proposal persisted');

            return;
        }

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
     * @return array{0: ValidationResult, 1: ChurchServiceTranscript, 2: EnsembleComposition, 3: array<string, mixed>}
     */
    private function detectWithEnsemble(
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
    ): array {
        $runner = new ServiceStructureEnsembleRunner(
            new ServiceStructureDrawExecutor($detector, $snapService, app(SoundStage::class), $validator),
            app(HistoricStagingContextRegistry::class),
        );
        $recompose = $this->reconcile ? null : $this->recomposeRequest();

        if ($recompose !== null) {
            return $this->recomposeBankedAttempt($recompose, $runner, $validator);
        }

        [
            'input' => $input,
            'transcript' => $transcript,
            'context' => $context,
        ] = app(ServiceStructureEnsembleInput::class)->build($this->processingLog, $this->resolveChurchService());

        $run = $runner->run($this->processingLog, $input);
        $this->assertEnsembleInputCurrent($input);
        $composition = app(CutAwareEnsembleComposer::class)->compose($run['draws'], $transcript, $this->processingLog);
        $replayed = null;
        $answers = $this->ensembleRulings();

        if ($answers !== []) {
            $replayed = app(ServiceStructureEnsembleReplay::class)->replay($run['evidence'], $answers, $this->processingLog);
            $composition = new EnsembleComposition(
                ServiceStructure::fromArray($replayed['structure']),
                $replayed['disputes'],
                $replayed['provenance'],
                $replayed['degraded'],
                $composition->refused,
                $composition->validVotes,
                $replayed['degraded_reviewed'],
                $replayed['majority_decisions'],
            );
        }

        $result = $composition->refused
            ? new ValidationResult(
                $composition->structure,
                [['code' => 'insufficient_ensemble_votes', 'message' => 'Fewer than two validated ensemble draws.']],
            )
            : $validator->validate($composition->structure, $context);

        $runner->recordComposition($this->processingLog, $run['evidence']['attempt_id'], [
            'structure' => $composition->structure->toArray(),
            'validation_passed' => $result->passed(),
            'failure_codes' => $result->failureCodes(),
            'degraded' => $composition->degraded,
            'degraded_reviewed' => $composition->degradedReviewed,
            'disputes' => $composition->disputes,
            'majority_decisions' => $composition->majorityDecisions,
            'provenance' => $composition->provenance,
            'applied_rulings' => $replayed['applied_rulings'] ?? [],
            'stale_rulings' => $replayed['stale_rulings'] ?? [],
            'conflicting_rulings' => $replayed['conflicting_rulings'] ?? [],
        ]);
        $this->processingLog->refresh();

        return [$result, $transcript, $composition, $input];
    }

    /**
     * Compose the run's latest banked draws again, with no provider call, under the current rules
     * and every answer on the run. Refuses rather than draws when the request names an attempt
     * that is no longer the latest or the banked input no longer matches the run: the answers
     * were given on those draws and that input.
     *
     * @param  array{attempt_id: string}  $request
     * @return array{0: ValidationResult, 1: ChurchServiceTranscript, 2: EnsembleComposition, 3: array<string, mixed>}
     */
    private function recomposeBankedAttempt(
        array $request,
        ServiceStructureEnsembleRunner $runner,
        ServiceStructureValidator $validator,
    ): array {
        $this->processingLog->refresh();
        $bank = $this->processingLog->processing_metadata?->raw['service_structure_ensemble'] ?? null;
        $evidence = is_array($bank) && $bank !== [] ? end($bank) : null;

        if (! is_array($evidence) || ($evidence['attempt_id'] ?? null) !== $request['attempt_id']) {
            throw new \RuntimeException('The ensemble attempt asked to be recomposed is no longer this run\'s latest; re-detect the run instead.');
        }

        if (! app(EnsembleReviewGate::class)->inputIsCurrent($this->processingLog, $evidence)) {
            throw new \RuntimeException('The banked ensemble input no longer matches this run; re-detect the run instead.');
        }

        $replay = app(ServiceStructureEnsembleReplay::class);
        $input = $replay->snapshot($evidence);
        $context = $input['validation_context'] ?? null;

        if (! is_array($context)) {
            throw new \RuntimeException('The banked ensemble input has no validation context.');
        }

        $replayed = $replay->replay($evidence, $this->ensembleRulings(), $this->processingLog);
        $composition = new EnsembleComposition(
            ServiceStructure::fromArray($replayed['structure']),
            $replayed['disputes'],
            $replayed['provenance'],
            $replayed['degraded'],
            $replayed['refused'],
            $replayed['valid_votes'],
            $replayed['degraded_reviewed'],
            $replayed['majority_decisions'],
        );
        $result = $composition->refused
            ? new ValidationResult(
                $composition->structure,
                [['code' => 'insufficient_ensemble_votes', 'message' => 'Fewer than two validated ensemble draws.']],
            )
            : $validator->validate($composition->structure, ServiceStructureDrawExecutor::contextFromSnapshot($context));

        $runner->recordComposition($this->processingLog, $request['attempt_id'], [
            'structure' => $composition->structure->toArray(),
            'validation_passed' => $result->passed(),
            'failure_codes' => $result->failureCodes(),
            'degraded' => $composition->degraded,
            'degraded_reviewed' => $composition->degradedReviewed,
            'disputes' => $composition->disputes,
            'majority_decisions' => $composition->majorityDecisions,
            'provenance' => $composition->provenance,
            'applied_rulings' => $replayed['applied_rulings'] ?? [],
            'stale_rulings' => $replayed['stale_rulings'] ?? [],
            'conflicting_rulings' => $replayed['conflicting_rulings'] ?? [],
            'recomposed_at' => now()->toIso8601String(),
        ]);
        $this->processingLog->refresh();

        Log::info('Recomposed banked service structure draws without a provider call', [
            'processing_id' => $this->processingLog->processing_id,
            'attempt_id' => $request['attempt_id'],
            'open_questions' => count($composition->disputes),
        ]);

        return [$result, ChurchServiceTranscript::fromArray($input['transcript'] ?? null), $composition, $input];
    }

    /** @return array{attempt_id: string}|null */
    private function recomposeRequest(): ?array
    {
        $request = $this->processingLog->fresh()?->processing_metadata?->raw[self::RECOMPOSE_KEY] ?? null;

        if ($request === null) {
            return null;
        }

        if (! is_array($request) || ! is_string($request['attempt_id'] ?? null) || $request['attempt_id'] === '') {
            throw new \RuntimeException('Service structure recompose request is malformed.');
        }

        return ['attempt_id' => $request['attempt_id']];
    }

    private function withdrawRecomposeRequest(): void
    {
        if (($this->processingLog->fresh()?->processing_metadata?->raw[self::RECOMPOSE_KEY] ?? null) === null) {
            return;
        }

        $this->processingLog->writeProcessingMetadata(static function (array $metadata): array {
            unset($metadata[self::RECOMPOSE_KEY]);

            return $metadata;
        });
        $this->processingLog->refresh();
    }

    /** @return list<array<string, mixed>> */
    private function ensembleRulings(): array
    {
        $rulings = $this->processingLog->fresh()?->processing_metadata?->raw['service_structure_ensemble_rulings'] ?? [];

        if (! is_array($rulings) || ! array_is_list($rulings)) {
            throw new \RuntimeException('Service structure ensemble ruling history is malformed.');
        }

        $answers = [];

        foreach ($rulings as $ruling) {
            if (! is_array($ruling)) {
                throw new \RuntimeException('Service structure ensemble ruling history is malformed.');
            }

            $answers[] = $ruling;
        }

        return $answers;
    }

    /**
     * A fingerprint of every stored section row for this run, taken before the draws and
     * compared before writing, so an operator's edit made while the draws ran is not overwritten.
     */
    private function sectionRevision(): string
    {
        $rows = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('id')
            ->toBase()
            ->get();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function refuseConcurrentSectionChange(
        ValidationResult $result,
        ChurchServiceTranscript $transcript,
        SermonCandidateConfidenceService $sermonConfidenceService,
    ): void {
        $reasonMessage = 'Service sections changed while the structure draws were running; the operator\'s changes were kept and the ensemble proposal banked for review.';
        $evaluation = $sermonConfidenceService->evaluateForProcessingLog($this->processingLog);

        $this->persistFailedProposal($result, $transcript);
        $this->markProcessingRunForManualReview(
            $this->processingLog,
            'service_structure_sections_changed',
            $reasonMessage,
            $evaluation['speech_segments'],
        );
        $this->notifyManualReviewRequired($reasonMessage, $evaluation['speech_segments']);
        $this->chained = [];
        $this->logStepFailed(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $reasonMessage);
    }

    /** @param  array<string, mixed>  $input */
    private function assertEnsembleInputCurrent(array $input): void
    {
        $this->processingLog->refresh();
        if (! app(EnsembleReviewGate::class)->snapshotIsCurrent($this->processingLog, $input)) {
            throw new \RuntimeException('Service structure ensemble input changed while draws were running.');
        }
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
     * When the passing structure has no auto-extractable sermon section (none
     * present, or one below the high-confidence threshold), this method returns
     * without touching the fields: the RMS baseline stays in place so
     * SermonExtractionPlanResolver still resolves a plan and ExtractSermon's
     * confidence guard can route the run to manual review rather than throwing.
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
        if (app(ProcessingNotificationRouter::class)->suppressIfHistoric(
            $this->processingLog,
            'manual_review_structure',
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
        $audioTimeline = $this->loadAudioTimeline();
        $oosItems = $this->loadOosItems();

        $structure = $detector->detect(
            $transcript,
            $this->oosItemPayloads($oosItems),
            $this->processingLog->processing_id,
            $feedback,
            $audioTimeline,
        );

        $structure = $this->snapToSilences($structure, $snapService, $transcript, $audioTimeline);

        $result = $validator->validate($structure, ValidationContext::for(
            $transcript,
            $oosItems,
            ValidationContext::recordingOmitsSongs($this->processingLog->processing_metadata),
        ));

        return [$result, $transcript];
    }

    private function loadTranscript(): ChurchServiceTranscript
    {
        $transcriptPath = $this->processingLog->serviceTranscriptPath();

        if ($transcriptPath === null) {
            throw new \RuntimeException('No full-service transcript recorded for this run; TranscribeFullService must run first.');
        }

        $artifactDisk = ServiceArtifactDisk::for($transcriptPath);

        if (! Storage::disk($artifactDisk)->exists($transcriptPath)) {
            throw new \RuntimeException("Full-service transcript artifact missing: {$transcriptPath}");
        }

        $transcript = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk($artifactDisk)->get($transcriptPath), true)
        );

        if ($transcript->isEmpty()) {
            throw new \RuntimeException('Stored full-service transcript contains no cues.');
        }

        return $transcript;
    }

    /**
     * The run's music/speech timeline, which every run that reaches detection has
     * ({@see ClassifyServiceAudio}). Refused before the detector is paid for: without it, sung
     * audio Whisper cannot hear is an empty gap the detector guesses at.
     */
    private function loadAudioTimeline(): AudioTimeline
    {
        $path = $this->processingLog->audio_timeline_path;

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('No audio timeline recorded for this run; ClassifyServiceAudio must run first.');
        }

        $artifactDisk = ServiceArtifactDisk::for($path);

        if (! Storage::disk($artifactDisk)->exists($path)) {
            throw new \RuntimeException("Audio timeline artifact missing: {$path}");
        }

        try {
            return AudioTimeline::fromJson((string) Storage::disk($artifactDisk)->get($path));
        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException("Audio timeline artifact is unreadable ({$path}): ".$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return list<ChurchServiceItem>
     */
    private function loadOosItems(): array
    {
        return app(ServiceStructureEnsembleInput::class)->oosItems($this->resolveChurchService());
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
     * @return list<array{id: int, position: int, type: string, title: ?string, song_id: ?int}>
     */
    private function oosItemPayloads(array $items): array
    {
        return app(ServiceStructureEnsembleInput::class)->oosItemPayloads($items);
    }

    private function snapToSilences(
        ServiceStructure $structure,
        SilenceSnapService $snapService,
        ChurchServiceTranscript $transcript,
        AudioTimeline $audioTimeline,
    ): ServiceStructure {
        $rmsLogPath = $this->processingLog->rms_log_path;

        if (! is_string($rmsLogPath) || $rmsLogPath === '') {
            return $structure;
        }

        $artifactDisk = ServiceArtifactDisk::for($rmsLogPath);

        if (! Storage::disk($artifactDisk)->exists($rmsLogPath)) {
            return $structure;
        }

        $rmsLogContent = (string) Storage::disk($artifactDisk)->get($rmsLogPath);

        return app(SoundStage::class)->apply(
            $snapService->snap($structure, $rmsLogContent),
            $rmsLogContent,
            $transcript,
            ValidationContext::recordingOmitsSongs($this->processingLog->processing_metadata),
            $audioTimeline,
        );
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
        $boundModel = (string) config('media-processing.service_structure.model', 'gpt-5.6-sol');
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
                $boundResult->structure->summary,
                $boundResult->structure->notices,
                $boundResult->structure->chapterMarkers,
                $boundResult->structure->sermonAbsence,
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
    /**
     * Stop, without retrying, when the hold guard refused the replacement.
     *
     * The refusal is deterministic: the sections were rejected because an
     * operator's content hold had nothing of its type to land on, and nothing
     * about that changes on a second attempt. Letting the exception escape put
     * the job back on the queue, so run 1314 paid for three detections in
     * thirteen minutes — and each retry was a fresh sample that could have
     * re-created the very span the hold was placed against, satisfying the
     * guard by regenerating the defect. The proposal is recorded first so the
     * refused replacement can be inspected, then the run parks for the operator,
     * who either releases the hold or re-holds it against the new content.
     *
     * @param  array<int, array<string, mixed>>  $classified
     */
    private function refuseUnplacedContentHold(
        UnplacedContentHoldException $exception,
        ValidationResult $result,
        array $classified,
    ): void {
        $sectionIds = $exception->unplacedSectionIds();

        $this->persistRefusedProposal($exception, $result, $classified);

        if ($this->reconcile) {
            // A completed run keeps its sections: a reconcile re-detection that
            // cannot carry a hold must not re-open it.
            $reasonMessage = 'Reconcile re-detection refused: '.$exception->getMessage();
            $this->logStepSkipped(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $reasonMessage);

            Log::warning('Service structure reconcile re-detection left a content hold unplaced; existing sections retained', [
                'processing_id' => $this->processingLog->processing_id,
                'unplaced_section_ids' => $sectionIds,
            ]);

            return;
        }

        $this->markProcessingRunForManualReview(
            $this->processingLog,
            'unplaced_content_hold',
            $exception->getMessage()
        );
        $this->processingLog->refresh();

        $this->notifyManualReviewRequired($exception->getMessage(), []);

        // No speech segments are offered and the chain stops: confirming a span
        // here would be the same guess the hold already rejected.
        $this->chained = [];

        $this->logStepFailed(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $exception->getMessage());

        Log::warning('Service structure detection refused: a content hold had no section to carry it', [
            'processing_id' => $this->processingLog->processing_id,
            'unplaced_section_ids' => $sectionIds,
        ]);
    }

    /**
     * Record the replacement the hold guard refused, so it can be inspected.
     *
     * It shares `service_structure_proposal` with a validation-rejected
     * proposal: both describe a revision that was never applied, and the key is
     * already classified as local review state by the portable-inventory
     * serializer, which fails closed on any proposal key nobody has listed.
     * `refused_reason` tells the two apart — this one passed validation.
     *
     * @param  array<int, array<string, mixed>>  $classified
     */
    private function persistRefusedProposal(
        UnplacedContentHoldException $exception,
        ValidationResult $result,
        array $classified,
    ): void {
        try {
            $this->putStructureMetadata('service_structure_proposal', $this->proposalPayload($result, $classified) + [
                'refused_reason' => 'unplaced_content_hold',
                'unplaced_content_hold_section_ids' => $exception->unplacedSectionIds(),
                'unplaced_content_holds' => $exception->unplacedContent,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to persist refused service structure proposal, continuing to manual review', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

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
            'summary' => $result->structure->summary,
            'notices' => $result->structure->notices,
            'chapter_markers' => $result->structure->chapterMarkers,
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
