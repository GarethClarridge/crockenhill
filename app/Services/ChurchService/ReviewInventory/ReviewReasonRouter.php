<?php

declare(strict_types=1);

namespace App\Services\ChurchService\ReviewInventory;

use App\Enums\ContentHoldCheck;
use App\Enums\ReviewReasonDisposition;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Routes one actionable review reason (video plan §4.1d RA1): whether it is already resolved,
 * resolved once built work is applied, repairable by an existing automatic stage, reserved for a
 * person by a ruling, or blocked on a named missing input. Human reservations are never routed
 * away by a pending fix; a flag the review policy no longer forces is resolved by current evidence.
 */
final class ReviewReasonRouter
{
    /** Reasons a ruling reserves for a person, and the ruling. */
    private const HUMAN_REASONS = [
        'talk_type_review' => 'T1/T2: talk-type confirmation is reserved and access-sensitive (RA6 prepares proposals)',
        'speaker_review' => 'P3: historic speaker identification is disabled; unknown stays honest (RA6 audits the banked prediction)',
        'pending_approval' => 'programme §8: publication approval is a separate act, not processing review',
    ];

    /** Review flags a ruling reserves for a person. */
    private const HUMAN_FLAGS = [
        'talk_speaker_review' => 'P3: historic speaker identification is disabled',
        'structure_sermon_interruption_merged' => 'unresolved interruption questions stay with the operator (RA6 boundary)',
    ];

    /**
     * Refreshed by RA2 only when the analysis that wrote the published reference is owed:
     * re-running a current analysis settles nothing (963 and 1066, 11-07).
     */
    private const REFERENCE_CONTRADICTION = 'published_reference_contradicts_sermon';

    /**
     * Review flags an existing automatic stage owns: [stage, next operation].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FLAG_ROUTES = [
        'sermon_text_predates_evidence' => ['RA2', 're-slice sermon text from the current transcript and executed spans'],
        'sermon_evidence_incomplete' => ['RA2', 'complete the sermon evidence, then re-slice'],
        'transcript_repetition_suspect' => ['RA4', 'characterise repetition; padded-window recovery decode'],
        'unmatched_song_section' => ['RA4', 'song identity from admitted catalogue/lyrics evidence'],
        'song_identity_single_source' => ['RA4', 'seek independent identity corroboration'],
        'song_identity_unverified_from_suspect_transcript' => ['RA4', 'identity after transcript recovery'],
        'song_title_marker_mismatch' => ['RA4', 'reconcile the title marker against sung content'],
        'structure_song_swallows_speech' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_song_over_dead_feed' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_song_widened_into_music' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_song_widened_to_sustained_sound' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_unidentified_singing' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_section_reads_as_sung' => ['RA3', 'song boundary repair and post-cut verification'],
        'structure_low_confidence' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
        'structure_micro_section' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
        'structure_macro_section' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
        'structure_oos_same_type_inversion' => ['RA5', 'order question from evidence; compare candidate cuts'],
        'structure_oos_type_unresolved' => ['RA5', 'order question from evidence; compare candidate cuts'],
        'structure_oos_shared_item' => ['RA5', 'order question from evidence; compare candidate cuts'],
        'oos_structure_mismatch' => ['RA5', 'order question from evidence; compare candidate cuts'],
        'structure_ensemble_disagrees' => ['RA5', 'resolve disagreement from cited source evidence'],
        'structure_ensemble_degraded' => ['RA5', 'resolve disagreement from cited source evidence'],
        'structure_missing_preached_reading' => ['RA5', 'supported current reference settles it under D3'],
        'structure_talk_audio_dropout' => ['RA5', 'measure the dropout against source audio'],
        'structure_talk_interrupted' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
        'structure_talk_fragment' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
        'structure_untranscribed_speech_before_section' => ['RA5', 'K20 introduction completeness'],
        'structure_sermon_adjacent_speech_unowned' => ['RA5', 'K20 introduction completeness'],
        'structure_sermon_boundary_material_risk' => ['RA5', 'K21/E27 boundary evidence'],
        'structure_sermon_contains_sung_span' => ['RA5', 'K21/E27 boundary evidence'],
        'sermon_composition_review_required' => ['RA5', 'recompose the sermon from current evidence'],
        'sermon_audio_loudness_missed' => ['sound treatment', 'existing sound-treatment repair and measurement'],
        'sermon_audio_speech_near_noise' => ['sound treatment', 'existing sound-treatment repair and measurement'],
        'song_publication_hold' => ['RA3', 'song boundary repair (the publication review holds the manual-review flag)'],
    ];

    /** Stages whose evidence is the cut or structure itself, so applying a fix that changes it re-decides them. */
    private const CUT_DEPENDENT_STAGES = ['RA2', 'RA3', 'RA5', 'sound treatment'];

    /**
     * @param  string  $reason  a key from ServiceReviewDashboardQuery::reviewReasons(), or 'song_without_catalogue_item'
     * @param  string|null  $detail  the review flag, song-review kind or hold check behind the reason
     * @return array{disposition: ReviewReasonDisposition, stage: string|null, next_operation: string, ruling: string|null, missing_input: string|null, fix: string|null}
     */
    public function route(ServiceSection $section, string $reason, ?string $detail, PendingApplication $pending): array
    {
        if (isset(self::HUMAN_REASONS[$reason])) {
            return $this->human(self::HUMAN_REASONS[$reason], $reason === 'pending_approval' ? 'publication approval' : 'RA6');
        }

        [$stage, $operation] = match ($reason) {
            'needs_manual_review' => $this->flagRoute($section, $detail),
            'song_publication_review' => ['RA3', 'song boundary repair and post-cut verification'],
            'sermon_boundary_review' => ['RA5', 'K21/E27 boundary evidence'],
            'low_confidence' => ['RA5', 'gather bounded structure evidence; compare candidate cuts'],
            'inferred_song_label', 'unmatched_song', 'song_without_catalogue_item' => ['RA4', 'song identity from admitted catalogue/lyrics evidence'],
            default => [null, 'unclassified review reason: add a route'],
        };

        if ($stage === 'human') {
            return $this->human($operation, 'RA6');
        }
        if ($stage === 'resolved') {
            return $this->result(ReviewReasonDisposition::ResolvedByCurrentEvidence, null, $operation);
        }
        if ($stage === null) {
            return $this->result(ReviewReasonDisposition::Unassessable, null, $operation, missingInput: 'a route for this reason');
        }

        $cutDependent = in_array($stage, self::CUT_DEPENDENT_STAGES, true);
        $fix = $cutDependent ? $pending->fixFor($section) : null;
        if ($fix !== null) {
            return $this->result(ReviewReasonDisposition::ResolvedByPendingApplication, $stage, 're-evaluate after application, then '.$operation, fix: $fix);
        }
        $missing = in_array($stage, ['RA3', 'RA5'], true) ? $pending->missingInputFor($section) : null;
        if ($missing !== null) {
            return $this->result(ReviewReasonDisposition::Unassessable, $stage, $operation, missingInput: $missing);
        }

        return $this->result(ReviewReasonDisposition::AutomaticRepair, $stage, $operation);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function flagRoute(ServiceSection $section, ?string $flag): array
    {
        if ($flag === null) {
            return ['resolved', 'no review flag forces review: recompute the stored manual-review flag'];
        }
        if (str_starts_with($flag, 'content_hold:')) {
            $check = ContentHoldCheck::tryFrom(substr($flag, strlen('content_hold:')));

            return $check?->isRecheckable() === true
                ? ['hold recheck', 're-run the recorded '.$check->value.' check (ContentHoldRechecker) once its evidence changes']
                : ['human', 'D5: a hold found by '.($check->value ?? 'an unrecorded check').' stays until someone re-decides it'];
        }
        if (isset(self::HUMAN_FLAGS[$flag])) {
            return ['human', self::HUMAN_FLAGS[$flag]];
        }
        if ($flag === self::REFERENCE_CONTRADICTION) {
            return $section->processingLog->analysisIsOwed()
                ? ['RA2', 'refresh the dependent Scripture/analysis from the current text']
                : ['human', 'published vs heard reference is editorial while the analysis is current (10-18 repairs were operator-approved through SaveSermonDetails)'];
        }
        $metadata = $section->metadata?->toArray() ?? [];
        $reference = is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null;
        if ($flag !== 'song_publication_hold' && ! SectionReviewFlagPolicy::requiresManualReview($section->section_type, [$flag], $reference)) {
            return ['resolved', 'the review policy no longer forces review for this flag on this section'];
        }

        return self::FLAG_ROUTES[$flag] ?? [null, 'unclassified review flag: add a route'];
    }

    /** @return array{disposition: ReviewReasonDisposition, stage: string|null, next_operation: string, ruling: string|null, missing_input: string|null, fix: string|null} */
    private function human(string $ruling, string $stage): array
    {
        return $this->result(ReviewReasonDisposition::HumanDecision, $stage, 'operator decision', ruling: $ruling);
    }

    /** @return array{disposition: ReviewReasonDisposition, stage: string|null, next_operation: string, ruling: string|null, missing_input: string|null, fix: string|null} */
    private function result(ReviewReasonDisposition $disposition, ?string $stage, string $operation, ?string $ruling = null, ?string $missingInput = null, ?string $fix = null): array
    {
        return ['disposition' => $disposition, 'stage' => $stage, 'next_operation' => $operation, 'ruling' => $ruling, 'missing_input' => $missingInput, 'fix' => $fix];
    }
}
