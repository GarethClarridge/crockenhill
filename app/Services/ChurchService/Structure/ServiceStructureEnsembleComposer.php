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

        $groups = [];
        $alignmentConflicts = [];

        foreach ($claims as $claim) {
            $candidateGroups = [];

            foreach ($groups as $groupIndex => $group) {
                if ($this->compatibleWithGroup($claim, $group, $eligible)) {
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
            $flagged = ! $prayerEquivalent && ($supported !== $validVotes || count($variants) !== 1);
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
     * @param  array{slot:int,index:int,section:ServiceStructureSection}  $claim
     * @param  list<array{slot:int,index:int,section:ServiceStructureSection}>  $group
     * @param  array<int,ValidationResult>  $draws
     */
    private function compatibleWithGroup(array $claim, array $group, array $draws): bool
    {
        foreach ($group as $member) {
            if ($member['slot'] === $claim['slot'] || ! $this->compatible($claim['section'], $member['section'], $draws)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<int,ValidationResult>  $draws */
    private function compatible(ServiceStructureSection $a, ServiceStructureSection $b, array $draws): bool
    {
        if ($a->type !== $b->type) {
            return false;
        }

        $tolerance = match ($a->type) {
            ServiceSectionType::Sermon, ServiceSectionType::ShortTalk => 30.0,
            ServiceSectionType::Song, ServiceSectionType::BibleReading => 15.0,
            default => 20.0,
        };

        $startAgrees = abs($a->startTime - $b->startTime) <= $tolerance;

        if (! $startAgrees && $a->type === ServiceSectionType::Sermon) {
            $startAgrees = $this->preSermonPrayer($a->startTime, $b->startTime, $draws);
        }

        return $startAgrees && abs($a->endTime - $b->endTime) <= $tolerance;
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
