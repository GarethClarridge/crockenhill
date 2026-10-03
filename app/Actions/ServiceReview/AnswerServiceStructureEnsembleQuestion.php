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
        if (! $operator->canAccessAdmin()) {
            throw new InvalidArgumentException('Only an administrator may answer an ensemble question.');
        }

        if (! in_array($kind, ['accept', 'choose', 'remove', 'correct', 'absent', 'defer'], true)) {
            throw new InvalidArgumentException('Unsupported ensemble answer.');
        }

        if ($questionId === '') {
            throw new InvalidArgumentException('A question identifier is required.');
        }

        return DB::transaction(function () use ($processingLogId, $questionId, $kind, $operator, $slot, $sections, $explanation): array {
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
            $question = null;

            foreach ([...$before['disputes'], ...$before['majority_decisions']] as $dispute) {
                if (($dispute['question_id'] ?? null) === $questionId) {
                    $question = $dispute;

                    break;
                }
            }

            if (! is_array($question)) {
                throw new InvalidArgumentException('This ensemble question is no longer open.');
            }

            $rulingKey = is_string($question['ruling_key'] ?? null) ? $question['ruling_key'] : (string) Str::uuid();
            $revisions = array_map(
                static fn (array $ruling): int => (int) ($ruling['revision'] ?? 0),
                array_filter($rulings, static fn (array $ruling): bool => ($ruling['ruling_key'] ?? null) === $rulingKey),
            );
            $ruling = [
                'ruling_key' => $rulingKey,
                'question_id' => $questionId,
                'source_hash' => $before['source_hash'],
                'scope' => ServiceStructureEnsembleRulingApplier::scopeFor(
                    $question,
                    is_string($before['attempt_id'] ?? null) ? $before['attempt_id'] : null,
                ),
                'question' => $question,
                'kind' => $kind,
                'resolution' => $this->resolution($kind, $question, $before, $slot, $sections),
                'explanation' => $explanation,
                'operator_id' => $operator->getKey(),
                'revision' => ($revisions === [] ? 0 : max($revisions)) + 1,
                'answered_at' => now()->toIso8601String(),
            ];
            $rulings[] = $ruling;
            $after = $this->replay->replay($evidence, $rulings, $log);

            if ($kind !== 'defer' && $after['validation_passed'] !== true) {
                throw new InvalidArgumentException('The correction does not pass structural validation: '.implode(', ', $after['failure_codes']));
            }

            $sectionsSynced = false;
            $extractionPlan = $evidence['composition']['extraction_plan'] ?? null;

            if ($kind !== 'defer' && ! $this->hasExtractedMedia($log)) {
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

            return [...$after, 'sections_synced' => $sectionsSynced];
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
        foreach (ServiceStructure::fromArray($before['structure'])->sections as $section) {
            if ($section->type->value === ($question['type'] ?? null)
                && $section->startTime === ($question['start_time'] ?? null)
                && $section->endTime === ($question['end_time'] ?? null)) {
                return $section;
            }
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
