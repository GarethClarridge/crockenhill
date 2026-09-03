<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Re-derives the service structure of a historic run stranded at manual sermon
 * review because its stored structure predates the sermon-absence schema.
 *
 * D1 (2026-09-03) gave the projection a structured way to assert that a service
 * genuinely held no sermon, and made ExtractSermon stand down when it sees one.
 * Structures projected *before* that change have no such slot: the detector's
 * finding landed in free-text `notes`, where nothing reads it, and the run
 * failed on `candidate_exceeds_maximum_duration` — RMS speech duration being
 * asked a question only the structure can answer.
 *
 * Retrying such a run does not help. The phase registry resumes it at
 * `extract_sermon`, which re-reads the same assertion-less structure and fails
 * identically. The only remedy is to re-run detection so the projection answers
 * under the current schema, which this action does by re-pointing the run at
 * `detect_service_structure` before handing it back to the ordinary retry path.
 *
 * Deliberately narrow. It refuses a run that already carries an assertion
 * (nothing to re-derive), one whose structure already names a sermon section
 * (that is {@see ReconcileStaleSermonReview}'s case, not this one), and one held
 * for any reason other than sermon selection. Re-detection costs a provider call
 * and replaces the projected sections, and detection is not deterministic —
 * run #935 misread a whole service that the very next run read correctly — so
 * the operator confirms the result afterwards rather than this action trusting
 * it.
 */
class RedetectHistoricServiceStructure
{
    /**
     * Reason codes whose remedy is re-deriving the structure rather than
     * re-asking the segment-duration heuristic.
     */
    private const REDERIVABLE_REASON_CODES = [
        'candidate_exceeds_maximum_duration',
    ];

    public function __construct(
        private readonly MediaProcessingRunTransitionService $transitions,
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{eligible: bool, reason: string}
     */
    public function inspect(MediaProcessingLog $log): array
    {
        if ($log->historic_import_operation_id === null) {
            return ['eligible' => false, 'reason' => 'not a historic import run'];
        }

        if ($log->isRetired()) {
            return ['eligible' => false, 'reason' => 'run is retired'];
        }

        if ($log->status !== ProcessingStatus::Failed) {
            return ['eligible' => false, 'reason' => 'run is '.$log->status->value.', not failed'];
        }

        if ($log->current_step !== 'manual_review_required') {
            return ['eligible' => false, 'reason' => 'run is not held at manual_review_required'];
        }

        $reasonCode = $log->manualReviewMetadata()['reason_code'] ?? null;

        if (! in_array($reasonCode, self::REDERIVABLE_REASON_CODES, true)) {
            return ['eligible' => false, 'reason' => 'review reason '.var_export($reasonCode, true).' is not re-derivable'];
        }

        if ($log->assertedSermonAbsence() instanceof ServiceSermonAbsence) {
            return ['eligible' => false, 'reason' => 'structure already asserts sermon absence'];
        }

        $structure = data_get($log->processing_metadata?->toArray() ?? [], 'service_structure');

        if (! is_array($structure)) {
            return ['eligible' => false, 'reason' => 'run has no stored service structure'];
        }

        if (ServiceStructure::fromArray($structure)->sectionsOfType(ServiceSectionType::Sermon) !== []) {
            return ['eligible' => false, 'reason' => 'structure already names a sermon section'];
        }

        return ['eligible' => true, 'reason' => 'structure predates the sermon-absence schema'];
    }

    /**
     * @return array{outcome: 'dispatched'|'skipped', reason: string}
     */
    public function execute(MediaProcessingLog $log, bool $execute): array
    {
        $inspection = $this->inspect($log);

        if (! $inspection['eligible']) {
            return ['outcome' => 'skipped', 'reason' => $inspection['reason']];
        }

        if (! $execute) {
            return ['outcome' => 'dispatched', 'reason' => $inspection['reason']];
        }

        $this->transitions->updateStep($log, 'detect_service_structure');
        $log->refresh();

        Log::info('Re-deriving historic service structure under the sermon-absence schema', [
            'processing_id' => $log->processing_id,
            'church_service_id' => $log->church_service_id,
            'historic_import_operation_id' => $log->historic_import_operation_id,
        ]);

        $result = $this->orchestrator->retry($log);

        if (! $result->success) {
            return ['outcome' => 'skipped', 'reason' => 'retry refused: '.$result->message];
        }

        return ['outcome' => 'dispatched', 'reason' => $inspection['reason']];
    }
}
