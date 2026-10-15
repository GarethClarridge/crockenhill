<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Answers one banked ensemble question and reapplies all current rulings to immutable draws.
 *
 * The answer is recorded as the content the operator settled on (a resolution) and the
 * question's scope, so it carries to a replay under changed rules or a fresh set of draws of
 * the same recording. Sections are rewritten only while the run has no extracted or published
 * media: once it has, a changed structure must reach it through the authorised
 * re-detection/re-extraction route, which applies the banked answer there.
 */
class AnswerServiceStructureEnsembleQuestion
{
    public function __construct(
        private readonly ServiceStructureEnsembleReplay $replay,
        private readonly EnsembleReviewGate $gate,
        private readonly ServiceSectionSyncService $sync,
        private readonly SermonExtractionPlanResolver $extractionPlan,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sections  Full replacements for a corrected claim
     * @return array<string, mixed>
     */
    public function execute(
        int $processingLogId,
        string $questionId,
        string $kind,
        User $operator,
        ?int $slot = null,
        array $sections = [],
        ?string $explanation = null,
    ): array {
        return $this->executeAll($processingLogId, [[
            'question_id' => $questionId,
            'kind' => $kind,
            'slot' => $slot,
            'sections' => $sections,
            'explanation' => $explanation,
        ]], $operator);
    }

    /**
     * Records several answers to one run's questions together and validates the result once.
     *
     * Each answer is to a question as it stood before any of them, and they stand or fall
     * together: one answer can depend on another (1005: no answer validated until the sermon's
     * was in), and a refusal records none of them. A page is saved as it is answered and may be
     * applied more than once, so an answer already recorded unchanged is skipped; a different
     * answer to an answered question is refused by name, never taken as a revision.
     *
     * @param  list<array{question_id: string, kind: string, slot?: int|null, sections?: list<array<string, mixed>>, explanation?: string|null}>  $answers
     * @return array<string, mixed>
     */
    public function executeAll(int $processingLogId, array $answers, User $operator): array
    {
        if (! $operator->canAccessAdmin()) {
            throw new InvalidArgumentException('Only an administrator may answer an ensemble question.');
        }

        if ($answers === []) {
            throw new InvalidArgumentException('At least one answer is required.');
        }

        foreach ($answers as $answer) {
            if (! in_array($answer['kind'], ['accept', 'choose', 'remove', 'correct', 'absent', 'defer'], true)) {
                throw new InvalidArgumentException('Unsupported ensemble answer.');
            }

            if ($answer['question_id'] === '') {
                throw new InvalidArgumentException('A question identifier is required.');
            }
        }

        return DB::transaction(function () use ($processingLogId, $answers, $operator): array {
            $log = MediaProcessingLog::query()->lockForUpdate()->findOrFail($processingLogId);
            $metadata = $log->processing_metadata?->toArray() ?? [];
            $bank = $metadata['service_structure_ensemble'] ?? null;
            $evidence = is_array($bank) && $bank !== [] ? end($bank) : null;

            if (! is_array($evidence) || ! $this->gate->inputIsCurrent($log, $evidence)) {
                throw new RuntimeException('Ensemble evidence is missing or no longer matches this run.');
            }

            $storedRulings = $metadata['service_structure_ensemble_rulings'] ?? [];

            if (! is_array($storedRulings) || ! array_is_list($storedRulings)) {
                throw new RuntimeException('Ensemble ruling history is malformed.');
            }

            $rulings = [];

            foreach ($storedRulings as $storedRuling) {
                if (! is_array($storedRuling)) {
                    throw new RuntimeException('Ensemble ruling history is malformed.');
                }

                $rulings[] = $storedRuling;
            }

            $before = $this->replay->replay($evidence, $rulings, $log);
            $open = [...$before['disputes'], ...$before['majority_decisions']];
            $alreadyApplied = [];
            $newAnswers = [];

            foreach ($answers as $answer) {
                $questionId = $answer['question_id'];
                $kind = $answer['kind'];
                $question = array_values(array_filter($open, static fn (array $dispute): bool => ($dispute['question_id'] ?? null) === $questionId))[0] ?? null;

                if (! is_array($question)) {
                    $recorded = $this->latestRuling($rulings, $questionId);

                    if ($recorded === null) {
                        throw new InvalidArgumentException('This ensemble question is no longer open.');
                    }

                    if (! $this->sameAnswer($recorded, $answer)) {
                        throw new InvalidArgumentException("Question {$questionId} was already answered differently ({$recorded['kind']}); the recorded answer is unchanged.");
                    }

                    $alreadyApplied[] = $questionId;

                    continue;
                }

                $newAnswers[] = $answer;

                $rulingKey = is_string($question['ruling_key'] ?? null) ? $question['ruling_key'] : (string) Str::uuid();
                $revisions = array_map(
                    static fn (array $ruling): int => (int) ($ruling['revision'] ?? 0),
                    array_filter($rulings, static fn (array $ruling): bool => ($ruling['ruling_key'] ?? null) === $rulingKey),
                );
                $rulings[] = [
                    'ruling_key' => $rulingKey,
                    'question_id' => $questionId,
                    'source_hash' => $before['source_hash'],
                    'scope' => ServiceStructureEnsembleRulingApplier::scopeFor(
                        $question,
                        is_string($before['attempt_id'] ?? null) ? $before['attempt_id'] : null,
                    ),
                    'question' => $question,
                    'kind' => $kind,
                    'resolution' => $this->resolution($kind, $question, $before, $answer['slot'] ?? null, $answer['sections'] ?? []),
                    'explanation' => $answer['explanation'] ?? null,
                    'operator_id' => $operator->getKey(),
                    'revision' => ($revisions === [] ? 0 : max($revisions)) + 1,
                    'answered_at' => now()->toIso8601String(),
                ];
            }

            if ($newAnswers === []) {
                return [...$before, 'sections_synced' => false, 'already_applied' => $alreadyApplied];
            }

            $settles = array_diff(array_column($newAnswers, 'kind'), ['defer']) !== [];
            $after = $this->replay->replay($evidence, $rulings, $log);

            if ($settles && $after['validation_passed'] !== true) {
                throw new InvalidArgumentException('The correction does not pass structural validation: '.implode(', ', $after['failure_codes']));
            }

            $sectionsSynced = false;
            $extractionPlan = $evidence['composition']['extraction_plan'] ?? null;

            if ($settles && ! $this->hasExtractedMedia($log)) {
                $snapshot = $this->replay->snapshot($evidence);
                $transcript = ChurchServiceTranscript::fromArray($snapshot['transcript'] ?? null);
                $structure = ServiceStructure::fromArray($after['structure']);
                $classified = $structure->toClassifiedSections($log, $transcript);
                $this->sync->sync($log, $classified);
                $metadata['service_structure'] = $structure->toArray();
                $sectionsSynced = true;

                try {
                    $extractionPlan = $this->extractionPlan->resolve($log->refresh());
                } catch (\Throwable $exception) {
                    $extractionPlan = ['unavailable' => $exception->getMessage()];
                }
            }

            $metadata['service_structure_ensemble_rulings'] = $rulings;
            $last = array_key_last($bank);
            $bank[$last]['composition'] = [
                'structure' => $after['structure'],
                'validation_passed' => $after['validation_passed'],
                'failure_codes' => $after['failure_codes'],
                'degraded' => $after['degraded'],
                'degraded_reviewed' => $after['degraded_reviewed'],
                'disputes' => $after['disputes'],
                'majority_decisions' => $after['majority_decisions'],
                'provenance' => $after['provenance'],
                'applied_rulings' => $after['applied_rulings'],
                'stale_rulings' => $after['stale_rulings'],
                'conflicting_rulings' => $after['conflicting_rulings'],
                'extraction_plan' => $extractionPlan,
                'sections_synced' => $sectionsSynced,
            ];
            $metadata['service_structure_ensemble'] = $bank;

            if ($sectionsSynced) {
                $metadata['service_structure_projection'] = $this->gate->projectionProvenance($metadata);
            }

            $log->forceFill(['processing_metadata' => $metadata])->save();

            return [...$after, 'sections_synced' => $sectionsSynced, 'already_applied' => $alreadyApplied];
        });
    }

    /**
     * What the answer settles, as content that stands without the draws it was chosen from.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $before
     * @param  list<array<string, mixed>>  $sections
     * @return array{absent?: true, sections?: list<array<string, mixed>>}|null
     */
    private function resolution(string $kind, array $question, array $before, ?int $slot, array $sections): ?array
    {
        if (in_array($kind, ['defer', 'absent'], true)
            || in_array($question['type'] ?? null, ['degraded_coverage', 'sermon_absence'], true)) {
            return null;
        }

        return match ($kind) {
            'remove' => ['absent' => true],
            'correct' => ['sections' => $sections],
            'choose' => ['sections' => [$this->settled($this->chosenAlternative($question, $slot))]],
            'accept' => ($question['written'] ?? false) === true
                ? ['sections' => [$this->settled($this->writtenSection($question, $before))]]
                : ['absent' => true],
            default => throw new InvalidArgumentException('Unsupported ensemble answer.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rulings
     * @return array<string, mixed>|null
     */
    private function latestRuling(array $rulings, string $questionId): ?array
    {
        $recorded = array_values(array_filter($rulings, static fn (array $ruling): bool => ($ruling['question_id'] ?? null) === $questionId));
        usort($recorded, static fn (array $a, array $b): int => (int) ($a['revision'] ?? 0) <=> (int) ($b['revision'] ?? 0));

        return $recorded === [] ? null : end($recorded);
    }

    /**
     * Whether an answer repeats the recorded one: the same kind settling the same content.
     *
     * @param  array<string, mixed>  $recorded
     * @param  array{question_id: string, kind: string, slot?: int|null, sections?: list<array<string, mixed>>, explanation?: string|null}  $answer
     */
    private function sameAnswer(array $recorded, array $answer): bool
    {
        if (($recorded['kind'] ?? null) !== $answer['kind']) {
            return false;
        }

        return match ($answer['kind']) {
            'choose' => is_array($recorded['question'] ?? null)
                && $this->chosenSlotMatches($recorded, $answer['slot'] ?? null),
            'correct' => $this->sameContent($recorded['resolution']['sections'] ?? null, $answer['sections'] ?? []),
            default => true,
        };
    }

    /** @param  array<string, mixed>  $recorded */
    private function chosenSlotMatches(array $recorded, ?int $slot): bool
    {
        try {
            return $this->sameContent($recorded['resolution']['sections'] ?? null, [$this->settled($this->chosenAlternative($recorded['question'], $slot))]);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Recorded content has been through JSON, so 400.0 reads back as 400 and keys may reorder. */
    private function sameContent(mixed $recorded, mixed $answered): bool
    {
        $normalise = static fn (mixed $value): mixed => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return $normalise($recorded) == $normalise($answered);
    }

    /** @param  array<string, mixed>  $question */
    private function chosenAlternative(array $question, ?int $slot): ServiceStructureSection
    {
        foreach ($question['alternatives'] ?? [] as $alternative) {
            if ($slot !== null && in_array($slot, $alternative['slots'] ?? [], true)) {
                $section = ServiceStructureSection::fromArray($alternative['section'] ?? null);

                if ($section instanceof ServiceStructureSection) {
                    return $section;
                }
            }
        }

        throw new InvalidArgumentException('Chosen version is not a complete alternative for this question.');
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $before
     */
    private function writtenSection(array $question, array $before): ServiceStructureSection
    {
        $sections = ServiceStructure::fromArray($before['structure'])->sections;
        $index = ServiceStructureEnsembleRulingApplier::sectionFor($sections, $question);

        if ($index !== null) {
            return $sections[$index];
        }

        throw new InvalidArgumentException('The disputed section no longer matches the proposal.');
    }

    /**
     * @return array<string, mixed>
     */
    private function settled(ServiceStructureSection $section): array
    {
        return $section->withoutReviewFlags()
            ->withReviewFlags(array_values(array_diff($section->reviewFlags, ServiceStructureEnsembleRulingApplier::ensembleFlags())))
            ->toArray();
    }

    /**
     * Whether rewriting sections could now delete extracted media or move a publication:
     * {@see ServiceSectionSyncService} matches rows by order and cleans up the assets of any
     * row whose signature changes.
     */
    private function hasExtractedMedia(MediaProcessingLog $log): bool
    {
        return $log->sermon_id !== null
            || ServiceSection::query()
                ->where('media_processing_log_id', $log->id)
                ->where(static fn ($query) => $query
                    ->whereNotNull('extracted_video_path')
                    ->orWhereNotNull('extracted_audio_path')
                    ->orWhereNotNull('published_sermon_id'))
                ->exists();
    }
}
