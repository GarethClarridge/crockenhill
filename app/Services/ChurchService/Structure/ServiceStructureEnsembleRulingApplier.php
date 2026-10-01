<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use InvalidArgumentException;

/**
 * Applies content-bound answers to a fresh composition, never to a previous replay's output.
 *
 * An answer is bound to the source it was given on (transcript, timeline, RMS, order of
 * service and policy — the proposal's source hash) and to the question's scope: its type
 * and span. It is matched to a dispute by that scope, not by the dispute's computed
 * identity, so it survives a change to the deterministic rules or a fresh set of draws of
 * the same recording. What it applies is the content the operator settled on, recorded as
 * a resolution when they answered, never a voter slot of some earlier draw.
 *
 * Reduced vote coverage is a fact about one set of draws, so its acceptance is bound to
 * that attempt. An answer matching two open questions, or two answers matching one, is
 * ambiguous and resolves nothing.
 */
class ServiceStructureEnsembleRulingApplier
{
    /** Share of the union two spans must overlap for an answer to carry to a dispute. */
    public const SPAN_MATCH_OVERLAP = 0.5;

    /** Seconds of contact between settled content and a neighbour that are snap noise, not a crossing. */
    public const RULING_EDGE_TOLERANCE = 1.0;

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<array<string, mixed>>  $rulings
     * @return array<string, mixed>
     */
    public function apply(array $proposal, array $rulings): array
    {
        $sourceHash = $proposal['source_hash'] ?? null;
        $disputes = $proposal['disputes'] ?? null;

        if (! is_string($sourceHash) || ! is_array($disputes) || ! is_array($proposal['structure'] ?? null)) {
            throw new InvalidArgumentException('Ensemble proposal is incomplete.');
        }

        $structure = ServiceStructure::fromArray($proposal['structure']);
        $sections = $structure->sections;
        $sermonAbsence = $structure->sermonAbsence;
        $attemptId = $proposal['attempt_id'] ?? null;
        $remaining = [];
        $applied = [];
        $stale = [];
        $conflicting = [];
        $byKey = [];
        $rulingKeyByUnit = [];

        foreach ($rulings as $ruling) {
            $key = $ruling['ruling_key'] ?? null;

            if (! is_string($key) || ! is_array($ruling['scope'] ?? null) || ($ruling['source_hash'] ?? null) !== $sourceHash) {
                $stale[] = $ruling;

                continue;
            }

            $unit = $key.'|'.self::scopeSignature($ruling['scope']);
            $byKey[$unit][] = $ruling;
            $rulingKeyByUnit[$unit] = $key;
        }

        $current = [];

        foreach ($byKey as $key => $answers) {
            $lastRevision = max(array_map(static fn (array $answer): int => (int) ($answer['revision'] ?? 0), $answers));
            $latest = array_values(array_filter($answers, static fn (array $answer): bool => (int) ($answer['revision'] ?? 0) === $lastRevision));

            if (count($latest) !== 1) {
                array_push($conflicting, ...$latest);

                continue;
            }

            $current[$key] = $latest[0];
        }

        $scores = [];

        foreach ($disputes as $disputeIndex => $dispute) {
            foreach ($byKey as $key => $answers) {
                $score = $this->matchScore($answers[0]['scope'], $dispute, $attemptId);

                if ($score !== null) {
                    $scores[$key][$disputeIndex] = $score;
                }
            }
        }

        $pairs = $this->closestFitPairs($scores);
        $disputesByKey = $scores;

        foreach (array_keys($scores) as $key) {
            if (! isset($pairs[$key]) && isset($current[$key]) && ! $this->isSuperseded($key, $pairs, $current, $rulingKeyByUnit)) {
                $conflicting[] = $current[$key];
            }
        }

        $keyByDispute = array_flip($pairs);

        foreach ($disputes as $disputeIndex => $dispute) {
            $key = $keyByDispute[$disputeIndex] ?? null;
            $answer = $key !== null ? ($current[$key] ?? null) : null;

            if ($answer === null) {
                $remaining[] = $key === null ? $dispute : [...$dispute, 'ruling_key' => $rulingKeyByUnit[$key]];

                continue;
            }

            $kind = $answer['kind'] ?? null;
            $type = $dispute['type'] ?? null;

            if ($kind === 'defer') {
                $remaining[] = [...$dispute, 'deferred' => true, 'ruling_key' => $rulingKeyByUnit[$key]];

                continue;
            }

            if ($type === 'degraded_coverage') {
                if ($kind !== 'accept') {
                    throw new InvalidArgumentException('Reduced vote coverage requires an explicit acceptance or deferral.');
                }

                $applied[] = $answer;

                continue;
            }

            if ($type === 'sermon_absence') {
                if ($kind !== 'absent' || ! is_string($answer['explanation'] ?? null)
                    || trim($answer['explanation']) === '') {
                    throw new InvalidArgumentException('Sermon absence requires an explicit explanation.');
                }

                $sermonAbsence = new ServiceSermonAbsence(null, trim($answer['explanation']));
                $applied[] = $answer;

                continue;
            }

            if (! in_array($kind, ['accept', 'choose', 'remove', 'correct'], true)) {
                throw new InvalidArgumentException('Unsupported ensemble answer.');
            }

            $resolution = $answer['resolution'] ?? null;

            if (! is_array($resolution)) {
                throw new InvalidArgumentException('An ensemble answer has no recorded resolution.');
            }

            $target = $this->targetIndex($sections, $dispute);

            if (($resolution['absent'] ?? false) === true) {
                if ($target !== null) {
                    array_splice($sections, $target, 1);
                }
            } else {
                $replacements = $this->resolvedSections($resolution);

                if ($target !== null) {
                    $preservedFlags = array_values(array_diff($sections[$target]->reviewFlags, self::ensembleFlags()));
                    $replacements = array_map(
                        static fn (ServiceStructureSection $section): ServiceStructureSection => $section->withReviewFlags($preservedFlags),
                        $replacements,
                    );
                    array_splice($sections, $target, 1);
                }

                $sections = [...$this->makeRoomFor($sections, $replacements), ...$replacements];
            }

            $applied[] = $answer;
        }

        foreach ($current as $key => $answer) {
            if (! isset($disputesByKey[$key])) {
                $stale[] = $answer;
            }
        }

        $hasUnresolved = $remaining !== [];
        $degraded = ($proposal['degraded'] ?? false) === true;
        $degradedReviewed = $degraded && ! in_array('degraded_coverage', array_column($remaining, 'type'), true);
        $sections = array_map(static function (ServiceStructureSection $section) use ($hasUnresolved, $degraded, $degradedReviewed): ServiceStructureSection {
            $flags = $hasUnresolved
                ? $section->reviewFlags
                : array_values(array_diff($section->reviewFlags, self::ensembleFlags()));

            if ($hasUnresolved && $section->type === ServiceSectionType::Sermon) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES;
            }

            if ($degraded && ! $degradedReviewed) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED;
            }

            return $section->withoutReviewFlags()->withReviewFlags($flags);
        }, $sections);

        $chapterMarkers = array_map(static fn (ServiceStructureSection $section): array => [
            'title' => $section->title ?? $section->type->label(),
            'start_time' => $section->startTime,
            'end_time' => $section->endTime,
        ], $sections);
        $corrected = ServiceStructure::fromSections(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $chapterMarkers,
            $sermonAbsence,
        );

        return [
            ...$proposal,
            'structure' => $corrected->toArray(),
            'disputes' => $remaining,
            'degraded_reviewed' => $degradedReviewed,
            'applied_rulings' => $applied,
            'stale_rulings' => $stale,
            'conflicting_rulings' => array_values(array_unique($conflicting, SORT_REGULAR)),
            'before_rulings' => $proposal['structure'],
        ];
    }

    /**
     * Gives the operator's settled content the time it claims.
     *
     * A version the operator chose often came from a minority draft that divided the passage
     * differently from the majority, so the composed neighbours can cross it: a prayer the
     * chosen talk includes, a welcome wrapped around the chosen reading, a notice that runs
     * into the chosen talk. The answer outranks the composition there, so a neighbour the
     * content wholly covers is absorbed, one that contains it is split around it and one that
     * crosses an edge is trimmed back to that edge. Contact within a snap is left alone.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<ServiceStructureSection>  $replacements
     * @return list<ServiceStructureSection>
     */
    private function makeRoomFor(array $sections, array $replacements): array
    {
        $note = 'Adjusted around an operator ruling.';
        $kept = [];

        foreach ($sections as $section) {
            $pieces = [$section];

            foreach ($replacements as $claim) {
                $next = [];

                foreach ($pieces as $piece) {
                    $overlap = min($piece->endTime, $claim->endTime) - max($piece->startTime, $claim->startTime);

                    if ($overlap <= self::RULING_EDGE_TOLERANCE) {
                        $next[] = $piece;

                        continue;
                    }

                    if ($piece->startTime < $claim->startTime - self::RULING_EDGE_TOLERANCE) {
                        $next[] = $piece->withTimes($piece->startTime, $claim->startTime, [$note]);
                    }

                    if ($piece->endTime > $claim->endTime + self::RULING_EDGE_TOLERANCE) {
                        $next[] = $piece->withTimes($claim->endTime, $piece->endTime, [$note]);
                    }
                }

                $pieces = $next;
            }

            array_push($kept, ...$pieces);
        }

        return $kept;
    }

    /** @param  list<ServiceStructureSection>  $sections
     * @param  array<string, mixed>  $dispute
     */
    private function targetIndex(array $sections, array $dispute): ?int
    {
        if (($dispute['written'] ?? false) !== true) {
            return null;
        }

        foreach ($sections as $index => $section) {
            if ($section->type->value === ($dispute['type'] ?? null)
                && $section->startTime === ($dispute['start_time'] ?? null)
                && $section->endTime === ($dispute['end_time'] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The scope an answer to this dispute is bound to: what kind of question it was and,
     * where it has one, the span it asked about.
     *
     * @param  array<string, mixed>  $dispute
     * @return array{type: string, start_time: float|null, end_time: float|null, attempt_id?: string}
     */
    public static function scopeFor(array $dispute, ?string $attemptId): array
    {
        $scope = [
            'type' => (string) ($dispute['type'] ?? ''),
            'start_time' => is_numeric($dispute['start_time'] ?? null) ? (float) $dispute['start_time'] : null,
            'end_time' => is_numeric($dispute['end_time'] ?? null) ? (float) $dispute['end_time'] : null,
        ];

        if ($scope['type'] === 'degraded_coverage' && $attemptId !== null) {
            $scope['attempt_id'] = $attemptId;
        }

        return $scope;
    }

    /** @return list<string> */
    public static function ensembleFlags(): array
    {
        return [
            ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
            ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED,
        ];
    }

    /**
     * Whether a later revision under the same key, about the question's drifted span, has
     * already been paired: the earlier answer was replaced, not contradicted.
     *
     * @param  array<string, int>  $pairs
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, string>  $rulingKeyByUnit
     */
    private function isSuperseded(string $unit, array $pairs, array $current, array $rulingKeyByUnit): bool
    {
        $revision = (int) ($current[$unit]['revision'] ?? 0);

        foreach (array_keys($pairs) as $paired) {
            if ($rulingKeyByUnit[$paired] === $rulingKeyByUnit[$unit]
                && (int) ($current[$paired]['revision'] ?? 0) > $revision) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revisions replace one another only when they answer the same scope. A later answer
     * filed under the same key about a different span (a part of the passage the first
     * answer covered as a whole) is a separate answer, not a revision of the first.
     *
     * @param  array<string, mixed>  $scope
     */
    private static function scopeSignature(array $scope): string
    {
        $time = static fn (mixed $value): ?string => is_numeric($value) ? number_format((float) $value, 3, '.', '') : null;

        return (string) json_encode([
            $scope['type'] ?? null,
            $time($scope['start_time'] ?? null),
            $time($scope['end_time'] ?? null),
            $scope['attempt_id'] ?? null,
        ]);
    }

    /**
     * How closely an answer's scope fits a dispute: the share of their union the two spans
     * overlap, 1.0 for a question with no span, or null when the answer cannot carry to it.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $dispute
     */
    private function matchScore(array $scope, array $dispute, mixed $attemptId): ?float
    {
        if (($scope['type'] ?? null) !== ($dispute['type'] ?? null)) {
            return null;
        }

        if (($scope['type'] ?? null) === 'degraded_coverage') {
            return is_string($attemptId) && ($scope['attempt_id'] ?? null) === $attemptId ? 1.0 : null;
        }

        $current = self::scopeFor($dispute, null);

        if ($scope['start_time'] === null || $current['start_time'] === null) {
            return $scope['start_time'] === null && $current['start_time'] === null ? 1.0 : null;
        }

        $overlap = min((float) $scope['end_time'], (float) $current['end_time'])
            - max((float) $scope['start_time'], (float) $current['start_time']);
        $union = max((float) $scope['end_time'], (float) $current['end_time'])
            - min((float) $scope['start_time'], (float) $current['start_time']);

        if ($overlap <= 0 || $union <= 0 || $overlap / $union < self::SPAN_MATCH_OVERLAP) {
            return null;
        }

        return $overlap / $union;
    }

    /**
     * Pairs answers with disputes, closest fit first.
     *
     * Questions about part of a passage and about the whole of it overlap, so an answer can
     * fit more than one open question. The closest remaining fit is paired first and both
     * sides leave the pool, so a part answer and a whole answer each reach their own
     * question. Two equally close fits competing for one answer or one question are
     * ambiguous: neither side is paired, and it resolves nothing.
     *
     * @param  array<string, array<int, float>>  $scores  Answer key → dispute index → fit
     * @return array<string, int> Answer key → dispute index
     */
    private function closestFitPairs(array $scores): array
    {
        $candidates = [];

        foreach ($scores as $key => $byDispute) {
            foreach ($byDispute as $disputeIndex => $score) {
                $candidates[] = ['key' => (string) $key, 'dispute' => $disputeIndex, 'score' => $score];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $pairs = [];
        $usedKeys = [];
        $usedDisputes = [];

        foreach ($candidates as $candidate) {
            if (isset($usedKeys[$candidate['key']]) || isset($usedDisputes[$candidate['dispute']])) {
                continue;
            }

            $rivals = array_filter($candidates, static fn (array $other): bool => $other !== $candidate
                && $other['score'] === $candidate['score']
                && ! isset($usedKeys[$other['key']]) && ! isset($usedDisputes[$other['dispute']])
                && ($other['key'] === $candidate['key'] || $other['dispute'] === $candidate['dispute']));

            $usedKeys[$candidate['key']] = true;
            $usedDisputes[$candidate['dispute']] = true;

            if ($rivals !== []) {
                foreach ($rivals as $rival) {
                    $usedKeys[$rival['key']] = true;
                    $usedDisputes[$rival['dispute']] = true;
                }

                continue;
            }

            $pairs[$candidate['key']] = $candidate['dispute'];
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return list<ServiceStructureSection>
     */
    private function resolvedSections(array $resolution): array
    {
        $payloads = $resolution['sections'] ?? null;

        if (! is_array($payloads) || ! array_is_list($payloads)) {
            throw new InvalidArgumentException('A resolution requires an ordered section list.');
        }

        $sections = [];

        foreach ($payloads as $payload) {
            $section = ServiceStructureSection::fromArray($payload);

            if (! $section instanceof ServiceStructureSection) {
                throw new InvalidArgumentException('A resolved section needs valid boundaries.');
            }

            $sections[] = $section;
        }

        return $sections;
    }
}
