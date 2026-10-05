<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Data\SongTitleMatch;
use App\Enums\ServiceSectionType;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Song\SongTitleHygiene;
use App\Services\Song\SongTitleResolver;

/**
 * Deterministically composes complete validated draws. Every selected section is
 * one actual draw's coherent fields and boundary pair; neither arrival order nor
 * arithmetic averaging can invent an extraction cut.
 */
class ServiceStructureEnsembleComposer
{
    /**
     * Section types that frame the service's content rather than being published themselves.
     * They are held to agreement only where they can change the sermon's cut (plan §3.5,
     * ruled 2026-09-29); elsewhere they follow the representative voter without a question.
     */
    private const FILLER_TYPES = [
        ServiceSectionType::Welcome,
        ServiceSectionType::Prayer,
        ServiceSectionType::Notices,
        ServiceSectionType::Other,
    ];

    /** How far before the earliest sermon start filler can still change the cut (the pre-sermon prayer). */
    private const PRE_SERMON_MARGIN_SECONDS = 120.0;

    /** Share of the shorter span two claims must overlap to be the same song or the same filler. */
    private const SAME_SPAN_OVERLAP = 0.5;

    /**
     * Votes that decide a claim without a question (ruled 2026-10-01). Where the truth was ruled,
     * a three-to-one vote was never wrong about a talk or sermon: 26 canary-9 answers and 31 splits
     * in the 232 §6 draws. Only ties and three-way splits are asked.
     */
    private const MAJORITY_VOTES = 3;

    /** Catalogue matches that name one song without inference; a fuzzy or hymnbook-absent match guesses. */
    private const DETERMINISTIC_SONG_MATCHES = [
        SongTitleMatch::TYPE_EXACT,
        SongTitleMatch::TYPE_PRAISE_NUMBER,
        SongTitleMatch::TYPE_STRIPPED_NUMBER,
        SongTitleMatch::TYPE_LOOSE_TITLE,
        SongTitleMatch::TYPE_ALTERNATE_TITLE,
        SongTitleMatch::TYPE_FIRST_LINE,
    ];

    /**
     * The longest leader's introduction two reading starts may differ by and still be one
     * reading (ruled 2026-09-30): "Peter will come and do his readings", "Luke chapter 16".
     */
    private const READING_INTRODUCTION_SECONDS = 60.0;

    /** Spoken forms of a numbered book's prefix. */
    private const SPOKEN_BOOK_NUMBERS = ['1' => 'first', '2' => 'second', '3' => 'third'];

    /**
     * @param  SongTitleResolver|null  $songCatalogue  Read from the database on first use when not given
     */
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
        private readonly SongTitleHygiene $songTitles,
        private ?SongTitleResolver $songCatalogue = null,
    ) {}

    /**
     * @param  array<int, ValidationResult>  $draws  Immutable slot number => validated draw
     * @param  ChurchServiceTranscript|null  $transcript  What was said, for rules that read it
     *                                                    (a reading's introduction); without it they do not apply
     * @param  list<int>  $cutNeutralGroups  Filler groups whose disagreement cannot change the sermon's
     *                                       cut, from {@see CutAwareEnsembleComposer}; they raise no question
     * @param  array<int, bool>  $forcedGroups  Group => written, to plan the cut under each version of a
     *                                          disputed group; only {@see CutAwareEnsembleComposer} sets it
     */
    public function compose(
        array $draws,
        ?ChurchServiceTranscript $transcript = null,
        array $cutNeutralGroups = [],
        array $forcedGroups = [],
    ): EnsembleComposition {
        ksort($draws);
        $eligible = array_filter($draws, static fn (ValidationResult $draw): bool => $draw->passed());
        $validVotes = count($eligible);

        if ($validVotes < 2) {
            return new EnsembleComposition(ServiceStructure::fromSections([]), [], [], true, true, $validVotes);
        }

        $claims = [];

        foreach ($eligible as $slot => $draw) {
            foreach ($draw->structure->sections as $index => $section) {
                $claims[] = ['slot' => $slot, 'index' => $index, 'section' => $section];
            }
        }

        usort($claims, static fn (array $a, array $b): int => [
            $a['section']->startTime, $a['section']->endTime, $a['section']->type->value, $a['slot'], $a['index'],
        ] <=> [
            $b['section']->startTime, $b['section']->endTime, $b['section']->type->value, $b['slot'], $b['index'],
        ]);

        $context = [...$this->alignmentContext($eligible), 'transcript' => $transcript];
        $claims = array_map(fn (array $claim): array => [
            ...$claim,
            'cut_relevant' => $this->cutRelevant($claim, $context),
        ], $claims);
        $groups = [];
        $alignmentConflicts = [];

        foreach ($claims as $claim) {
            $candidateGroups = [];

            foreach ($groups as $groupIndex => $group) {
                if ($this->compatibleWithGroup($claim, $group, $eligible, $context)) {
                    $candidateGroups[] = $groupIndex;
                }
            }

            if ($candidateGroups === []) {
                $groups[] = [$claim];

                continue;
            }

            usort($candidateGroups, function (int $a, int $b) use ($groups, $claim): int {
                return [$this->distanceToGroup($claim, $groups[$a]), $a]
                    <=> [$this->distanceToGroup($claim, $groups[$b]), $b];
            });

            if (count($candidateGroups) > 1) {
                $alignmentConflicts[] = [
                    'type' => 'alignment',
                    'claim_slot' => $claim['slot'],
                    'claim_index' => $claim['index'],
                    'candidate_groups' => $candidateGroups,
                    'start_time' => $claim['section']->startTime,
                    'end_time' => $claim['section']->endTime,
                    'written' => false,
                ];
            }

            $groups[$candidateGroups[0]][] = $claim;
        }

        $sections = [];
        $disputes = $alignmentConflicts;
        $records = [];
        $provenance = [];
        $degraded = $validVotes < 4;

        if ($degraded) {
            $disputes[] = [
                'type' => 'degraded_coverage',
                'written' => false,
                'valid_slots' => array_keys($eligible),
                'missing_slots' => array_values(array_diff([0, 1, 2, 3], array_keys($eligible))),
            ];
        }

        foreach ($groups as $groupIndex => $group) {
            $variants = [];

            foreach ($group as $claim) {
                foreach ($variants as $variantIndex => $variant) {
                    if ($this->sameClaim($claim, $variant, $context)) {
                        $variants[$variantIndex][] = $claim;

                        continue 2;
                    }
                }

                $variants[] = [$claim];
            }

            $supported = count($group);
            $absent = $validVotes - $supported;
            $variantList = array_values($variants);
            usort($variantList, static function (array $a, array $b): int {
                $firstA = min(array_column($a, 'slot'));
                $firstB = min(array_column($b, 'slot'));

                return [count($b), $firstA] <=> [count($a), $firstB];
            });

            $winner = $variantList[0];
            $winningVotes = count($winner);
            $representative = $this->representative($winner);

            if ($representative['section']->type === ServiceSectionType::BibleReading) {
                $representative = $this->readingIntroductionChoice($winner, $representative, $transcript);
            }

            $source = $representative['section'];
            $prayerEquivalent = $source->type === ServiceSectionType::Prayer
                && $this->equivalentPreSermonPrayer($source, $eligible);
            $quietFiller = in_array($source->type, self::FILLER_TYPES, true)
                && (! in_array(true, array_column($group, 'cut_relevant'), true) || in_array($groupIndex, $cutNeutralGroups, true));
            $disagrees = ! $prayerEquivalent && ! $quietFiller && ($supported !== $validVotes || count($variants) !== 1);
            $decided = $disagrees && max($winningVotes, $absent) >= self::MAJORITY_VOTES;
            $flagged = $disagrees && ! $decided;
            $written = $forcedGroups[$groupIndex]
                ?? ($winningVotes > $absent || ($winningVotes === $absent && min(array_keys($eligible)) === min(array_column($winner, 'slot'))));

            if ($disagrees) {
                $records[] = [
                    'decided' => $decided,
                    'group' => $groupIndex,
                    'type' => $source->type->value,
                    'start_time' => $source->startTime,
                    'end_time' => $source->endTime,
                    'written' => $written,
                    'supporting_slots' => array_column($winner, 'slot'),
                    'absent_slots' => array_values(array_diff(array_keys($eligible), array_column($group, 'slot'))),
                    'alternatives' => array_map(static fn (array $variant): array => [
                        'slots' => array_column($variant, 'slot'),
                        'section' => $variant[0]['section']->toArray(),
                    ], $variantList),
                ];
            }

            if (! $written) {
                continue;
            }

            $flags = [];

            if ($flagged) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES;
            }

            if ($degraded) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED;
            }

            // Holds remain a union; the stitch warning describes the supporting majority's structure.
            $supporterFlags = array_merge(...array_map(
                static fn (array $supporter): array => $supporter['section']->reviewFlags,
                $winner,
            ));
            $stitchVotes = count(array_filter($winner, static fn (array $supporter): bool => in_array(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $supporter['section']->reviewFlags, true)));
            if ($stitchVotes <= count($winner) / 2) {
                $supporterFlags = array_values(array_diff($supporterFlags, [ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED]));
            }
            // The exposed-speech question is answerable only with its interval, which travels in the
            // note of whichever supporter raised it.
            $questionNotes = array_values(array_unique(array_filter(
                array_merge(...array_map(static fn (array $supporter): array => $supporter['section']->notes, $winner)),
                static fn (string $note): bool => SongSpeechEdges::isExposedSpeechNote($note) && ! in_array($note, $source->notes, true),
            )));
            $section = $this->withConfidence($source->withoutReviewFlags()->withReviewFlags([...$supporterFlags, ...$flags], $questionNotes), $winner);
            $sections[] = $section;
            $provenance[] = [
                'group' => $groupIndex,
                'slot' => $representative['slot'],
                'claim_index' => $representative['index'],
                'supporting_slots' => array_column($winner, 'slot'),
            ];
        }

        [$sections, $provenance] = $this->resolveFillerOverlaps($sections, $provenance);
        [$questions, $majorityDecisions] = $this->separateMajorityDecisions($records, array_keys($eligible));
        $disputes = [...$disputes, ...$questions];

        $sermonVotes = count(array_filter($eligible, static fn (ValidationResult $draw): bool => $draw->structure->sectionsOfType(ServiceSectionType::Sermon) !== []));
        $absenceVotes = array_filter($eligible, static fn (ValidationResult $draw): bool => $draw->structure->assertsSermonAbsence());

        if ($sermonVotes === 0 && count($absenceVotes) !== $validVotes) {
            $disputes[] = [
                'type' => 'sermon_absence',
                'written' => false,
                'asserting_slots' => array_keys($absenceVotes),
                'unasserted_slots' => array_values(array_diff(array_keys($eligible), array_keys($absenceVotes))),
            ];
        }

        if ($disputes !== []) {
            $sections = $this->coverOmittedDisputes($sections, $disputes);
        }

        $disputes = $this->withQuestionIds($this->mergeEdgeSplits($disputes, array_keys($eligible)));
        $majorityDecisions = $this->withQuestionIds($this->mergeEdgeSplits($majorityDecisions, array_keys($eligible)));

        $sections = $this->flagMissingPreachedReading($sections);

        $sermonSections = array_filter(
            $sections,
            static fn (ServiceStructureSection $section): bool => $section->type === ServiceSectionType::Sermon,
        );
        $absence = count($absenceVotes) === $validVotes && $sermonSections === [];

        $first = reset($eligible);
        $chapterMarkers = array_map(static fn (ServiceStructureSection $section): array => [
            'title' => $section->title ?? $section->type->label(),
            'start_time' => $section->startTime,
            'end_time' => $section->endTime,
        ], $sections);
        $structure = ServiceStructure::fromSections(
            $sections,
            $first->structure->notes,
            'ensemble',
            $first->structure->summary,
            $first->structure->notices,
            $chapterMarkers,
            $absence ? reset($absenceVotes)->structure->sermonAbsence : null,
        );

        return new EnsembleComposition(OutputEdgeReview::apply($structure, $disputes), $disputes, $provenance, $degraded, false, $validVotes, majorityDecisions: $majorityDecisions);
    }

    /**
     * Splits the disagreements a three-to-one vote settles from those that stay questions. A
     * settled group whose edge split joins an unsettled one stays with it, so the question
     * still offers every version.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<int>  $slots
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function separateMajorityDecisions(array $records, array $slots): array
    {
        $askedGroups = [];

        foreach ($this->mergeEdgeSplits($records, $slots) as $merged) {
            if (! $merged['decided']) {
                foreach ($merged['groups'] ?? [$merged['group']] as $group) {
                    $askedGroups[$group] = true;
                }
            }
        }

        $questions = [];
        $decisions = [];

        foreach ($records as $record) {
            if ($record['decided'] && ! isset($askedGroups[$record['group']])) {
                $decisions[] = $record;

                continue;
            }

            $questions[] = [...$record, 'decided' => false];
        }

        return [$questions, $decisions];
    }

    /**
     * @param  list<array<string, mixed>>  $disputes
     * @return list<array<string, mixed>>
     */
    private function withQuestionIds(array $disputes): array
    {
        return array_map(static function (array $dispute): array {
            unset($dispute['decided']);
            $identity = $dispute;
            unset($identity['group'], $identity['groups'], $identity['candidate_groups'], $identity['claim_index']);
            $dispute['question_id'] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return $dispute;
        }, $disputes);
    }

    /**
     * Groups are composed independently, so a written section and its neighbour can come from
     * different voters and overlap by a few seconds. An edge that is not held to agreement gives
     * way to one that is: filler's edges, and a song's edges other than the start of the song
     * that ends the sermon (ruled 2026-09-29). It is trimmed back to the neighbour's supported
     * edge, so no new cut is invented. Where both edges are unheld, the less supported gives way.
     * A section left under a second long is dropped. Two held edges that overlap are left for
     * validation to refuse.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array<string, mixed>>  $provenance  Parallel to $sections
     * @return array{0: list<ServiceStructureSection>, 1: list<array<string, mixed>>}
     */
    private function resolveFillerOverlaps(array $sections, array $provenance): array
    {
        $order = array_keys($sections);
        usort($order, static fn (int $a, int $b): int => [$sections[$a]->startTime, $sections[$a]->endTime, $a]
            <=> [$sections[$b]->startTime, $sections[$b]->endTime, $b]);
        $sections = array_map(static fn (int $index): ServiceStructureSection => $sections[$index], $order);
        $provenance = array_map(static fn (int $index): array => $provenance[$index], $order);
        $sermonStart = null;

        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Sermon) {
                $sermonStart = min($sermonStart ?? $section->startTime, $section->startTime);
            }
        }

        $endingSongStart = null;

        foreach ($sections as $section) {
            if ($sermonStart !== null && $section->type === ServiceSectionType::Song && $section->startTime >= $sermonStart) {
                $endingSongStart = $section->startTime;

                break;
            }
        }

        $unheld = static fn (ServiceStructureSection $section, bool $atItsStart): bool => in_array($section->type, self::FILLER_TYPES, true)
            || ($section->type === ServiceSectionType::Song && ! ($atItsStart && $section->startTime === $endingSongStart));

        for ($index = 0; $index < count($sections) - 1; $index++) {
            $earlier = $sections[$index];
            $later = $sections[$index + 1];

            $earlierUnheld = $unheld($earlier, false);
            $laterUnheld = $unheld($later, true);

            if ($earlier->endTime - $later->startTime <= 1.0 || (! $earlierUnheld && ! $laterUnheld)) {
                continue;
            }

            $earlierGivesWay = match (true) {
                ! $laterUnheld => true,
                ! $earlierUnheld => false,
                default => count($provenance[$index]['supporting_slots'] ?? []) < count($provenance[$index + 1]['supporting_slots'] ?? []),
            };
            $loser = $earlierGivesWay ? $index : $index + 1;
            $trimmed = $earlierGivesWay
                ? $earlier->withTimes($earlier->startTime, $later->startTime)
                : $later->withTimes($earlier->endTime, $later->endTime);

            if ($trimmed->endTime - $trimmed->startTime < 1.0) {
                array_splice($sections, $loser, 1);
                array_splice($provenance, $loser, 1);
            } else {
                $sections[$loser] = $trimmed;
                $provenance[$loser]['trimmed_for_overlap'] = [$trimmed->startTime, $trimmed->endTime];
            }

            $index = max(-1, $index - 2);
        }

        return [array_values($sections), $provenance];
    }

    /**
     * @param  array{slot:int,index:int,section:ServiceStructureSection,cut_relevant:bool}  $claim
     * @param  list<array{slot:int,index:int,section:ServiceStructureSection,cut_relevant:bool}>  $group
     * @param  array<int,ValidationResult>  $draws
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>, held_readings: array<string, true>, reading_references: list<string>, sermon_references: list<string>, transcript?: ChurchServiceTranscript|null}  $context
     */
    private function compatibleWithGroup(array $claim, array $group, array $draws, array $context): bool
    {
        foreach ($group as $member) {
            if ($member['slot'] === $claim['slot'] || ! $this->compatible($claim, $member, $draws, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{slot:int,index:int,section:ServiceStructureSection,cut_relevant:bool}  $claimA
     * @param  array{slot:int,index:int,section:ServiceStructureSection,cut_relevant:bool}  $claimB
     * @param  array<int,ValidationResult>  $draws
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>, held_readings: array<string, true>, reading_references: list<string>, sermon_references: list<string>, transcript?: ChurchServiceTranscript|null}  $context
     */
    private function compatible(array $claimA, array $claimB, array $draws, array $context): bool
    {
        $a = $claimA['section'];
        $b = $claimB['section'];

        if ($a->type !== $b->type) {
            return false;
        }

        /**
         * A song is the same song wherever most of it overlaps; only the start of the song that
         * ends the sermon is a cut (ruled 2026-09-29). Identity is compared as a claim field.
         */
        if ($a->type === ServiceSectionType::Song) {
            $bothEndSermon = isset($context['ending_songs'][$this->claimKey($claimA)], $context['ending_songs'][$this->claimKey($claimB)]);

            return $this->overlapShare($a, $b) >= self::SAME_SPAN_OVERLAP
                && (! $bothEndSermon || abs($a->startTime - $b->startTime) <= 15.0);
        }

        if (in_array($a->type, self::FILLER_TYPES, true) && ! ($claimA['cut_relevant'] && $claimB['cut_relevant'])) {
            return $this->overlapShare($a, $b) >= self::SAME_SPAN_OVERLAP;
        }

        /**
         * Only a reading the sermon could pair with is cut, so only it is held to its edges
         * (ruled 2026-09-30); any other reading is the same reading wherever most of it
         * overlaps. Its reference is compared as a claim field.
         */
        if ($a->type === ServiceSectionType::BibleReading
            && ! isset($context['held_readings'][$this->claimKey($claimA)])
            && ! isset($context['held_readings'][$this->claimKey($claimB)])) {
            return $this->overlapShare($a, $b) >= self::SAME_SPAN_OVERLAP;
        }

        if ($a->type === ServiceSectionType::BibleReading
            && abs($a->endTime - $b->endTime) <= 15.0
            && $this->introductionBetween(min($a->startTime, $b->startTime), max($a->startTime, $b->startTime), $draws, $context['transcript'] ?? null)) {
            return true;
        }

        $tolerance = match ($a->type) {
            ServiceSectionType::Sermon, ServiceSectionType::ShortTalk => 30.0,
            ServiceSectionType::BibleReading => 15.0,
            default => 20.0,
        };

        $startAgrees = abs($a->startTime - $b->startTime) <= $tolerance;

        if (! $startAgrees && $a->type === ServiceSectionType::Sermon) {
            $startAgrees = $this->preSermonPrayer($a->startTime, $b->startTime, $draws);
        }

        return $startAgrees && abs($a->endTime - $b->endTime) <= $tolerance;
    }

    /**
     * Where filler can change the sermon's cut, which song each voter ends its sermon with,
     * and which of each voter's readings its sermon could pair with.
     *
     * The window runs from shortly before the earliest sermon start (the pre-sermon prayer) to
     * the latest point any voter's sermon could extend to: the first song after its sermon, or
     * the reading-pairing window past the sermon's end when no song follows.
     *
     * @param  array<int,ValidationResult>  $draws
     * @return array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>, held_readings: array<string, true>, reading_references: list<string>, sermon_references: list<string>}
     */
    private function alignmentContext(array $draws): array
    {
        $extension = (float) config('media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds', 900);
        $starts = [];
        $limits = [];
        $endingSongs = [];
        $heldReadings = [];
        $references = ['reading' => [], 'sermon' => []];

        foreach ($draws as $slot => $draw) {
            foreach ($draw->structure->sections as $section) {
                if ($section->readingReference !== null) {
                    $references['reading'][$section->readingReference] = true;
                }

                if ($section->sermonReference !== null) {
                    $references['sermon'][$section->sermonReference] = true;
                }
            }

            foreach ($draw->structure->sections as $sermon) {
                if ($sermon->type !== ServiceSectionType::Sermon) {
                    continue;
                }

                $starts[] = $sermon->startTime;
                $limit = $sermon->endTime + $extension;

                foreach ($draw->structure->sections as $index => $song) {
                    if ($song->type === ServiceSectionType::Song && $song->startTime >= $sermon->startTime) {
                        $endingSongs["{$slot}:{$index}"] = true;
                        $limit = $song->startTime;

                        break;
                    }
                }

                $limits[] = $limit;
                $heldReadings += $this->pairableReadings($slot, $draw->structure->sections, $sermon);
            }
        }

        return [
            'window' => $starts === [] || $limits === []
                ? null
                : [min($starts) - self::PRE_SERMON_MARGIN_SECONDS, max($limits)],
            'ending_songs' => $endingSongs,
            'held_readings' => $heldReadings,
            'reading_references' => array_map('strval', array_keys($references['reading'])),
            'sermon_references' => array_map('strval', array_keys($references['sermon'])),
        ];
    }

    /**
     * The readings `SermonExtractionPlanResolver` could cut into this sermon's media: any reading
     * that starts before it, however far, by the resolver's own membership rule. When the
     * sermon's reference rules out every reading, a voter may have named the preached reading
     * wrongly, so each is held: correcting that reference would put it in the cut.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return array<string, true>
     */
    private function pairableReadings(int $slot, array $sections, ServiceStructureSection $sermon): array
    {
        $readings = [];

        foreach ($sections as $index => $reading) {
            if ($reading->type === ServiceSectionType::BibleReading && $reading->startTime < $sermon->startTime) {
                $readings["{$slot}:{$index}"] = $reading->readingReference;
            }
        }

        $membership = $this->scriptureReferences->sermonReadingMembership($sermon->sermonReference, $readings);
        $couldBeCut = $membership['selected'] !== null || $membership['review'] ? $membership['could_be_cut'] : array_keys($readings);

        return array_fill_keys($couldBeCut, true);
    }

    /**
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $claim
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>, held_readings: array<string, true>, reading_references: list<string>, sermon_references: list<string>, transcript?: ChurchServiceTranscript|null}  $context
     */
    private function cutRelevant(array $claim, array $context): bool
    {
        $window = $context['window'];

        return $window !== null
            && $claim['section']->startTime < $window[1]
            && $claim['section']->endTime > $window[0];
    }

    /** @param  array{slot:int,index:int}  $claim */
    private function claimKey(array $claim): string
    {
        return "{$claim['slot']}:{$claim['index']}";
    }

    /**
     * Whether two reading starts differ only by the leader's introduction: a short stretch in
     * which no voter hears a song, talk or sermon, and no other reading ends.
     *
     * @param  array<int,ValidationResult>  $draws
     */
    private function introductionBetween(float $start, float $end, array $draws, ?ChurchServiceTranscript $transcript): bool
    {
        if (! $transcript instanceof ChurchServiceTranscript || $end - $start > self::READING_INTRODUCTION_SECONDS) {
            return false;
        }

        foreach ($draws as $draw) {
            foreach ($draw->structure->sections as $section) {
                $content = in_array($section->type, [ServiceSectionType::Song, ServiceSectionType::ShortTalk, ServiceSectionType::Sermon], true);
                $readingEnds = $section->type === ServiceSectionType::BibleReading && $section->endTime > $start && $section->endTime < $end;

                if (($content && $section->startTime < $end && $section->endTime > $start) || $readingEnds) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Where a reading starts when its voters differ only by the leader's introduction: with the
     * introduction when it names the passage, after it when it does not (ruled 2026-09-30).
     * The start is always one voter's, never invented; the book named is the reading's own.
     *
     * @param  non-empty-list<array{slot:int,index:int,section:ServiceStructureSection}>  $supporters
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $representative
     * @return array{slot:int,index:int,section:ServiceStructureSection}
     */
    private function readingIntroductionChoice(array $supporters, array $representative, ?ChurchServiceTranscript $transcript): array
    {
        $starts = array_map(static fn (array $claim): float => $claim['section']->startTime, $supporters);

        if (! $transcript instanceof ChurchServiceTranscript || max($starts) - min($starts) <= 15.0) {
            return $representative;
        }

        $introduction = mb_strtolower($transcript->sliceText(min($starts), max($starts)));
        $announced = false;

        foreach ($supporters as $claim) {
            foreach ($this->spokenBookNames($claim['section']->readingReference) as $book) {
                $announced = $announced || preg_match('/\b'.preg_quote($book, '/').'\b/u', $introduction) === 1;
            }
        }

        usort($supporters, static fn (array $a, array $b): int => $announced
            ? [$a['section']->startTime, $a['slot']] <=> [$b['section']->startTime, $b['slot']]
            : [-$a['section']->startTime, $a['slot']] <=> [-$b['section']->startTime, $b['slot']]);

        return $supporters[0];
    }

    /**
     * The ways a reading's book is said aloud: "John", "1 Corinthians" or "First Corinthians",
     * "Psalm" or "Psalms".
     *
     * @return list<string>
     */
    private function spokenBookNames(?string $reference): array
    {
        $normalized = $reference === null ? null : $this->normalizedReference($reference);

        if ($normalized === null || preg_match('/^(?:([1-3])\s+)?([A-Za-z][A-Za-z ]*?)\s+\d/', $normalized, $match) !== 1) {
            return [];
        }

        $book = mb_strtolower($match[2]);
        $names = [$book];

        if (in_array($book, ['psalm', 'psalms'], true)) {
            $names = ['psalm', 'psalms'];
        }

        if ($match[1] !== '') {
            $names = [...array_map(static fn (string $name): string => "{$match[1]} {$name}", $names),
                ...array_map(static fn (string $name): string => self::SPOKEN_BOOK_NUMBERS[$match[1]].' '.$name, $names)];
        }

        return $names;
    }

    /**
     * One disagreement over a span's edges is one question: two flagged groups of the same type
     * that no voter shares and that mostly overlap become one dispute offering every version.
     * It stays anchored to the written group, so an answer replaces the section it asks about.
     * Two written groups are left apart, since each is its own section.
     *
     * @param  list<array<string, mixed>>  $disputes
     * @param  list<int>  $slots
     * @return list<array<string, mixed>>
     */
    private function mergeEdgeSplits(array $disputes, array $slots): array
    {
        $groupSlots = static fn (array $dispute): array => array_merge(...array_map(
            static fn (array $alternative): array => $alternative['slots'],
            $dispute['alternatives'] ?? [],
        ));

        for ($i = 0; $i < count($disputes); $i++) {
            for ($j = $i + 1; $j < count($disputes); $j++) {
                $a = $disputes[$i];
                $b = $disputes[$j];

                if (! isset($a['group'], $b['group'], $a['start_time'], $b['start_time'])
                    || $a['type'] !== $b['type']
                    || ($a['written'] && $b['written'])
                    || array_intersect($groupSlots($a), $groupSlots($b)) !== []) {
                    continue;
                }

                $overlap = min($a['end_time'], $b['end_time']) - max($a['start_time'], $b['start_time']);
                $shorter = min($a['end_time'] - $a['start_time'], $b['end_time'] - $b['start_time']);

                if ($shorter <= 0 || $overlap / $shorter < self::SAME_SPAN_OVERLAP) {
                    continue;
                }

                [$anchor, $other] = $b['written'] && ! $a['written'] ? [$b, $a] : [$a, $b];
                $covered = [...$groupSlots($anchor), ...$groupSlots($other)];
                $disputes[$i] = [
                    ...$anchor,
                    'decided' => ($anchor['decided'] ?? false) && ($other['decided'] ?? false),
                    'groups' => [...($anchor['groups'] ?? [$anchor['group']]), ...($other['groups'] ?? [$other['group']])],
                    'absent_slots' => array_values(array_diff($slots, $covered)),
                    'alternatives' => [...$anchor['alternatives'], ...$other['alternatives']],
                ];
                array_splice($disputes, $j, 1);
                $j = $i;
            }
        }

        return $disputes;
    }

    private function overlapShare(ServiceStructureSection $a, ServiceStructureSection $b): float
    {
        $overlap = min($a->endTime, $b->endTime) - max($a->startTime, $b->startTime);
        $shorter = min($a->endTime - $a->startTime, $b->endTime - $b->startTime);

        return $overlap <= 0 || $shorter <= 0 ? 0.0 : $overlap / $shorter;
    }

    /** @param array<int,ValidationResult> $draws */
    private function preSermonPrayer(float $a, float $b, array $draws): bool
    {
        $start = min($a, $b);
        $end = max($a, $b);
        $prayer = false;

        foreach ($draws as $draw) {
            foreach ($draw->structure->sections as $section) {
                if ($section->startTime >= $end || $section->endTime <= $start) {
                    continue;
                }

                if (in_array($section->type, [ServiceSectionType::Song, ServiceSectionType::BibleReading], true)) {
                    return false;
                }

                $prayer = $prayer || ($section->type === ServiceSectionType::Prayer
                    && $section->startTime <= $start + 2.0
                    && $section->endTime >= $end - 2.0);
            }
        }

        return $prayer;
    }

    /** @param  array<int,ValidationResult>  $draws */
    private function equivalentPreSermonPrayer(ServiceStructureSection $prayer, array $draws): bool
    {
        /** @var non-empty-list<float>|list<float> $starts */
        $starts = [];
        /** @var non-empty-list<float>|list<float> $ends */
        $ends = [];

        foreach ($draws as $draw) {
            $sermons = $draw->structure->sectionsOfType(ServiceSectionType::Sermon);

            if (count($sermons) !== 1) {
                return false;
            }

            $starts[] = $sermons[0]->startTime;
            $ends[] = $sermons[0]->endTime;
        }

        if ($starts === [] || $ends === []) {
            return false;
        }

        if (max($starts) - min($starts) <= 30.0 || max($ends) - min($ends) > 30.0) {
            return false;
        }

        return $prayer->startTime <= min($starts) + 2.0
            && $prayer->endTime >= max($starts) - 2.0
            && $this->preSermonPrayer(min($starts), max($starts), $draws);
    }

    /**
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $claim
     * @param  list<array{slot:int,index:int,section:ServiceStructureSection}>  $group
     */
    private function distanceToGroup(array $claim, array $group): float
    {
        return array_sum(array_map(static fn (array $member): float => abs($claim['section']->startTime - $member['section']->startTime)
            + abs($claim['section']->endTime - $member['section']->endTime), $group));
    }

    /**
     * Whether a claim makes the same output-relevant statement as every claim of a variant.
     *
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $claim
     * @param  non-empty-list<array{slot:int,index:int,section:ServiceStructureSection}>  $variant
     * @param  array{reading_references: list<string>, sermon_references: list<string>}  $context
     */
    private function sameClaim(array $claim, array $variant, array $context): bool
    {
        $section = $claim['section'];

        foreach ($variant as $member) {
            $other = $member['section'];

            if ($this->signature($section) !== $this->signature($other)
                || ! $this->referencesAgree($section->readingReference, $other->readingReference,
                    fn (string $reading, string $sermon): string => $this->scriptureReferences->readingRelation($sermon, $reading), $context['sermon_references'])
                || ! $this->referencesAgree($section->sermonReference, $other->sermonReference,
                    $this->scriptureReferences->readingRelation(...), $context['reading_references'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A song bound to an order-of-service item is that item's song, so two voters binding the
     * same item agree however they spell its title (ruled 2026-09-30); an unbound song is the
     * catalogue song its title names. A short talk's proposed type is not compared: only the
     * type an operator confirms in talk type review is ever published (ruled 2026-10-01).
     */
    private function signature(ServiceStructureSection $section): string
    {
        $boundSong = $section->type === ServiceSectionType::Song && $section->oosItemId !== null;

        return json_encode([
            $section->type->value,
            $section->oosItemId,
            $section->songTitle === null || $boundSong ? null : $this->songIdentity($section->songTitle),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Titles that resolve deterministically to one catalogue song name that song, however they
     * spell it ("Jesus Saves" is the alternate title of "We Have Heard A Joyful Sound"; ruled
     * 2026-10-01). A title the catalogue cannot name is compared as normalised text.
     */
    private function songIdentity(string $title): string
    {
        $this->songCatalogue ??= SongTitleResolver::fromDatabase();
        $match = $this->songCatalogue->resolve($title);

        if ($match instanceof SongTitleMatch && in_array($match->matchType, self::DETERMINISTIC_SONG_MATCHES, true)) {
            return "catalogue:{$match->songId}";
        }

        $normalised = $this->songTitles->normalise($title);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $normalised) ?? $normalised));
    }

    /**
     * Two citations of one passage at different granularity agree ("Psalm 95" and "Psalm 95:1-7";
     * ruled 2026-10-01) unless they would pair the sermon with different readings: a reading
     * reference is checked against every voter's sermon reference, and a sermon reference
     * against every voter's reading reference, by the relation extraction itself uses. Sharing
     * verses is not enough: a passage the reading holds is cut with it, one it only overlaps
     * is asked about. A missing reference agrees only with another.
     *
     * @param  \Closure(string, string): string  $relation  The membership relation of a reference to a counterpart
     * @param  list<string>  $counterparts
     */
    private function referencesAgree(?string $a, ?string $b, \Closure $relation, array $counterparts): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (mb_strtolower($this->normalizedReference($a)) === mb_strtolower($this->normalizedReference($b))) {
            return true;
        }

        if (! $this->scriptureReferences->referencesOverlap($a, $b)) {
            return false;
        }

        foreach ($counterparts as $counterpart) {
            if ($relation($a, $counterpart) !== $relation($b, $counterpart)) {
                return false;
            }
        }

        return true;
    }

    private function normalizedReference(string $reference): string
    {
        return $this->scriptureReferences->normalizeAll($reference) ?? $reference;
    }

    /**
     * @param  list<array{slot:int,index:int,section:ServiceStructureSection}>  $claims
     * @return array{slot:int,index:int,section:ServiceStructureSection}
     */
    private function representative(array $claims): array
    {
        usort($claims, function (array $a, array $b) use ($claims): int {
            return [$this->distanceToGroup($a, $claims), $a['slot'], $a['index']]
                <=> [$this->distanceToGroup($b, $claims), $b['slot'], $b['index']];
        });

        return $claims[0];
    }

    /** @param  non-empty-list<array{slot:int,index:int,section:ServiceStructureSection}>  $supporters */
    private function withConfidence(ServiceStructureSection $section, array $supporters): ServiceStructureSection
    {
        $confidence = $section->confidence;

        foreach ($supporters as $supporter) {
            $confidence = min($confidence, $supporter['section']->confidence);
        }

        return new ServiceStructureSection(
            type: $section->type,
            title: $section->title,
            startTime: $section->startTime,
            endTime: $section->endTime,
            confidence: $confidence,
            oosItemId: $section->oosItemId,
            songTitle: $section->songTitle,
            readingReference: $section->readingReference,
            sermonReference: $section->sermonReference,
            notes: $section->notes,
            reviewFlags: $section->reviewFlags,
            snapDeltas: $section->snapDeltas,
            summary: $section->summary,
            talkType: $section->talkType,
        );
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array<string,mixed>>  $disputes
     * @return list<ServiceStructureSection>
     */
    private function coverOmittedDisputes(array $sections, array $disputes): array
    {
        foreach ($disputes as $dispute) {
            if ($dispute['written']) {
                continue;
            }

            foreach ($sections as $index => $section) {
                if (! isset($dispute['start_time'], $dispute['end_time'])) {
                    continue;
                }

                if ($section->startTime < $dispute['end_time'] && $section->endTime > $dispute['start_time']) {
                    $sections[$index] = $section->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]);
                }
            }
        }

        return $sections;
    }

    /**
     * Flags each sermon extraction could cut no reading into, by the resolver's own membership
     * rule: a reading that matches, shares verses or has no reference could be the preached
     * one, however far before the sermon it starts; only unrelated readings leave it bare.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function flagMissingPreachedReading(array $sections): array
    {
        foreach ($sections as $index => $sermon) {
            if ($sermon->type !== ServiceSectionType::Sermon) {
                continue;
            }

            $readings = array_map(
                static fn (ServiceStructureSection $reading): ?string => $reading->readingReference,
                array_filter($sections, static fn (ServiceStructureSection $reading): bool => $reading->type === ServiceSectionType::BibleReading
                    && $reading->startTime < $sermon->startTime),
            );

            if ($this->scriptureReferences->sermonReadingMembership($sermon->sermonReference, $readings)['could_be_cut'] === []) {
                $sections[$index] = $sermon->withReviewFlags([ServiceStructureValidator::FLAG_MISSING_PREACHED_READING]);
            }
        }

        return $sections;
    }
}
