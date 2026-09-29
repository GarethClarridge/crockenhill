<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Song\SongTitleHygiene;

/** Scores a composed proposal against explicitly labelled truth without treating consensus as adjudication. */
class ServiceStructureEnsembleScorer
{
    /**
     * Identity fields a truth entry may label. A labelled field must match the output;
     * songs and readings verify only when at least one of their identity fields is labelled.
     */
    private const IDENTITY_FIELDS = ['sermon_reference', 'reading_reference', 'song_title', 'oos_item_id'];

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
        private readonly SongTitleHygiene $songTitles,
    ) {}

    /**
     * @param  array<string, mixed>  $replay
     * @param  list<array<string, mixed>>  $truth
     * @return array<string, mixed>
     */
    public function score(array $replay, array $truth): array
    {
        $structure = ServiceStructure::fromArray($replay['structure'] ?? null);
        $disputes = is_array($replay['disputes'] ?? null)
            ? array_values(array_filter($replay['disputes'], 'is_array'))
            : [];
        $matchedOutput = [];
        $matches = [];
        $basisCounts = ['operator' => 0, 'transcript' => 0, 'consensus' => 0, 'pending' => 0];

        foreach ($truth as $index => $expected) {
            $type = ServiceSectionType::tryFromStored((string) ($expected['type'] ?? ''));
            $basis = (string) ($expected['basis'] ?? 'pending');

            if ($type === null || ! array_key_exists($basis, $basisCounts)
                || ! is_numeric($expected['start'] ?? null) || ! is_numeric($expected['end'] ?? null)) {
                $matches[] = ['truth_index' => $index, 'status' => 'unscored_invalid_truth'];

                continue;
            }

            $basisCounts[$basis]++;
            $alternatives = [['start' => (float) $expected['start'], 'end' => (float) $expected['end']]];

            foreach ($expected['alternatives'] ?? [] as $alternative) {
                if (is_array($alternative) && is_numeric($alternative['start'] ?? null) && is_numeric($alternative['end'] ?? null)) {
                    $alternatives[] = ['start' => (float) $alternative['start'], 'end' => (float) $alternative['end']];
                }
            }

            $candidates = [];

            foreach ($structure->sections as $outputIndex => $section) {
                if ($section->type !== $type || isset($matchedOutput[$outputIndex])) {
                    continue;
                }

                $distance = min(array_map(static fn (array $span): float => abs($section->startTime - $span['start'])
                    + abs($section->endTime - $span['end']), $alternatives));
                $candidates[] = ['output_index' => $outputIndex, 'distance' => $distance, 'section' => $section];
            }

            usort($candidates, static fn (array $a, array $b): int => [$a['distance'], $a['output_index']]
                <=> [$b['distance'], $b['output_index']]);
            $chosen = $candidates[0] ?? null;
            $tolerance = (float) ($expected['tolerance'] ?? 20);
            $withinTolerance = $chosen !== null && $this->withinAnyAlternative($chosen['section'], $alternatives, $tolerance);

            if ($chosen !== null) {
                $matchedOutput[$chosen['output_index']] = true;
            }

            $identityMismatches = $chosen === null ? [] : $this->identityMismatches($chosen['section'], $expected);
            $status = match (true) {
                $chosen === null => 'missing',
                ! $withinTolerance => 'wrong_boundary',
                $identityMismatches !== [] => 'wrong_identity',
                ! $this->identityLabelled($type, $expected) => 'identity_unverified',
                default => 'matched',
            };

            $matches[] = [
                'truth_index' => $index,
                'type' => $type->value,
                'basis' => $basis,
                'status' => $status,
                'identity_mismatches' => $identityMismatches,
                'truth_span' => ['start' => (float) $expected['start'], 'end' => (float) $expected['end']],
                'output_index' => $chosen['output_index'] ?? null,
                'ambiguous_assignment' => count($candidates) > 1
                    && abs($candidates[0]['distance'] - $candidates[1]['distance']) < 0.001,
            ];
        }

        $unexpected = [];

        foreach ($structure->sections as $index => $section) {
            if (in_array($section->type, [ServiceSectionType::ShortTalk, ServiceSectionType::Sermon], true)
                && ! isset($matchedOutput[$index])) {
                $unexpected[] = [
                    'output_index' => $index,
                    'type' => $section->type->value,
                    'start_time' => $section->startTime,
                    'end_time' => $section->endTime,
                ];
            }
        }

        $talkErrors = $this->talkErrors($structure, $matches, $matchedOutput, $disputes);
        $unflaggedTalkErrors = array_filter($talkErrors, static fn (array $error): bool => ! $error['flagged']);
        $unscored = count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'unscored_invalid_truth'));

        return [
            'talk_count' => [
                'expected' => count(array_filter($truth, static fn (array $span): bool => ($span['type'] ?? null) === ServiceSectionType::ShortTalk->value)),
                'detected' => count($structure->sectionsOfType(ServiceSectionType::ShortTalk)),
                'error' => $talkErrors !== [],
                'flagged_error' => $talkErrors !== [] && $unflaggedTalkErrors === [],
                'unflagged_error' => $unflaggedTalkErrors !== [],
                'errors' => $talkErrors,
            ],
            'matches' => $matches,
            'unexpected_talk_or_sermon' => $unexpected,
            'truth_basis_counts' => $basisCounts,
            'unscored_truth_count' => $unscored,
            'clean_verified' => $unscored === 0 && $basisCounts['consensus'] === 0
                && $basisCounts['pending'] === 0 && count($matchedOutput) === count($structure->sections)
                && count($matches) === count($truth)
                && count(array_filter($matches, static fn (array $match): bool => $match['status'] !== 'matched'
                    || $match['ambiguous_assignment'])) === 0
                && ($replay['validation_passed'] ?? null) === true
                && ($replay['degraded'] ?? true) === false
                && $disputes === [],
            'degraded' => (bool) ($replay['degraded'] ?? true),
            'refused' => ($replay['validation_passed'] ?? null) !== true,
        ];
    }

    /**
     * @param  list<array{start: float, end: float}>  $alternatives
     */
    private function withinAnyAlternative(ServiceStructureSection $section, array $alternatives, float $tolerance): bool
    {
        foreach ($alternatives as $span) {
            if (abs($section->startTime - $span['start']) <= $tolerance
                && abs($section->endTime - $span['end']) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every false or missed talk, each flagged only by review evidence over its own span.
     *
     * A talk error is flagged when the written talk carries the ensemble flag, or when a
     * dispute touching talks overlaps the span in question. A dispute elsewhere in the run
     * says nothing about this talk, so it cannot excuse it.
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  array<int, true>  $matchedOutput
     * @param  list<array<string, mixed>>  $disputes
     * @return list<array{kind: string, start_time: float, end_time: float, flagged: bool}>
     */
    private function talkErrors(ServiceStructure $structure, array $matches, array $matchedOutput, array $disputes): array
    {
        $errors = [];

        foreach ($matches as $match) {
            if (($match['type'] ?? null) !== ServiceSectionType::ShortTalk->value || $match['status'] !== 'missing') {
                continue;
            }

            $start = $match['truth_span']['start'];
            $end = $match['truth_span']['end'];
            $errors[] = [
                'kind' => 'missed_talk',
                'start_time' => $start,
                'end_time' => $end,
                'flagged' => $this->flaggedSectionOverlaps($structure, $start, $end)
                    || $this->talkDisputeOverlaps($disputes, $start, $end),
            ];
        }

        foreach ($structure->sections as $index => $section) {
            if ($section->type !== ServiceSectionType::ShortTalk || isset($matchedOutput[$index])) {
                continue;
            }

            $errors[] = [
                'kind' => 'false_talk',
                'start_time' => $section->startTime,
                'end_time' => $section->endTime,
                'flagged' => in_array(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $section->reviewFlags, true)
                    || $this->talkDisputeOverlaps($disputes, $section->startTime, $section->endTime),
            ];
        }

        return $errors;
    }

    private function flaggedSectionOverlaps(ServiceStructure $structure, float $start, float $end): bool
    {
        foreach ($structure->sections as $section) {
            if ($section->startTime < $end && $section->endTime > $start
                && in_array(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $section->reviewFlags, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array<string, mixed>>  $disputes */
    private function talkDisputeOverlaps(array $disputes, float $start, float $end): bool
    {
        foreach ($disputes as $dispute) {
            if (! is_numeric($dispute['start_time'] ?? null) || ! is_numeric($dispute['end_time'] ?? null)
                || (float) $dispute['start_time'] >= $end || (float) $dispute['end_time'] <= $start) {
                continue;
            }

            $types = [$dispute['type'] ?? null];

            foreach ($dispute['alternatives'] ?? [] as $alternative) {
                $types[] = $alternative['section']['type'] ?? null;
            }

            if (in_array(ServiceSectionType::ShortTalk->value, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function identityMismatches(ServiceStructureSection $section, array $expected): array
    {
        $actual = [
            'sermon_reference' => $section->sermonReference,
            'reading_reference' => $section->readingReference,
            'song_title' => $section->songTitle,
            'oos_item_id' => $section->oosItemId,
        ];
        $mismatches = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            if (! array_key_exists($field, $expected)) {
                continue;
            }

            if (! $this->identityAgrees($field, $expected[$field], $actual[$field])) {
                $mismatches[] = $field;
            }
        }

        return $mismatches;
    }

    private function identityAgrees(string $field, mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === null && $actual === null;
        }

        return match ($field) {
            'oos_item_id' => (int) $expected === (int) $actual,
            'song_title' => $this->songTitles->normalise(mb_strtolower((string) $expected))
                === $this->songTitles->normalise(mb_strtolower((string) $actual)),
            default => $this->scriptureReferences->referencesRenderSameSpan((string) $expected, (string) $actual),
        };
    }

    /** @param  array<string, mixed>  $expected */
    private function identityLabelled(ServiceSectionType $type, array $expected): bool
    {
        return match ($type) {
            ServiceSectionType::Song => array_key_exists('song_title', $expected) || array_key_exists('oos_item_id', $expected),
            ServiceSectionType::BibleReading => array_key_exists('reading_reference', $expected),
            default => true,
        };
    }
}
