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

        foreach ($rulings as $ruling) {
            $key = $ruling['ruling_key'] ?? null;

            if (! is_string($key) || ! is_array($ruling['scope'] ?? null) || ($ruling['source_hash'] ?? null) !== $sourceHash) {
                $stale[] = $ruling;

                continue;
            }

            $byKey[$key][] = $ruling;
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

        $keysByDispute = [];
        $disputesByKey = [];

        foreach ($disputes as $disputeIndex => $dispute) {
            foreach ($byKey as $key => $answers) {
                if ($this->matches($answers[0]['scope'], $dispute, $attemptId)) {
                    $keysByDispute[$disputeIndex][] = $key;
                    $disputesByKey[$key][] = $disputeIndex;
                }
            }
        }

        foreach ($disputesByKey as $key => $matched) {
            if (count($matched) > 1 && isset($current[$key])) {
                $conflicting[] = $current[$key];
            }
        }

        foreach ($disputes as $disputeIndex => $dispute) {
            $keys = $keysByDispute[$disputeIndex] ?? [];
            $key = count($keys) === 1 ? $keys[0] : null;
            $answer = $key !== null && count($disputesByKey[$key]) === 1 ? ($current[$key] ?? null) : null;

            if (count($keys) > 1) {
                foreach ($keys as $competing) {
                    if (isset($current[$competing])) {
                        $conflicting[] = $current[$competing];
                    }
                }
            }

            if ($answer === null) {
                $remaining[] = $key === null ? $dispute : [...$dispute, 'ruling_key' => $key];

                continue;
            }

            $kind = $answer['kind'] ?? null;
            $type = $dispute['type'] ?? null;

            if ($kind === 'defer') {
                $remaining[] = [...$dispute, 'deferred' => true, 'ruling_key' => $key];

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
                    array_splice($sections, $target, 1, $replacements);
                } else {
                    array_push($sections, ...$replacements);
                }
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
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $dispute
     */
    private function matches(array $scope, array $dispute, mixed $attemptId): bool
    {
        if (($scope['type'] ?? null) !== ($dispute['type'] ?? null)) {
            return false;
        }

        if (($scope['type'] ?? null) === 'degraded_coverage') {
            return is_string($attemptId) && ($scope['attempt_id'] ?? null) === $attemptId;
        }

        $current = self::scopeFor($dispute, null);

        if ($scope['start_time'] === null || $current['start_time'] === null) {
            return $scope['start_time'] === null && $current['start_time'] === null;
        }

        $overlap = min((float) $scope['end_time'], (float) $current['end_time'])
            - max((float) $scope['start_time'], (float) $current['start_time']);
        $union = max((float) $scope['end_time'], (float) $current['end_time'])
            - min((float) $scope['start_time'], (float) $current['start_time']);

        return $overlap > 0 && $union > 0 && $overlap / $union >= self::SPAN_MATCH_OVERLAP;
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
