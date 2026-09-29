<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleComposer;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleScorer;
use App\Services\ChurchService\Structure\ValidationResult;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Composes the 2026-09-28 scratch detection draws as four-vote ensembles, with the production
 * composer and scorer, without a provider call or any database write.
 *
 * Those draws predate the evidence bundles and are lossy: each section kept only its type,
 * boundaries, title and flags. So this measures boundary and type agreement, talk-count
 * containment and flag volume — never reference, song or binding agreement, which the saved
 * draws cannot show. The draws are already refined by the code of their day and are not
 * re-validated here. A draw that was not a first attempt (a validation retry or an adopted
 * reading recheck) does not vote, as the ensemble never retries.
 *
 * Deletion trigger: remove once evaluation runs from banked ensemble evidence
 * (`structure:ensemble-replay`) cover the same services.
 */
class EvaluateSavedStructureDrawsCommand extends Command
{
    /**
     * Draw-file prefixes and how each is voted: mixed sets pair two 5.6 and two gpt-6 draws of
     * one prompt; single-model sets exist only for 5.6 and are reported as such.
     */
    private const SETS = [
        'baseline' => ['prefix' => '', 'mixed' => true],
        'p2' => ['prefix' => 'p2-', 'mixed' => true],
        'p3' => ['prefix' => 'p3-', 'mixed' => true],
        'p5order' => ['prefix' => 'p5order-', 'mixed' => false],
        'c8probe949' => ['prefix' => 'c8probe949-', 'mixed' => false],
    ];

    protected $signature = 'structure:ensemble-evaluate-saved-draws
                            {directory : Directory holding the scratch draw JSON files}
                            {truth : Labelled truth JSON ({"runs": {runId: [...]}})}
                            {--report= : Write the JSON report to this path}';

    protected $description = 'Compose saved scratch detection draws as ensembles and score them, read-only';

    public function handle(ServiceStructureEnsembleComposer $composer, ServiceStructureEnsembleScorer $scorer): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/');
        $truthRuns = $this->readJson((string) $this->argument('truth'))['runs'] ?? null;

        if (! is_array($truthRuns)) {
            throw new RuntimeException('Truth file has no runs.');
        }

        $report = ['generated_at' => now()->toIso8601String(), 'limits' => $this->limits(), 'sets' => []];

        foreach (self::SETS as $name => $set) {
            $sequences = $this->sequences($directory, $set['prefix'], $set['mixed']);

            if ($sequences === []) {
                continue;
            }

            $evaluated = array_map(
                fn (array $arms): array => $this->evaluateSequence($directory, $arms, $truthRuns, $composer, $scorer),
                $sequences,
            );

            $report['sets'][$name] = [
                'composition' => $set['mixed'] ? '2 × gpt-5.6-luna + 2 × gpt-6-luna' : 'gpt-5.6-luna ×4 (not the configured mix)',
                'summary' => $this->summary($evaluated),
                'sequences' => $evaluated,
            ];
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $json);
            $this->info("Report written to {$path}");
        }

        foreach ($report['sets'] as $name => $set) {
            $summary = $set['summary'];
            $this->line(sprintf(
                '%-11s %-40s %d seq, %d/%d flagged, %.1f disputes/service, talk-count errors %d (unflagged %d), overlaps in %d',
                $name,
                $set['composition'],
                count($set['sequences']),
                $summary['services_flagged'],
                $summary['services'],
                $summary['disputes_per_service'],
                $summary['talk_count_errors'],
                $summary['unflagged_talk_count_errors'],
                $summary['services_with_overlapping_sections'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Disjoint sequences of four arms, so no draw is counted in two ensembles.
     *
     * @return list<list<string>>
     */
    private function sequences(string $directory, string $prefix, bool $mixed): array
    {
        $available = static fn (string $model, int $number): bool => is_file("{$directory}/{$prefix}{$model}-d{$number}.json");
        $sequences = [];

        for ($k = 0; ; $k++) {
            $arms = $mixed
                ? [['g56-luna', 2 * $k + 1], ['g56-luna', 2 * $k + 2], ['g6-luna', 2 * $k + 1], ['g6-luna', 2 * $k + 2]]
                : array_map(static fn (int $offset): array => ['g56-luna', 4 * $k + $offset], [1, 2, 3, 4]);

            foreach ($arms as [$model, $number]) {
                if (! $available($model, $number)) {
                    return $sequences;
                }
            }

            $sequences[] = array_map(static fn (array $arm): string => "{$prefix}{$arm[0]}-d{$arm[1]}", $arms);
        }
    }

    /**
     * @param  list<string>  $arms
     * @param  array<string, mixed>  $truthRuns
     * @return array<string, mixed>
     */
    private function evaluateSequence(
        string $directory,
        array $arms,
        array $truthRuns,
        ServiceStructureEnsembleComposer $composer,
        ServiceStructureEnsembleScorer $scorer,
    ): array {
        $draws = array_map(fn (string $arm): array => $this->readJson("{$directory}/{$arm}.json"), $arms);
        $runIds = array_keys($draws[0]['runs'] ?? []);
        $runs = [];

        foreach ($runIds as $runId) {
            $votes = [];
            $slots = [];

            foreach ($draws as $slot => $draw) {
                [$status, $vote] = $this->vote($draw['runs'][$runId] ?? null);
                $slots[] = ['slot' => $slot, 'arm' => $arms[$slot], 'status' => $status];

                if ($vote instanceof ValidationResult) {
                    $votes[$slot] = $vote;
                }
            }

            $composition = $composer->compose($votes);
            $replay = [
                'structure' => $composition->structure->toArray(),
                'validation_passed' => ! $composition->refused,
                'degraded' => $composition->degraded,
                'disputes' => $composition->disputes,
            ];
            $truth = $truthRuns[(string) $runId] ?? null;
            $score = is_array($truth) && array_is_list($truth)
                ? $scorer->score($replay, array_values(array_filter($truth, 'is_array')))
                : null;
            $disputesByType = [];

            foreach ($composition->disputes as $dispute) {
                $type = (string) ($dispute['type'] ?? 'unknown');
                $disputesByType[$type] = ($disputesByType[$type] ?? 0) + 1;
            }

            ksort($disputesByType);

            $runs[(string) $runId] = [
                'valid_votes' => $composition->validVotes,
                'refused' => $composition->refused,
                'degraded' => $composition->degraded,
                'slots' => $slots,
                'disputes' => count($composition->disputes),
                'disputes_by_type' => $disputesByType,
                'talks_written' => array_map(
                    static fn (ServiceStructureSection $talk): array => [$talk->startTime, $talk->endTime, $talk->reviewFlags],
                    $composition->structure->sectionsOfType(ServiceSectionType::ShortTalk),
                ),
                'sermon_written' => array_map(
                    static fn (ServiceStructureSection $sermon): array => [$sermon->startTime, $sermon->endTime],
                    $composition->structure->sectionsOfType(ServiceSectionType::Sermon),
                ),
                'overlapping_written' => $this->overlaps($composition->structure),
                'talk_count' => $score['talk_count'] ?? null,
                'truth_matches' => $score['matches'] ?? null,
            ];
        }

        return ['draws' => $arms, 'runs' => $runs];
    }

    /**
     * @return array{0: string, 1: ValidationResult|null}
     */
    private function vote(mixed $record): array
    {
        if (! is_array($record) || isset($record['error']) || ! is_array($record['sections'] ?? null)) {
            return ['unavailable', null];
        }

        $steps = is_array($record['pipeline_steps'] ?? null) ? $record['pipeline_steps'] : [];

        if (array_intersect($steps, ['validation_retry', 'reading_recheck_adopted']) !== []) {
            return ['not_first_attempt', null];
        }

        $sections = [];

        foreach ($record['sections'] as $section) {
            $type = ServiceSectionType::tryFromStored((string) ($section['type'] ?? ''));

            if ($type === null || ! is_numeric($section['start'] ?? null) || ! is_numeric($section['end'] ?? null)) {
                return ['unreadable', null];
            }

            $sections[] = new ServiceStructureSection(
                type: $type,
                title: is_string($section['title'] ?? null) ? $section['title'] : null,
                startTime: (float) $section['start'],
                endTime: (float) $section['end'],
                confidence: 1.0,
                oosItemId: null,
                songTitle: null,
                readingReference: null,
                reviewFlags: array_values(array_filter($section['flags'] ?? [], 'is_string')),
            );
        }

        $failures = array_map(
            static fn (string $code): array => ['code' => $code, 'message' => $code],
            array_values(array_filter($record['hard_failures'] ?? [], 'is_string')),
        );
        $vote = new ValidationResult(ServiceStructure::fromSections($sections), $failures);

        return [$vote->passed() ? 'valid' : 'invalid', $vote];
    }

    /**
     * @param  list<array<string, mixed>>  $sequences
     * @return array<string, int|float>
     */
    private function summary(array $sequences): array
    {
        $services = 0;
        $flagged = 0;
        $disputes = 0;
        $degraded = 0;
        $refused = 0;
        $talkErrors = 0;
        $unflagged = 0;
        $sermonDisputes = 0;
        $overlapping = 0;

        foreach ($sequences as $sequence) {
            foreach ($sequence['runs'] as $run) {
                $services++;
                $flagged += $run['disputes'] > 0 ? 1 : 0;
                $disputes += $run['disputes'];
                $degraded += $run['degraded'] ? 1 : 0;
                $refused += $run['refused'] ? 1 : 0;
                $sermonDisputes += isset($run['disputes_by_type']['sermon']) ? 1 : 0;
                $overlapping += $run['overlapping_written'] > 0 ? 1 : 0;

                foreach ($run['talk_count']['errors'] ?? [] as $error) {
                    $talkErrors++;
                    $unflagged += $error['flagged'] ? 0 : 1;
                }
            }
        }

        return [
            'services' => $services,
            'services_flagged' => $flagged,
            'disputes_per_service' => $services === 0 ? 0.0 : round($disputes / $services, 2),
            'services_with_sermon_dispute' => $sermonDisputes,
            'services_with_overlapping_sections' => $overlapping,
            'degraded' => $degraded,
            'refused' => $refused,
            'talk_count_errors' => $talkErrors,
            'unflagged_talk_count_errors' => $unflagged,
        ];
    }

    /**
     * Written sections overlapping by more than a second — a composition validation would refuse.
     */
    private function overlaps(ServiceStructure $structure): int
    {
        $count = 0;
        $sections = $structure->sections;

        foreach ($sections as $index => $section) {
            $next = $sections[$index + 1] ?? null;

            if ($next !== null && $section->endTime - $next->startTime > 1.0) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function limits(): array
    {
        return [
            'Saved draws keep only type, boundaries, title and flags: reference, song and binding agreement are not measured.',
            'Draws were refined by the code of 2026-09-28 and are not re-validated.',
            'Mixed sets use the baseline, p2 and p3 prompts; the current prompt (p5order) has 5.6 draws only.',
            'Repeated draws of the same 16 tuned services measure variability, not unseen-service accuracy.',
        ];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Missing file: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Not a JSON object: {$path}");
        }

        return $decoded;
    }
}
