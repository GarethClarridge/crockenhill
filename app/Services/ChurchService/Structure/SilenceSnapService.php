<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Sermon\SermonExtractionPlanResolver;

/**
 * Deterministic boundary refinement: LLM-proposed section times are proposals;
 * the nearest genuine silence in the RMS log is where a section actually turns
 * over. Boundaries with no silence in range stay put (noted), and a snap never
 * crosses a neighbouring section's midpoint, so ordering is preserved.
 */
class SilenceSnapService
{
    private const BOUNDARY_ROUNDING_OVERLAP_SECONDS = 2.0;

    /**
     * Section types a preacher may hand off to mid-sermon without the sermon
     * having ended. Deliberately narrow: a song or a children's talk between
     * two sermon sections means two separate talks, which must keep failing.
     *
     * @var list<value-of<ServiceSectionType>>
     */
    private const SERMON_INTERRUPTION_TYPES = ['bible_reading', 'prayer'];

    private const INTERRUPTION_NOTE = 'Interruption merged into the sermon:';

    /**
     * One item a sermon merge absorbed, with its own span: what an answer about that item settles
     * ({@see ServiceStructureEnsembleRulingApplier}).
     */
    public static function interruptionNote(string $type, float $start, float $end): string
    {
        return sprintf('%s %s at %.1f–%.1fs.', self::INTERRUPTION_NOTE, $type, $start, $end);
    }

    /**
     * The interruptions a merged sermon's notes record, in order.
     *
     * @param  list<string>  $notes
     * @return list<array{0: string, 1: float, 2: float}>
     */
    public static function mergedInterruptions(array $notes): array
    {
        $occurrences = [];

        foreach ($notes as $note) {
            if (preg_match('/^'.preg_quote(self::INTERRUPTION_NOTE, '/').' ([a-z_]+) at (\d+(?:\.\d+)?)–(\d+(?:\.\d+)?)s\.$/u', $note, $match) === 1) {
                $occurrences[] = [$match[1], (float) $match[2], (float) $match[3]];
            }
        }

        return $occurrences;
    }

    /**
     * The note a merged sermon carries for an absorbed item the evidence shows is part of it.
     */
    public static function settledByEvidenceNote(string $type, float $start, float $end, string $evidence): string
    {
        return sprintf('Interruption settled by evidence: the %s at %.1f–%.1fs is part of this sermon (%s).', str_replace('_', ' ', $type), $start, $end, $evidence);
    }

    /**
     * Whether an absorbed item is settled as part of the sermon: by the evidence when it was
     * merged, or by an operator's answer ({@see ServiceStructureEnsembleRulingApplier::interruptionSettledNote()}).
     * Each item is settled on its own; one settled never settles another.
     *
     * @param  array{0: string, 1: float, 2: float}  $occurrence
     * @param  list<string>  $notes
     */
    public static function isSettled(array $occurrence, array $notes): bool
    {
        $claim = sprintf(': the %s at %.1f–%.1fs is part of this sermon', str_replace('_', ' ', $occurrence[0]), $occurrence[1], $occurrence[2]);

        return array_any($notes, static fn (string $note): bool => str_starts_with($note, 'Interruption settled by ') && str_contains($note, $claim));
    }

    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * The passage a merged group preaches, when its parts show one sermon resumed: every sermon
     * part names a passage and they agree. Null otherwise (Codex review, 2026-10-07: a John 19
     * part, a John 19 reading and a Romans 8 part are not one sermon by any reading's evidence),
     * so nothing absorbed is settled and the merge stays in review.
     *
     * @param  list<ServiceStructureSection>  $group
     */
    private function continuousPassage(array $group): ?string
    {
        $scripture = app(ScriptureReferenceResolver::class);
        $passages = array_map(static fn (ServiceStructureSection $part): ?string => $part->sermonReference,
            array_values(array_filter($group, static fn (ServiceStructureSection $part): bool => $part->type === ServiceSectionType::Sermon)));

        if ($passages === [] || in_array(null, $passages, true)) {
            return null;
        }

        return array_all($passages, static fn (string $passage): bool => $scripture->referencesAgree($passages[0], $passage)) ? $passages[0] : null;
    }

    /**
     * Why a reading the merge absorbed is part of the sermon, or null when nothing shows it is
     * (run 949, operator 2026-10-07: "if the first reading is included then the second must
     * be"). Either its passage lies within the one preached (1250's preacher re-reading Job
     * 30:24-31 in a sermon on Job 29-31), or the sermon's own speech names it in the leader's
     * introduction span before it or the line it opens with (949's preacher: "I want to start with
     * Hebrews chapter 9", "one that stands out to me from Numbers 21"): the preacher calls for
     * the passage, and his sermon carries on after it. Speech before the sermon began is not
     * the sermon's. A prayer, a reading with no reference, or one nobody names stays a question.
     */
    private function settledByEvidence(ServiceStructureSection $item, float $sermonStart, string $preached, ?ChurchServiceTranscript $transcript): ?string
    {
        $reading = $item->readingReference;

        if ($item->type !== ServiceSectionType::BibleReading || $reading === null) {
            return null;
        }

        $scripture = app(ScriptureReferenceResolver::class);

        if ($scripture->referenceContains($preached, $reading)) {
            return sprintf('%s lies within the preached passage, %s', $reading, $preached);
        }

        if ($transcript === null) {
            return null;
        }

        // The introduction is said before the reading or as it opens: the line starting with it,
        // never one further in (Codex review: a line minutes later named the passage).
        $from = max($sermonStart, $item->startTime - ServiceStructureEnsembleComposer::READING_INTRODUCTION_SECONDS);
        $introduction = implode(' ', array_column(array_filter($transcript->cues,
            static fn (array $cue): bool => $cue['start'] >= $from - 0.001 && $cue['start'] <= $item->startTime + 0.001), 'text'));

        return $scripture->namesPassage($introduction, $reading) ? sprintf('the sermon names %s before reading it', $reading) : null;
    }


    /**
     * Snap every section boundary to the nearest in-range silence.
     *
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     */
    public function snap(ServiceStructure $structure, string $rmsLogContent, ?ChurchServiceTranscript $transcript = null): ServiceStructure
    {
        if ($structure->isEmpty()) {
            return $structure;
        }

        $silences = $this->silenceTimes($rmsLogContent);

        if ($silences === []) {
            return $this->withSections(
                $structure,
                $this->mergeInterruptedSermon(
                    $this->reconcileBoundaryRoundingOverlaps($structure, $structure->sections),
                    $transcript,
                ),
            );
        }

        $window = (float) config('media-processing.service_structure.snap_window_seconds', 30);
        $sections = $structure->sections;
        $snapped = [];

        foreach ($sections as $index => $section) {
            $previous = $sections[$index - 1] ?? null;
            $next = $sections[$index + 1] ?? null;

            // A boundary may move freely between the midpoints of the sections
            // it separates — never across a neighbour's midpoint.
            $startLowerBound = $previous instanceof ServiceStructureSection ? $this->midpoint($previous) : null;
            $midpoint = $this->midpoint($section);
            $endUpperBound = $next instanceof ServiceStructureSection ? $this->midpoint($next) : null;

            $newStart = $this->nearestSilence($silences, $section->startTime, $window, $startLowerBound, $midpoint);
            $newEnd = $this->nearestSilence($silences, $section->endTime, $window, $midpoint, $endUpperBound);

            if ($transcript !== null) {
                $boundaries = app(TranscriptCueBoundaries::class);
                $newStart = $newStart === null ? null : $boundaries->snapEdge($section, $newStart, 'start', $transcript);
                $newEnd = $newEnd === null ? null : $boundaries->snapEdge($section, $newEnd, 'end', $transcript);
            }

            $notes = [];

            if ($newStart === null) {
                $newStart = $section->startTime;
                $notes[] = sprintf('No silence within %.0fs of the proposed start (%.1fs); left unsnapped.', $window, $section->startTime);
            } elseif (abs($newStart - $section->startTime) > 0.001) {
                $notes[] = sprintf('Start snapped %+.1fs to silence at %.1fs.', $newStart - $section->startTime, $newStart);
            }

            if ($newEnd === null) {
                $newEnd = $section->endTime;
                $notes[] = sprintf('No silence within %.0fs of the proposed end (%.1fs); left unsnapped.', $window, $section->endTime);
            } elseif (abs($newEnd - $section->endTime) > 0.001) {
                $notes[] = sprintf('End snapped %+.1fs to silence at %.1fs.', $newEnd - $section->endTime, $newEnd);
            }

            $snapped[] = $section
                ->withTimes($newStart, $newEnd, $notes)
                ->withSnapDeltas($newStart - $section->startTime, $newEnd - $section->endTime);
        }

        return $this->withSections(
            $structure,
            $this->mergeInterruptedSermon(
                $this->reconcileBoundaryRoundingOverlaps($structure, $snapped),
                $transcript,
            ),
        );
    }

    /**
     * Rebuild one sermon from fragments a mid-sermon reading or prayer split apart.
     *
     * A preacher pausing to have someone read, then resuming, is one sermon, not
     * two — the 2024-07-28 corpus run has the request on the transcript ("we've got
     * time, Andrew, would you like to read for us? Revelation 5"), a reading, then
     * the same sermon concluding. The detector reads that correctly; it was the
     * validator's "at most one sermon" rule that had no way to express it.
     *
     * The merged section absorbs the interruption rather than nesting it, so the
     * sermon stays a single contiguous span and every downstream consumer —
     * {@see SermonExtractionPlanResolver} above all, which
     * takes the *first* matching section and would otherwise publish a sermon
     * missing its conclusion — needs no change.
     *
     * Deliberately narrow. It exists only so a structure the detector typed as two
     * sermons can be valid; it does not decide what audio gets published. Which
     * trailing sections belong to the published sermon is
     * {@see SermonExtractionPlanResolver}'s question, and it
     * answers that without destroying section data. So the group grows only from a
     * sermon, through an interruption, into another *sermon* — a song between two
     * sermons still ends it and keeps failing validation as two separate talks —
     * and a merge exceeding the configured sermon ceiling is refused. The result is
     * flagged for manual review, because the merge moves the sermon's own boundaries,
     * unless the evidence settles every item it absorbed ({@see self::settledByEvidence()}).
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function mergeInterruptedSermon(array $sections, ?ChurchServiceTranscript $transcript = null): array
    {
        $first = null;

        foreach ($sections as $index => $section) {
            if ($section->type === ServiceSectionType::Sermon) {
                $first = $index;

                break;
            }
        }

        if ($first === null) {
            return $sections;
        }

        // Walk forward while the service is still alternating between the sermon
        // and something it was interrupted by. A continuation only counts once an
        // interruption has been seen, and anything else — a song above all — ends
        // the group.
        $last = null;
        $interrupted = false;

        for ($index = $first + 1; $index < count($sections); $index++) {
            $type = $sections[$index]->type->value;

            if (in_array($type, self::SERMON_INTERRUPTION_TYPES, true)) {
                $interrupted = true;

                continue;
            }

            if ($interrupted && $type === ServiceSectionType::Sermon->value) {
                $last = $index;

                continue;
            }

            break;
        }

        if ($last === null) {
            return $sections;
        }

        $start = $sections[$first]->startTime;
        $end = $sections[$last]->endTime;
        $maxDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds',
            2700,
        );

        if ($maxDuration > 0.0 && ($end - $start) > $maxDuration) {
            return $sections;
        }

        $absorbed = [];

        for ($index = $first + 1; $index <= $last; $index++) {
            $absorbed[] = $sections[$index]->type->value;
        }

        $occurrences = [];
        $unsettled = false;
        $preached = $this->continuousPassage(array_slice($sections, $first, $last - $first + 1));

        for ($index = $first + 1; $index <= $last; $index++) {
            $item = $sections[$index];

            if (in_array($item->type->value, self::SERMON_INTERRUPTION_TYPES, true)) {
                $occurrences[] = self::interruptionNote($item->type->value, $item->startTime, $item->endTime);
                $evidence = $preached !== null ? $this->settledByEvidence($item, $start, $preached, $transcript) : null;

                if ($evidence !== null) {
                    $occurrences[] = self::settledByEvidenceNote($item->type->value, $item->startTime, $item->endTime, $evidence);
                } else {
                    $unsettled = true;
                }
            }
        }

        $merged = $sections[$first]
            ->withTimes($start, $end, [sprintf(
                'Sermon interrupted by %s and resumed; merged %d following sections into one sermon (%.1fs-%.1fs).',
                implode(', ', array_unique(array_intersect($absorbed, self::SERMON_INTERRUPTION_TYPES))),
                count($absorbed),
                $start,
                $end,
            ), ...$occurrences])
            ->withReviewFlags($unsettled ? [ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED] : []);

        return [
            ...array_slice($sections, 0, $first),
            $merged,
            ...array_slice($sections, $last + 1),
        ];
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function reconcileBoundaryRoundingOverlaps(ServiceStructure $original, array $sections): array
    {
        foreach (array_keys($sections) as $index) {
            if ($index === 0) {
                continue;
            }

            $previous = $sections[$index - 1];
            $current = $sections[$index];
            $overlap = $previous->endTime - $current->startTime;

            if ($overlap <= 0.0 || $overlap > self::BOUNDARY_ROUNDING_OVERLAP_SECONDS) {
                continue;
            }

            $boundary = ($previous->endTime + $current->startTime) / 2.0;
            $note = sprintf('Reconciled %.1fs boundary-rounding overlap at %.1fs.', $overlap, $boundary);
            $originalPrevious = $original->sections[$index - 1];
            $originalCurrent = $original->sections[$index];

            $sections[$index - 1] = $previous
                ->withTimes($previous->startTime, $boundary, [$note])
                ->withSnapDeltas(
                    $previous->startTime - $originalPrevious->startTime,
                    $boundary - $originalPrevious->endTime,
                );
            $sections[$index] = $current
                ->withTimes($boundary, $current->endTime, [$note])
                ->withSnapDeltas(
                    $boundary - $originalCurrent->startTime,
                    $current->endTime - $originalCurrent->endTime,
                );
        }

        return array_values($sections);
    }

    /** @param list<ServiceStructureSection> $sections */
    private function withSections(ServiceStructure $structure, array $sections): ServiceStructure
    {
        return ServiceStructure::fromSections(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * Sample times whose RMS level sits at or below the silence threshold.
     *
     * Uses the same per-recording calibrated threshold as the segmentation
     * pipeline (adaptive by default), so the snapper sees the same silences
     * the heuristic path does; falls back to the fixed threshold when the
     * adaptive calculation cannot run.
     *
     * @return list<float>
     */
    private function silenceTimes(string $rmsLogContent): array
    {
        try {
            $threshold = (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            $threshold = $this->rmsAnalysisService->getRmsThreshold();
        }

        $times = [];

        foreach ($this->rmsAnalysisService->extractRmsData($rmsLogContent) as $sample) {
            if ($sample['rms'] <= $threshold) {
                $times[] = $sample['time'];
            }
        }

        return $times;
    }

    /**
     * The nearest silence to $target within $window seconds, constrained to lie
     * strictly between the exclusive bounds. Null when nothing qualifies.
     *
     * @param  list<float>  $silences
     */
    private function nearestSilence(
        array $silences,
        float $target,
        float $window,
        ?float $lowerBoundExclusive,
        ?float $upperBoundExclusive,
    ): ?float {
        $best = null;
        $bestDistance = INF;

        foreach ($silences as $time) {
            $distance = abs($time - $target);

            if ($distance > $window) {
                continue;
            }

            if ($lowerBoundExclusive !== null && $time <= $lowerBoundExclusive) {
                continue;
            }

            if ($upperBoundExclusive !== null && $time >= $upperBoundExclusive) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = $time;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function midpoint(ServiceStructureSection $section): float
    {
        return ($section->startTime + $section->endTime) / 2.0;
    }
}
