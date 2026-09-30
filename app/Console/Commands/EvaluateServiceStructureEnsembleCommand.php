<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ServiceStructure;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\CutAwareEnsembleComposer;
use App\Services\ChurchService\Structure\EnsembleEvidenceVersion;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleInput;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleScorer;
use App\Services\ChurchService\Structure\ServiceStructureEvaluationTelemetry;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationResult;
use App\Services\Sermon\SermonCutProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The plan §6 evaluation: fresh four-draw ensembles over a predeclared set of runs, composed,
 * validated, cut and scored exactly as production would, with nothing written to any run.
 *
 * Each draw is the production {@see ServiceStructureDrawExecutor} over the production input
 * snapshot, run four at a time in separate processes as the runner does. Every draw is kept
 * whole (references, songs, bindings, raw response), so later free replays can measure what
 * the 2026-09-28 draws could not. The cut is planned by the production resolver through
 * {@see SermonCutProbe}, inside a transaction that is always rolled back.
 *
 * Without --detector nothing is called: the inputs are built and the plan printed. Before each
 * sequence the command reserves the worst-case cost of four calls, so neither the call cap nor
 * the spend cap can be crossed. It stops after two sequences in a row lose draws to the provider
 * and are left with fewer than three valid votes (ruled 2026-09-30: a stray server error is
 * noise; an outage is not). --resume continues a stopped report under the same manifest and
 * inputs, keeping its spend and calls against the caps and every sequence already bought.
 * --replay-draws recomposes, re-cuts and rescores a finished evaluation's saved draws under the
 * current code, so a candidate rule is measured on bought evidence before it is adopted.
 */
class EvaluateServiceStructureEnsembleCommand extends Command
{
    /** Consecutive sequences that lose draws and keep fewer than three valid votes before the run stops. */
    private const MAX_CONSECUTIVE_LOSSY_SEQUENCES = 2;

    protected $signature = 'structure:ensemble-evaluate
                            {manifest : Predeclared evaluation manifest JSON}
                            {--detector= : mock|openai; omitted, no draw is made and only the plan is printed}
                            {--report= : Write the JSON report here (default: beside the manifest)}
                            {--resume : Continue the stopped report at --report under the same manifest and inputs}
                            {--replay-draws= : Compose, cut and score the draws already saved in this directory instead of drawing; costs nothing}';

    protected $description = 'Run the predeclared four-draw ensemble evaluation against labelled truth, read-only';

    public function handle(
        ServiceStructureEnsembleInput $inputs,
        CutAwareEnsembleComposer $composer,
        ServiceStructureValidator $validator,
        ServiceStructureEnsembleScorer $scorer,
        SermonCutProbe $cutProbe,
    ): int {
        $manifestPath = (string) $this->argument('manifest');
        $manifest = $this->manifest($manifestPath);
        $truthRuns = $this->readJson($manifest['truth'])['runs'] ?? null;

        if (! is_array($truthRuns)) {
            throw new RuntimeException('Truth file has no runs.');
        }

        $prices = $this->readJson($manifest['price_snapshot']);
        $models = config('media-processing.service_structure.ensemble.models');

        if (! is_array($models) || count($models) !== 4) {
            throw new RuntimeException('The ensemble must configure exactly four model slots.');
        }

        foreach ($models as $model) {
            if (! is_array($prices['models'][$model] ?? null)) {
                throw new RuntimeException("The price snapshot does not price {$model}.");
            }
        }

        $reportPath = (string) ($this->option('report') ?: preg_replace('/\.json$/', '', $manifestPath).'-report.json');
        $workDirectory = preg_replace('/\.json$/', '', $reportPath).'-draws';
        File::ensureDirectoryExists($workDirectory.'/inputs');
        $detector = $this->option('detector');

        $report = [
            'generated_at' => now()->toIso8601String(),
            'manifest' => $manifest,
            'manifest_hash' => hash_file('sha256', $manifestPath),
            'truth_hash' => hash_file('sha256', $manifest['truth']),
            'price_snapshot_hash' => hash_file('sha256', $manifest['price_snapshot']),
            'code_version' => $this->codeVersion(),
            'models' => array_values($models),
            'reasoning_effort' => config('media-processing.service_structure.reasoning_effort'),
            'detector' => $detector,
            'inputs' => [],
            'sequences' => [],
        ];

        $prepared = [];

        foreach ($this->runIds($manifest) as $runId) {
            $log = MediaProcessingLog::query()->findOrFail($runId);
            $churchService = $log->churchService()->first();

            if (! $churchService instanceof ChurchService) {
                throw new RuntimeException("Run {$runId} has no church service, so its planned items would differ from production.");
            }

            $built = $inputs->build($log, $churchService);
            $input = $built['input'];
            $input['evidence_version'] = EnsembleEvidenceVersion::snapshot($input);
            $raw = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $path = "{$workDirectory}/inputs/{$runId}.json";
            File::put($path, $raw);
            $prepared[$runId] = ['log' => $log, 'input' => $input, 'path' => $path, 'transcript' => $built['transcript'], 'context' => $built['context']];
            $report['inputs'][(string) $runId] = [
                'input_hash' => hash('sha256', $raw),
                'evidence_version_hash' => hash('sha256', json_encode($input['evidence_version'], JSON_THROW_ON_ERROR)),
                'oos_items' => count($input['oos_items']),
                'truth_entries' => is_array($truthRuns[(string) $runId] ?? null) ? count($truthRuns[(string) $runId]) : 0,
            ];
        }

        $queue = $this->queue($manifest);
        $plannedCalls = count($queue) * 4;
        $calls = 0;
        $spent = 0.0;

        if ($this->option('resume')) {
            $previous = $this->readJson($reportPath);

            if (($previous['manifest_hash'] ?? null) !== $report['manifest_hash']) {
                throw new RuntimeException('The report to resume was made under a different manifest.');
            }

            foreach ($report['inputs'] as $runId => $input) {
                if (($previous['inputs'][$runId]['input_hash'] ?? null) !== $input['input_hash']) {
                    throw new RuntimeException("Run {$runId}'s input changed since the report began, so its sequences would not be comparable.");
                }
            }

            $report['sequences'] = $previous['sequences'] ?? [];
            $report['code_versions'] = [...($previous['code_versions'] ?? [['from_sequence' => 1, 'version' => $previous['code_version'] ?? null]]), [
                'from_sequence' => count($report['sequences']) + 1,
                'version' => $report['code_version'],
            ]];
            $report['resumed_after'] = $previous['stop_reason'] ?? null;
            $calls = (int) ($previous['calls'] ?? 0);
            $spent = (float) ($previous['spent_usd'] ?? 0.0);
            $done = [];

            foreach ($report['sequences'] as $sequence) {
                $done["{$sequence['set']}:{$sequence['run']}:{$sequence['sequence']}"] = true;
            }

            $queue = array_values(array_filter(
                $queue,
                static fn (array $item): bool => ! isset($done["{$item['set']}:{$item['run']}:{$item['sequence']}"]),
            ));
            $this->line(sprintf('Resuming: %d sequences kept, %d calls and $%.4f already spent.', count($report['sequences']), $calls, $spent));
        }

        $this->line(sprintf(
            'Plan: %d sequences, %d calls; caps %d calls / $%.2f; worst case reserved $%.3f per call.',
            count($queue),
            $plannedCalls,
            $manifest['max_calls'],
            $manifest['max_spend_usd'],
            $manifest['reserve_per_call_usd'],
        ));

        $replayDirectory = $this->option('replay-draws');
        $replaying = is_string($replayDirectory) && $replayDirectory !== '';

        if ($replaying && is_string($detector) && $detector !== '') {
            throw new RuntimeException('A replay makes no draws; omit --detector.');
        }

        if ($replaying) {
            $report['replayed_from'] = $replayDirectory;
        }

        if (! $replaying && (! is_string($detector) || $detector === '')) {
            $report['dry_run'] = true;
            $this->writeReport($reportPath, $report);
            $this->info("Dry run: inputs built, no draw made. Report: {$reportPath}");

            return self::SUCCESS;
        }

        $lossyRun = 0;
        $stopReason = null;
        $started = microtime(true);

        foreach ($queue as $number => $item) {
            $runId = $item['run'];
            $run = $prepared[$runId];
            $drawBase = "{$item['set']}-{$runId}-s{$item['sequence']}";

            if ($replaying) {
                $outcomes = $this->readOutcomes("{$replayDirectory}/{$drawBase}", array_values($models));
            } else {
                $stopReason = match (true) {
                    $calls + 4 > $manifest['max_calls'] => 'max_calls',
                    $spent + 4 * $manifest['reserve_per_call_usd'] > $manifest['max_spend_usd'] => 'max_spend',
                    $lossyRun >= self::MAX_CONSECUTIVE_LOSSY_SEQUENCES => 'repeated_unavailable_draws',
                    default => null,
                };

                if ($stopReason !== null) {
                    break;
                }

                $outcomes = $this->draw($run['path'], "{$workDirectory}/{$drawBase}", array_values($models), (string) $detector);
                $calls += 4;
            }

            $draws = [];
            $slots = [];
            $sequenceCost = 0.0;

            foreach ($outcomes as $slot => $outcome) {
                $cost = $this->cost($outcome, $prices, $manifest['reserve_per_call_usd']);
                $sequenceCost += $cost['usd'];
                $slots[] = [
                    'slot' => $slot,
                    'model' => $outcome['model'] ?? null,
                    'status' => $outcome['status'] ?? 'interrupted',
                    'elapsed_seconds' => $outcome['elapsed_seconds'] ?? null,
                    'service_tier' => $outcome['service_tier'] ?? null,
                    'cost_usd' => $cost['usd'],
                    'cost_basis' => $cost['basis'],
                    'error' => $outcome['error'] ?? null,
                ];

                if (in_array($outcome['status'] ?? null, ['valid', 'invalid'], true)) {
                    $draws[$slot] = new ValidationResult(
                        ServiceStructure::fromArray($outcome['validated'] ?? null),
                        $outcome['hard_failures'] ?? [],
                        $outcome['unmatched_oos_item_ids'] ?? [],
                    );
                }
            }

            $spent += $replaying ? 0.0 : $sequenceCost;
            $composition = $composer->compose($draws, $run['transcript'], $run['log']);
            $lostDraws = array_intersect(array_column($slots, 'status'), ['unavailable', 'interrupted']);
            $lossyRun = $lostDraws !== [] && $composition->validVotes < 3 ? $lossyRun + 1 : 0;
            $validation = $composition->refused ? null : $validator->validate($composition->structure, $run['context']);
            $cut = $composition->refused ? null : $cutProbe->probe($run['log'], $composition->structure, $run['transcript']);
            $truth = $truthRuns[(string) $runId] ?? null;
            $replay = [
                'structure' => $composition->structure->toArray(),
                'validation_passed' => $validation?->passed() ?? false,
                'degraded' => $composition->degraded,
                'disputes' => $composition->disputes,
                'cut' => $cut,
            ];

            $report['sequences'][] = [
                'number' => count($report['sequences']) + 1,
                'set' => $item['set'],
                'run' => $runId,
                'sequence' => $item['sequence'],
                'slots' => $slots,
                'cost_usd' => round($sequenceCost, 6),
                'valid_votes' => $composition->validVotes,
                'refused' => $composition->refused,
                'validation_passed' => $replay['validation_passed'],
                'validation_failures' => $validation?->failureCodes() ?? [],
                'degraded' => $composition->degraded,
                'disputes' => $composition->disputes,
                'structure' => $replay['structure'],
                'cut' => $cut,
                'score' => is_array($truth) && array_is_list($truth)
                    ? $scorer->score($replay, array_values(array_filter($truth, 'is_array')))
                    : null,
            ];
            $report['calls'] = $calls;
            $report['spent_usd'] = round($spent, 6);
            $report['elapsed_seconds'] = round(microtime(true) - $started, 1);
            $this->writeReport($reportPath, $report);
            $this->line(sprintf(
                '%3d/%d %-8s run %-5s seq %d  votes %d  questions %2d  $%.4f (total $%.4f)',
                $number + 1,
                count($queue),
                $item['set'],
                $runId,
                $item['sequence'],
                $composition->validVotes,
                count($composition->disputes),
                $sequenceCost,
                $spent,
            ));
        }

        $report['stop_reason'] = $stopReason;
        $report['sequences'] = array_values(array_map(
            fn (array $sequence): array => $this->rescore($sequence, $truthRuns, $scorer),
            $report['sequences'],
        ));
        $report['summary'] = $this->summary($report['sequences']);
        $this->writeReport($reportPath, $report);

        if ($stopReason !== null) {
            $this->warn("Stopped early: {$stopReason}.");
        }

        $this->info(sprintf('%d calls, $%.4f. Report: %s', $calls, $spent, $reportPath));

        return $stopReason === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Scores a sequence again from what it recorded, so every sequence in a resumed report is
     * judged by the current scorer, not the one in force when it was bought.
     *
     * @param  array<string, mixed>  $sequence
     * @param  array<string, mixed>  $truthRuns
     * @return array<string, mixed>
     */
    private function rescore(array $sequence, array $truthRuns, ServiceStructureEnsembleScorer $scorer): array
    {
        $truth = $truthRuns[(string) $sequence['run']] ?? null;
        $sequence['score'] = is_array($truth) && array_is_list($truth)
            ? $scorer->score([
                'structure' => $sequence['structure'],
                'validation_passed' => $sequence['validation_passed'] ?? (! $sequence['refused'] && $sequence['validation_failures'] === []),
                'degraded' => $sequence['degraded'],
                'disputes' => $sequence['disputes'],
                'cut' => $sequence['cut'],
            ], array_values(array_filter($truth, 'is_array')))
            : null;

        return $sequence;
    }

    /**
     * Four draws of one input, each in its own process. Every draw writes its own outcome
     * file, so a draw that is killed or fails loses only itself.
     *
     * @param  list<string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function draw(string $inputPath, string $outputBase, array $models, string $detector): array
    {
        $tasks = [];

        foreach ($models as $slot => $model) {
            $outputPath = "{$outputBase}-slot-{$slot}.json";
            $tasks[$slot] = static function () use ($inputPath, $outputPath, $model, $detector): bool {
                config(['media-processing.service_structure.detector' => $detector]);
                $started = microtime(true);

                try {
                    $input = json_decode((string) file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
                    $draw = app(ServiceStructureDrawExecutor::class)->execute($input, $model);
                    $outcome = [
                        'status' => $draw['validation']->passed() ? 'valid' : 'invalid',
                        'raw' => $draw['raw']->toArray(),
                        'raw_response' => $draw['raw_response'],
                        'refined' => $draw['refined']->toArray(),
                        'validated' => $draw['validation']->structure->toArray(),
                        'hard_failures' => $draw['validation']->hardFailures,
                        'unmatched_oos_item_ids' => $draw['validation']->unmatchedOosItemIds,
                        'usage' => $draw['usage'],
                        'service_tier' => $draw['service_tier'],
                    ];
                } catch (Throwable $exception) {
                    $telemetry = app(ServiceStructureEvaluationTelemetry::class);
                    $outcome = [
                        'status' => 'unavailable',
                        'error' => $exception->getMessage(),
                        'usage' => $telemetry->take(),
                        'service_tier' => $telemetry->takeServiceTier(),
                        'raw_response' => $telemetry->takeRawResponse(),
                    ];
                }

                $outcome['model'] = $model;
                $outcome['elapsed_seconds'] = round(microtime(true) - $started, 2);
                file_put_contents($outputPath, json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                return true;
            };
        }

        try {
            Concurrency::run($tasks, (int) config('media-processing.service_structure.ensemble.draw_timeout_seconds', 300));
        } catch (Throwable $exception) {
            $this->warn('A draw process failed: '.mb_substr($exception->getMessage(), 0, 300));
        }

        return $this->readOutcomes($outputBase, $models);
    }

    /**
     * Each slot's saved outcome; a slot with no file of its own model is an interrupted draw.
     *
     * @param  list<string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function readOutcomes(string $outputBase, array $models): array
    {
        $outcomes = [];

        foreach ($models as $slot => $model) {
            $path = "{$outputBase}-slot-{$slot}.json";
            $outcome = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $outcomes[$slot] = is_array($outcome) && ($outcome['model'] ?? null) === $model
                ? $outcome
                : ['status' => 'interrupted', 'model' => $model, 'usage' => null];
        }

        return $outcomes;
    }

    /**
     * A draw's cost from its reported usage at the snapshot's standard rates. A draw with no
     * usage is charged the worst-case reserve, since it may still have been billed.
     *
     * @param  array<string, mixed>  $outcome
     * @param  array<string, mixed>  $prices
     * @return array{usd: float, basis: string}
     */
    private function cost(array $outcome, array $prices, float $reserve): array
    {
        $usage = $outcome['usage'] ?? null;
        $rates = $prices['models'][$outcome['model'] ?? ''] ?? null;

        if (! is_array($usage) || ! is_array($rates)) {
            return ['usd' => $reserve, 'basis' => 'reserve'];
        }

        $input = (int) ($usage['input_tokens'] ?? 0);
        $cached = (int) ($usage['cached_input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $inputRate = (float) $rates['input'];

        return [
            'usd' => (($input - $cached) * $inputRate
                + $cached * (float) ($rates['cached_input'] ?? $inputRate)
                + $output * (float) $rates['output']) / 1_000_000,
            'basis' => 'usage',
        ];
    }

    /**
     * Sequence 1 of every run in every set before any run's sequence 2, so a run cut short by
     * a cap still covers each service once.
     *
     * @param  array{sets: list<array{name: string, runs: list<int>, sequences: int}>}  $manifest
     * @return list<array{set: string, run: int, sequence: int}>
     */
    private function queue(array $manifest): array
    {
        $queue = [];

        foreach ($manifest['sets'] as $order => $set) {
            for ($sequence = 1; $sequence <= $set['sequences']; $sequence++) {
                foreach ($set['runs'] as $run) {
                    $queue[] = ['set' => $set['name'], 'run' => $run, 'sequence' => $sequence, 'key' => [$sequence, $order, $run]];
                }
            }
        }

        usort($queue, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(static fn (array $item): array => ['set' => $item['set'], 'run' => $item['run'], 'sequence' => $item['sequence']], $queue);
    }

    /**
     * @param  array{sets: list<array{name: string, runs: list<int>, sequences: int}>}  $manifest
     * @return list<int>
     */
    private function runIds(array $manifest): array
    {
        $ids = [];

        foreach ($manifest['sets'] as $set) {
            array_push($ids, ...$set['runs']);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Per set: flag volume, talk and cut errors (flagged and unflagged), and how far one
     * service's cut moves between sequences, which needs no truth to expose an unstable cut.
     *
     * @param  list<array<string, mixed>>  $sequences
     * @return array<string, mixed>
     */
    private function summary(array $sequences): array
    {
        $sets = [];

        foreach ($sequences as $sequence) {
            $sets[$sequence['set']][] = $sequence;
        }

        $summary = [];

        foreach ($sets as $name => $items) {
            $count = count($items);
            $byType = [];
            $cuts = [];
            $latency = [];

            foreach ($items as $item) {
                foreach ($item['disputes'] as $dispute) {
                    $type = (string) ($dispute['type'] ?? 'unknown');
                    $byType[$type] = ($byType[$type] ?? 0) + 1;
                }

                foreach ($item['slots'] as $slot) {
                    if (is_numeric($slot['elapsed_seconds'])) {
                        $latency[(string) $slot['model']][] = (float) $slot['elapsed_seconds'];
                    }
                }

                $segments = $item['cut']['as_written']['segments'] ?? null;

                if (($item['cut']['as_written']['from_sections'] ?? false) === true && is_array($segments) && $segments !== []) {
                    $cuts[$item['run']][] = [
                        'start' => (float) $segments[0]['start_time'],
                        'end' => (float) $segments[array_key_last($segments)]['end_time'],
                        'strategy' => $item['cut']['as_written']['strategy'] ?? null,
                        'questioned' => $item['disputes'] !== [],
                    ];
                }
            }

            ksort($byType);
            $scores = array_filter(array_column($items, 'score'), 'is_array');
            $cutScores = array_filter(array_column($scores, 'cut'), 'is_array');

            $summary[$name] = [
                'sequences' => $count,
                'services' => count(array_unique(array_column($items, 'run'))),
                'flagged' => count(array_filter($items, static fn (array $item): bool => $item['disputes'] !== [])),
                'questions_per_sequence' => round(array_sum($byType) / max(1, $count), 2),
                'questions_per_sequence_by_type' => array_map(static fn (int $n): float => round($n / max(1, $count), 2), $byType),
                'refused' => count(array_filter($items, static fn (array $item): bool => $item['refused'])),
                'validation_failed' => count(array_filter($items, static fn (array $item): bool => ! $item['refused'] && $item['validation_failures'] !== [])),
                'degraded' => count(array_filter($items, static fn (array $item): bool => $item['degraded'])),
                'talk_count_errors' => count(array_filter($scores, static fn (array $score): bool => $score['talk_count']['error'])),
                'unflagged_talk_count_errors' => count(array_filter($scores, static fn (array $score): bool => $score['talk_count']['unflagged_error'])),
                'cuts_scored' => count(array_filter($cutScores, static fn (array $cut): bool => $cut['scored'])),
                'cuts_wrong' => count(array_filter($cutScores, static fn (array $cut): bool => $cut['wrong'] ?? false)),
                'cuts_wrong_unflagged' => count(array_filter($cutScores, static fn (array $cut): bool => $cut['wrong_unflagged'] ?? false)),
                'cuts_reaching_extraction_unreviewed' => count(array_filter($cutScores, static fn (array $cut): bool => $cut['reaches_extraction_unreviewed'])),
                'wrong_cuts_reaching_extraction_unreviewed' => count(array_filter($cutScores, static fn (array $cut): bool => $cut['wrong_reaches_extraction_unreviewed'] ?? false)),
                'cut_spread_by_run' => array_map(static fn (array $runCuts): array => [
                    'cuts' => count($runCuts),
                    'start_spread_seconds' => round(max(array_column($runCuts, 'start')) - min(array_column($runCuts, 'start')), 2),
                    'end_spread_seconds' => round(max(array_column($runCuts, 'end')) - min(array_column($runCuts, 'end')), 2),
                    'strategies' => array_values(array_unique(array_filter(array_column($runCuts, 'strategy')))),
                    'unquestioned' => count(array_filter($runCuts, static fn (array $cut): bool => ! $cut['questioned'])),
                ], $cuts),
                'cost_usd' => round(array_sum(array_column($items, 'cost_usd')), 4),
                'draw_statuses' => array_count_values(array_merge(...array_map(static fn (array $item): array => array_column($item['slots'], 'status'), $items))),
                'latency_seconds_by_model' => array_map(static function (array $values): array {
                    sort($values);

                    return ['median' => $values[intdiv(count($values), 2)], 'max' => end($values)];
                }, $latency),
            ];
        }

        return $summary;
    }

    /**
     * @return array{truth: string, price_snapshot: string, max_calls: int, max_spend_usd: float, reserve_per_call_usd: float, sets: list<array{name: string, runs: list<int>, sequences: int}>}
     */
    private function manifest(string $path): array
    {
        $manifest = $this->readJson($path);
        $sets = [];

        foreach ($manifest['sets'] ?? [] as $set) {
            if (! is_array($set) || ! is_string($set['name'] ?? null) || ! is_array($set['runs'] ?? null)
                || $set['runs'] === [] || ! is_int($set['sequences'] ?? null) || $set['sequences'] < 1) {
                throw new RuntimeException('Each manifest set needs a name, a non-empty list of run ids and a sequence count.');
            }

            $sets[] = [
                'name' => $set['name'],
                'runs' => array_values(array_map('intval', $set['runs'])),
                'sequences' => $set['sequences'],
            ];
        }

        foreach (['truth', 'price_snapshot'] as $key) {
            if (! is_string($manifest[$key] ?? null) || ! is_file($manifest[$key])) {
                throw new RuntimeException("The manifest's {$key} must name a readable file.");
            }
        }

        foreach (['max_calls', 'max_spend_usd', 'reserve_per_call_usd'] as $key) {
            if (! is_numeric($manifest[$key] ?? null) || (float) $manifest[$key] <= 0) {
                throw new RuntimeException("The manifest must declare a positive {$key}.");
            }
        }

        if ($sets === []) {
            throw new RuntimeException('The manifest declares no sets.');
        }

        return [
            'truth' => $manifest['truth'],
            'price_snapshot' => $manifest['price_snapshot'],
            'max_calls' => (int) $manifest['max_calls'],
            'max_spend_usd' => (float) $manifest['max_spend_usd'],
            'reserve_per_call_usd' => (float) $manifest['reserve_per_call_usd'],
            'sets' => $sets,
        ];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException("Could not read JSON from {$path}.");
        }

        return $decoded;
    }

    private function codeVersion(): ?string
    {
        $head = Process::path(base_path())->run(['git', '-c', 'safe.directory=*', 'rev-parse', 'HEAD']);
        $status = Process::path(base_path())->run(['git', '-c', 'safe.directory=*', 'status', '--porcelain', '--untracked-files=no']);

        if (! $head->successful() || ! $status->successful()) {
            return null;
        }

        return trim($head->output()).(trim($status->output()) === '' ? '' : '-dirty');
    }

    /** @param  array<string, mixed>  $report */
    private function writeReport(string $path, array $report): void
    {
        File::put($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
