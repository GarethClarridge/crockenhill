<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\ServiceSectionMetadata;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Write the recomputed sound-stage flags onto the sections that already exist.
 *
 * Deliberately narrow, and the narrowness is the point.
 *
 * **It only ever annotates a stored section; it never creates one.**
 * {@see SustainedSoundSongSections} raises `structure_unidentified_singing` on a
 * section it *proposes* — a song over sustained sound nothing accounted for — so
 * six of the 2026-09-21 findings have no stored row to annotate. Inserting them
 * is not this class's job and must not become it: sections are matched to
 * incoming ones by `section_order` in {@see \App\Services\ChurchService\ServiceSectionSyncService},
 * while the change signature that decides asset cleanup ignores order. An insert
 * therefore shifts every later section into a different comparison, mismatches
 * its signature, and deletes its extracted video and audio. Measured on the five
 * affected runs, that is 15 sections carrying media. Those six are reported as
 * deferred, never written.
 *
 * **It raises, never withdraws.** `needs_manual_review` is set to the existing
 * value OR the policy's verdict, not to the policy's verdict alone. Recomputing
 * outright would withdraw a hold whose cause is not expressed as a review flag,
 * and a pass whose purpose is to add coverage has no business removing
 * containment as a side effect. This mirrors `SectionStructureFlagRederiver`,
 * which can re-weigh a flag but never withdraw one.
 *
 * **It refuses a section whose bounds disagree with the structure it came
 * from.** Position in the banked structure is matched to `section_order`, which
 * is how the pipeline itself keys them — but a mismatch would write a flag onto
 * the wrong section, so the times are checked and a disagreement is reported
 * rather than resolved.
 */
class SoundStageFlagWriter
{
    /**
     * How far a stored section's bounds may sit from the structure section at
     * the same position before the two are taken to be different sections.
     *
     * Generous: snapping and widening move a boundary by design, and the check
     * exists to catch a section being off by a whole item, not to re-assert
     * boundaries the pipeline has already settled.
     */
    private const BOUNDS_TOLERANCE_SECONDS = 1.0;

    /**
     * @param  list<array{run: int, index: int|null, flags: list<string>, start?: float, end?: float}>  $affected
     * @return array<string, mixed>
     */
    public function apply(array $affected, bool $execute): array
    {
        $written = 0;
        $newlyHeld = 0;
        $alreadyCorrect = 0;
        $deferredInserts = [];
        $boundsMismatch = [];
        $touched = [];

        foreach ($this->groupByRun($affected) as $runId => $entries) {
            $sections = ServiceSection::query()
                ->where('media_processing_log_id', $runId)
                ->orderBy('section_order')
                ->get()
                ->values();

            foreach ($entries as $entry) {
                $section = $entry['index'] === null ? null : $sections->get($entry['index']);

                if (! $section instanceof ServiceSection) {
                    // No stored row at this position: a proposed section, not an
                    // annotation. Never written here.
                    $deferredInserts[] = $entry;

                    continue;
                }

                if (! $this->boundsAgree($section, $entry)) {
                    $boundsMismatch[] = [...$entry, 'section_id' => (int) $section->id];

                    continue;
                }

                $outcome = $this->write($section, $entry['flags'], $execute);

                if ($outcome === null) {
                    $alreadyCorrect++;

                    continue;
                }

                $written++;
                $touched[] = (int) $section->id;

                if ($outcome === 'held') {
                    $newlyHeld++;
                }
            }
        }

        return [
            'executed' => $execute,
            'sections_written' => $written,
            'sections_newly_held' => $newlyHeld,
            'sections_already_correct' => $alreadyCorrect,
            'section_ids' => $touched,
            'deferred_inserts' => $deferredInserts,
            'bounds_mismatch' => $boundsMismatch,
        ];
    }

    /**
     * Returns null when nothing needed doing, 'held' when this raised the hold,
     * and 'flagged' when the flag was added without changing the hold.
     *
     * @param  list<string>  $flags
     */
    private function write(ServiceSection $section, array $flags, bool $execute): ?string
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $existing = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        $missing = array_values(array_diff($flags, $existing));

        if ($missing === []) {
            return null;
        }

        $metadata['review_flags'] = [...$existing, ...$missing];

        $policySays = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        // Raise, never withdraw.
        $needsReview = $section->needs_manual_review || $policySays;
        $raisesHold = $needsReview && ! $section->needs_manual_review;

        if ($execute) {
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
            $section->needs_manual_review = $needsReview;
            $section->save();
        }

        return $raisesHold ? 'held' : 'flagged';
    }

    /**
     * @param  array{start?: float, end?: float}  $entry
     */
    private function boundsAgree(ServiceSection $section, array $entry): bool
    {
        if (! isset($entry['start'], $entry['end'])) {
            return true;
        }

        return abs((float) $section->start_time - $entry['start']) <= self::BOUNDS_TOLERANCE_SECONDS
            && abs((float) $section->end_time - $entry['end']) <= self::BOUNDS_TOLERANCE_SECONDS;
    }

    /**
     * @param  list<array{run: int, index: int|null, flags: list<string>, start?: float, end?: float}>  $affected
     * @return array<int, list<array{run: int, index: int|null, flags: list<string>, start?: float, end?: float}>>
     */
    private function groupByRun(array $affected): array
    {
        $grouped = [];

        foreach ($affected as $entry) {
            $grouped[$entry['run']][] = $entry;
        }

        return $grouped;
    }
}
