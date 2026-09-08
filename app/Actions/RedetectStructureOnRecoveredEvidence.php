<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Re-derive the service structure of a settled run whose transcript was
 * corrected after that structure was projected.
 *
 * Structure detection reads the full-service transcript. Where part of that
 * transcript was unobservable, the detector drew the structure over a void, and
 * it did so in one of two shapes: a low-confidence placeholder spanning the
 * blind region (run 1278's `other` section, confidence 0.20, covering a
 * 1,439-second window), or **no section at all** (run 1127, whose structure
 * simply has a 2,068-second hole). The second shape is invisible to any check
 * that looks for a suspicious section, because there is no section to find.
 *
 * Once {@see \App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay} puts
 * the speech back, those boundaries describe evidence the run no longer holds.
 *
 * **This is not a cheap operation and it is not reversible by itself.** It
 * re-opens a completed run, re-cuts its sermon media, and re-derives its
 * analysis; the run leaves `completed` while the chain runs, so an interrupted
 * chain leaves a finished run in a failed state. The structure it replaces is
 * therefore snapshotted first, on the run, before anything is dispatched.
 *
 * A **failed** run is eligible on the same evidence and carries less risk: it
 * has no completed state to lose and nothing published to re-cut. Its structure
 * is still worth re-deriving, because a run can fail *downstream* of detection
 * on a verdict the void produced — run #1035 failed at extraction for want of a
 * 20-minute speech block, having projected one prayer from a 22-word transcript.
 * The ordinary retry resumes at the failing phase and never revisits detection.
 *
 * Deliberately does not select runs itself. Detection is not deterministic —
 * run #935 misread a whole service that the very next run read correctly — so
 * re-deriving a structure that happens to be right can make it worse. Each run
 * is named by an operator who has looked at it.
 *
 * Delete once every run whose transcript the recovery replay corrected has
 * either been re-derived or accepted as it stands.
 */
class RedetectStructureOnRecoveredEvidence
{
    public const SNAPSHOT_KEY = 'structure_redetection_snapshot';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{eligible: bool, reason: string}
     */
    public function inspect(MediaProcessingLog $log): array
    {
        if ($log->transcriptRecoveryReplay() === null) {
            return ['eligible' => false, 'reason' => 'transcript was never corrected by a recovery replay'];
        }

        $snapshot = $this->existingSnapshot($log);

        if ($snapshot !== null) {
            return ['eligible' => false, 'reason' => 'already re-derived at '.($snapshot['taken_at'] ?? 'an unrecorded time')];
        }

        $refusal = $this->orchestrator->structureRedetectionRefusal($log);

        if ($refusal !== null) {
            return ['eligible' => false, 'reason' => $refusal['message']];
        }

        return ['eligible' => true, 'reason' => 'structure was projected from a transcript that has since been corrected'];
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

        // Written before anything is dispatched: once the chain starts, the
        // sections it replaces are gone, and "the old structure is in the logs
        // somewhere" is not a rollback.
        $this->snapshot($log);

        $result = $this->orchestrator->redetectServiceStructure($log);

        if (! $result->success) {
            $this->clearSnapshot($log);

            return ['outcome' => 'skipped', 'reason' => 'refused: '.$result->message];
        }

        Log::info('Dispatched service structure re-detection on recovered evidence', [
            'processing_id' => $log->processing_id,
            'sermon_id' => $log->sermon_id,
        ]);

        return ['outcome' => 'dispatched', 'reason' => $inspection['reason']];
    }

    /**
     * Record the structure being replaced, and the sections derived from it.
     *
     * Sections are stored whole rather than by id: the chain deletes and
     * recreates them, so an id-only record would point at nothing.
     */
    private function snapshot(MediaProcessingLog $log): void
    {
        $sections = $log->serviceSections()->orderBy('start_time')->get()->map(static fn ($section): array => [
            'id' => $section->id,
            'section_type' => $section->section_type->value,
            'start_time' => $section->start_time,
            'end_time' => $section->end_time,
            'title' => $section->title,
            'needs_manual_review' => $section->needs_manual_review,
            'publication_status' => $section->publication_status->value,
            'published_at' => $section->published_at?->toIso8601String(),
            'metadata' => $section->metadata?->toArray(),
        ])->all();

        $log->putStructureRedetectionSnapshot([
            'taken_at' => now()->toIso8601String(),
            'service_structure' => $log->processing_metadata?->toArray()['service_structure'] ?? null,
            'sermon_extraction_plan' => $log->processing_metadata?->toArray()['sermon_extraction_plan'] ?? null,
            'sermon_start_time' => $log->sermon_start_time,
            'sermon_end_time' => $log->sermon_end_time,
            'sections' => $sections,
        ]);
    }

    private function clearSnapshot(MediaProcessingLog $log): void
    {
        $log->putStructureRedetectionSnapshot(null);
    }

    /** @return array<string, mixed>|null */
    private function existingSnapshot(MediaProcessingLog $log): ?array
    {
        $snapshot = ($log->processing_metadata?->toArray() ?? [])[self::SNAPSHOT_KEY] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }
}
