<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Data\ServiceStructure;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Support\RetiredSectionReviewFlags;
use App\Support\SectionReviewFlagPolicy;

/**
 * Re-derives the structure review flags a run banked, by re-running the validator's
 * soft-flag annotation over the structure already stored on the run.
 *
 * The gap this closes: `services:recompute-section-review-flags` re-reads each section's
 * *stored* flags and re-applies {@see SectionReviewFlagPolicy} to decide
 * `needs_manual_review`. It can re-weigh a flag but never withdraw one, so every validator
 * improvement reached future runs only. Fourteen sections were held on 2026-09-03 by flags
 * with no live reason to hold them: four by a flag with no raise site left in the codebase
 * at all {@see RetiredSectionReviewFlags}, and ten by a live flag whose specific cause — a
 * deleted heuristic classification mode — {@see self::isHeuristicAudioOnlySongFossil()} no
 * longer applies.
 *
 * Nothing here calls a provider. The structure is banked, the rules are deterministic, and
 * the answer for a completed run is a pure function of the two — the same shape as
 * `historic-import:redetect-structure`, one layer lower.
 */
class SectionStructureFlagRederiver
{
    /**
     * Rows and structure sections are written from one another, so they agree exactly; the
     * tolerance exists only for the float round-trip through JSON and the database.
     */
    private const TIMING_TOLERANCE_SECONDS = 0.5;

    /**
     * `review_reason` sub-labels {@see self::isHeuristicAudioOnlySongFossil()} clears
     * alongside the flag they explain. Neither ever named a flag by its own value, so
     * neither can fall out of the removed-flags diff the way `song_title_marker_mismatch`
     * or a {@see RetiredSectionReviewFlags} entry does.
     *
     * @var array<int, string>
     */
    private const HEURISTIC_AUDIO_ONLY_SONG_REASONS = [
        'audio_only_song_segment',
        'possible_musical_intro',
    ];

    public function __construct(private ServiceStructureValidator $validator) {}

    /**
     * The changes this run's sections need.
     *
     * A run this pass cannot re-derive is not passed over: its retired flags are still
     * withdrawn, because a flag no code can raise is dead whatever the run banked. The
     * accompanying note records what could not be re-derived, so a partial answer stays
     * legible as one.
     */
    public function rederive(MediaProcessingLog $processingLog): SectionFlagRederivation
    {
        /** @var list<ServiceSection> $rows */
        $rows = $processingLog->serviceSections()
            ->orderBy('section_order')
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return new SectionFlagRederivation;
        }

        $payload = $this->storedStructure($processingLog);

        if ($payload === null) {
            return new SectionFlagRederivation($this->retirementsOnly($rows));
        }

        $structure = ServiceStructure::fromArray($payload);
        $misalignment = $this->misalignment($structure, $rows);

        if ($misalignment !== null) {
            return new SectionFlagRederivation($this->retirementsOnly($rows), $misalignment);
        }

        $rederivable = $this->rederivableFlags($processingLog);
        $annotated = $this->validator->reannotate($structure, $this->contextFor($processingLog));

        $changes = [];

        foreach ($annotated->sections as $index => $section) {
            $change = $this->changeFor($rows[$index], $section->reviewFlags, $rederivable);

            if ($change instanceof SectionFlagChange) {
                $changes[] = $change;
            }
        }

        return new SectionFlagRederivation($changes);
    }

    /**
     * The changes available without a structure to re-derive from: retired flags withdrawn,
     * everything else left exactly as it stands.
     *
     * The three sections still held on 2026-09-03 by `reading_reference_conflict` are all
     * heuristic-era runs that banked no structure at all — the same commit deleted both the
     * flag's raiser and the pipeline that produced those runs — so a pass that only visited
     * runs with a banked structure would never have reached them. So are the ten sections
     * {@see self::isHeuristicAudioOnlySongFossil()} releases, for the same reason under a
     * different name.
     *
     * @param  list<ServiceSection>  $rows
     * @return list<SectionFlagChange>
     */
    private function retirementsOnly(array $rows): array
    {
        $changes = [];

        foreach ($rows as $row) {
            $change = $this->changeFor($row, [], []);

            if ($change instanceof SectionFlagChange) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * The change one section needs, or null when it already agrees with the current rules.
     *
     * Only flags in the re-derivable set move, plus the retired ones
     * {@see RetiredSectionReviewFlags}, which are withdrawn outright — a flag no code can
     * raise any more is one the current rules do not raise, which is the same question this
     * pass is asking. Everything else the section carries — the children's-talk speaker
     * question, an alignment mismatch — is copied across untouched, because this pass holds
     * no evidence about any of it.
     *
     * @param  list<string>  $rederivedFlags
     * @param  list<string>  $rederivable
     */
    private function changeFor(ServiceSection $row, array $rederivedFlags, array $rederivable): ?SectionFlagChange
    {
        $metadata = $row->metadata?->toArray() ?? [];

        $storedFlags = is_array($metadata['review_flags'] ?? null)
            ? array_values(array_filter($metadata['review_flags'], 'is_string'))
            : [];

        $retained = array_values(array_filter(
            $storedFlags,
            fn (string $flag): bool => ! in_array($flag, $rederivable, true)
                && ! RetiredSectionReviewFlags::isRetired($flag)
                && ! $this->isHeuristicAudioOnlySongFossil($flag, $metadata),
        ));

        $rederived = array_values(array_filter(
            $rederivedFlags,
            static fn (string $flag): bool => in_array($flag, $rederivable, true),
        ));

        $flags = array_values(array_unique([...$retained, ...$rederived]));

        $added = array_values(array_diff($flags, $storedFlags));
        $removed = array_values(array_diff($storedFlags, $flags));

        // The boolean is re-derived only where a flag actually moved. Re-deriving it from
        // flags this pass did not touch would be a second copy of a rule another pass owns,
        // and it does not agree: two `other` sections retyped from spoken song announcements
        // still carry `song_alignment_inferred`, which the policy reads as review-worthy
        // although the retype deliberately cleared them. Correcting that is the retype's job.
        if ($added === [] && $removed === []) {
            return null;
        }

        $needsManualReview = SectionReviewFlagPolicy::requiresManualReview(
            $row->section_type,
            $flags,
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        $metadata['review_flags'] = $flags;

        // A review reason naming a flag that no longer applies is a label for a question
        // nobody is being asked any more. audio_only_song_segment and possible_musical_intro
        // never did name a flag literally — they are sub-labels of unmatched_song_section, not
        // flags in their own right — so they are named explicitly rather than relying on the
        // removed-flags diff, and only when this section's own fossil predicate held.
        $reviewReason = is_string($metadata['review_reason'] ?? null) ? $metadata['review_reason'] : null;

        $isFossilSongReason = $reviewReason !== null
            && in_array($reviewReason, self::HEURISTIC_AUDIO_ONLY_SONG_REASONS, true)
            && $this->isHeuristicAudioOnlySongFossil('unmatched_song_section', $metadata);

        if ($reviewReason !== null && (in_array($reviewReason, $removed, true) || $isFossilSongReason)) {
            unset($metadata['review_reason']);
        }

        return new SectionFlagChange($row, $added, $removed, [
            'needs_manual_review' => $needsManualReview,
            'metadata' => ServiceSectionMetadata::fromArray($metadata),
        ]);
    }

    /**
     * `unmatched_song_section` on a song the deleted heuristic `audio_only` classifier
     * produced (`SpeechSectionClassificationService` and its siblings, removed `9c1410f91`,
     * 2026-07-20) is a fossil in every sense that matters even though the flag itself is
     * still live for the current `llm_structure` pipeline (§361, "Happy Birthday", is a real,
     * current, still-open case and must not be touched by this).
     *
     * The distinction from {@see RetiredSectionReviewFlags} is that `unmatched_song_section`
     * itself is not dead code — only these ten sections' *reason* for carrying it is, so the
     * predicate is conditioned on the section's own `classification_mode`, not asserted for
     * the flag name globally.
     *
     * Three facts made this a release rather than a reduction:
     *
     *  - `classification_mode: audio_only` is written nowhere in the current codebase
     *    (confirmed by search); it is exclusively heuristic-era data.
     *  - {@see \App\Actions\ServiceReview\ConfirmServiceSection} is the only review action
     *    available for an unmatched song, and it dismisses the review without ever writing an
     *    identity — there is no path today by which a reviewer's listening becomes data.
     *  - {@see \App\Services\ChurchService\SectionPublication\SongPublicationHandler::isEligible()}
     *    and {@see \App\Services\Public\PublicServiceContentEligibility::applySongItemEligibility()}
     *    both already exclude an Unmatched section from publication and the public archive
     *    regardless of this flag, so nothing downstream reads the boolean either.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isHeuristicAudioOnlySongFossil(string $flag, array $metadata): bool
    {
        return $flag === 'unmatched_song_section'
            && ($metadata['classification_mode'] ?? null) === 'audio_only';
    }

    /**
     * Which flags this run has the evidence to re-derive.
     *
     * The benediction flag is geometry against the end of the recording, so a run with no
     * recorded duration cannot re-derive it — and must therefore not withdraw it either.
     * Guessing the duration from the last section's end would fabricate the very measurement
     * the flag is made of.
     *
     * @return list<string>
     */
    private function rederivableFlags(MediaProcessingLog $processingLog): array
    {
        if ($this->recordingDuration($processingLog) > 0.0) {
            return ServiceStructureValidator::REANNOTATED_FLAGS;
        }

        return array_values(array_filter(
            ServiceStructureValidator::REANNOTATED_FLAGS,
            static fn (string $flag): bool => $flag !== ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
        ));
    }

    private function contextFor(MediaProcessingLog $processingLog): ValidationContext
    {
        $oosItemTypes = [];
        $oosItemPositions = [];
        $oosItemRawTypes = [];

        foreach ($this->oosItems($processingLog) as $item) {
            $oosItemTypes[(int) $item->id] = $item->semanticSectionType();
            $oosItemPositions[(int) $item->id] = (int) $item->position;
            $oosItemRawTypes[(int) $item->id] = strtolower((string) $item->type);
        }

        return new ValidationContext(
            recordingDuration: $this->recordingDuration($processingLog),
            // Coverage is a hard check and reannotate() does not run one, so the speech
            // measurements a completed run no longer holds are not needed.
            speechDuration: 0.0,
            oosItemTypes: $oosItemTypes,
            oosItemPositions: $oosItemPositions,
            oosItemRawTypes: $oosItemRawTypes,
            recordingOmitsSongs: ValidationContext::recordingOmitsSongs($processingLog->processing_metadata),
        );
    }

    /**
     * @return list<ChurchServiceItem>
     */
    private function oosItems(MediaProcessingLog $processingLog): array
    {
        $churchServiceId = $processingLog->church_service_id;

        if (! is_int($churchServiceId)) {
            return [];
        }

        $churchService = ChurchService::query()->find($churchServiceId);

        if (! $churchService instanceof ChurchService) {
            return [];
        }

        return array_values($churchService->items()->orderBy('position')->orderBy('id')->get()->all());
    }

    private function recordingDuration(MediaProcessingLog $processingLog): float
    {
        return max(0.0, (float) ($processingLog->duration ?? 0.0));
    }

    /**
     * Why the banked structure cannot be trusted to describe these rows, or null when it can.
     *
     * `toClassifiedSections()` writes one row per structure section in structure order with
     * the section's own timings, so agreement on both is what proves the two are still the
     * same reading. All 48 live runs agreed exactly when this was built; a run that stops
     * agreeing has been edited by something else and is not this pass's to re-derive.
     *
     * @param  list<ServiceSection>  $rows
     */
    private function misalignment(ServiceStructure $structure, array $rows): ?string
    {
        if (count($structure->sections) !== count($rows)) {
            return sprintf(
                'structure has %d sections but the run has %d persisted',
                count($structure->sections),
                count($rows),
            );
        }

        foreach ($structure->sections as $index => $section) {
            $row = $rows[$index];

            if (abs((float) $row->start_time - $section->startTime) > self::TIMING_TOLERANCE_SECONDS
                || abs((float) $row->end_time - $section->endTime) > self::TIMING_TOLERANCE_SECONDS) {
                return sprintf('section %d has drifted from the banked structure', $index + 1);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedStructure(MediaProcessingLog $processingLog): ?array
    {
        $structure = $processingLog->processing_metadata?->raw['service_structure'] ?? null;

        return is_array($structure) && $structure !== [] ? $structure : null;
    }
}
