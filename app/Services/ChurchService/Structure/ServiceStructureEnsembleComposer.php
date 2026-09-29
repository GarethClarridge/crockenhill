<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Song\SongTitleHygiene;

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

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
        private readonly SongTitleHygiene $songTitles,
    ) {}

    /**
     * @param  array<int, ValidationResult>  $draws  Immutable slot number => validated draw
     */
    public function compose(array $draws): EnsembleComposition
    {
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

        $context = $this->alignmentContext($eligible);
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
                $signature = $this->signature($claim['section']);
                $variants[$signature][] = $claim;
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
            $source = $representative['section'];
            $prayerEquivalent = $source->type === ServiceSectionType::Prayer
                && $this->equivalentPreSermonPrayer($source, $eligible);
            $quietFiller = in_array($source->type, self::FILLER_TYPES, true)
                && ! in_array(true, array_column($group, 'cut_relevant'), true);
            $flagged = ! $prayerEquivalent && ! $quietFiller && ($supported !== $validVotes || count($variants) !== 1);
            $written = $winningVotes > $absent || ($winningVotes === $absent && min(array_keys($eligible)) === min(array_column($winner, 'slot')));

            if ($flagged) {
                $disputes[] = [
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

            // Agreement never clears a flag: a supporter's held proposal stays held on the written section.
            $supporterFlags = array_merge(...array_map(
                static fn (array $supporter): array => $supporter['section']->reviewFlags,
                $winner,
            ));
            $section = $this->withConfidence($source->withReviewFlags([...$supporterFlags, ...$flags]), $winner);
            $sections[] = $section;
            $provenance[] = [
                'group' => $groupIndex,
                'slot' => $representative['slot'],
                'claim_index' => $representative['index'],
                'supporting_slots' => array_column($winner, 'slot'),
            ];
        }

        [$sections, $provenance] = $this->resolveFillerOverlaps($sections, $provenance);

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
            $sections = array_map(static fn (ServiceStructureSection $section): ServiceStructureSection => $section->type === ServiceSectionType::Sermon
                    ? $section->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES])
                    : $section, $sections);
        }

        $disputes = array_map(static function (array $dispute): array {
            $identity = $dispute;
            unset($identity['group'], $identity['candidate_groups'], $identity['claim_index']);
            $dispute['question_id'] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return $dispute;
        }, $disputes);

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

        return new EnsembleComposition($structure, $disputes, $provenance, $degraded, false, $validVotes);
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
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>}  $context
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
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>}  $context
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
     * Where filler can change the sermon's cut, and which song each voter ends its sermon with.
     *
     * The window runs from shortly before the earliest sermon start (the pre-sermon prayer) to
     * the latest point any voter's sermon could extend to: the first song after its sermon, or
     * the reading-pairing window past the sermon's end when no song follows.
     *
     * @param  array<int,ValidationResult>  $draws
     * @return array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>}
     */
    private function alignmentContext(array $draws): array
    {
        $extension = (float) config('media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds', 900);
        $starts = [];
        $limits = [];
        $endingSongs = [];

        foreach ($draws as $slot => $draw) {
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
            }
        }

        return [
            'window' => $starts === [] || $limits === []
                ? null
                : [min($starts) - self::PRE_SERMON_MARGIN_SECONDS, max($limits)],
            'ending_songs' => $endingSongs,
        ];
    }

    /**
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $claim
     * @param  array{window: array{0: float, 1: float}|null, ending_songs: array<string, true>}  $context
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

    private function signature(ServiceStructureSection $section): string
    {
        $fields = [
            $section->type->value,
            $section->talkType?->value,
            $section->oosItemId,
            $section->songTitle === null ? null : $this->songTitles->normalise($section->songTitle),
            $this->normalizedReference($section->readingReference),
            $this->normalizedReference($section->sermonReference),
        ];

        return json_encode(array_map(static fn (mixed $value): mixed => is_string($value)
            ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value))
            : $value, $fields), JSON_THROW_ON_ERROR);
    }

    private function normalizedReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

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
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function flagMissingPreachedReading(array $sections): array
    {
        $window = (float) config('media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds', 900);

        foreach ($sections as $index => $sermon) {
            if ($sermon->type !== ServiceSectionType::Sermon) {
                continue;
            }

            $paired = false;

            foreach ($sections as $reading) {
                if ($reading->type !== ServiceSectionType::BibleReading
                    || $reading->endTime > $sermon->startTime
                    || $sermon->startTime - $reading->endTime > $window) {
                    continue;
                }

                if ($sermon->sermonReference === null || ($reading->readingReference !== null
                    && $this->scriptureReferences->referencesOverlap($reading->readingReference, $sermon->sermonReference))) {
                    $paired = true;

                    break;
                }
            }

            if (! $paired) {
                $sections[$index] = $sermon->withReviewFlags([ServiceStructureValidator::FLAG_MISSING_PREACHED_READING]);
            }
        }

        return $sections;
    }
}
