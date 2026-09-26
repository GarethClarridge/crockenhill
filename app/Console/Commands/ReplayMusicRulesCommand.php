<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\DetectorEvaluation\FreezeDetectorCaseBook;
use App\Services\DetectorEvaluation\MusicRuleReplay;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The §8 rule replay of the music and silence plan: every action R1, R2 and R3 would take over
 * the banked structure of the eligible historic corpus, per rule and per service year.
 *
 * **Read-only, permanently.** There is no `--apply`: R1 edge moves, R2 and R3 move edges and
 * replace sections, which only a detection run may do. The report is what the operator's
 * precision gate listens to (§12 ruling 4) before canary 5.
 *
 * Grouped by service year, not era: era boundaries have not been derived from the corpus yet
 * (see {@see FreezeDetectorCaseBook}).
 *
 * @phpstan-import-type ReplayReport from MusicRuleReplay
 */
class ReplayMusicRulesCommand extends Command
{
    protected $signature = 'structure:replay-music-rules
        {--run=* : Processing run ids to replay}
        {--all : Every eligible completed, unsuperseded historic run}
        {--json : Emit the whole report as JSON}';

    protected $description = 'List every action the music and silence rules would take over banked structure (read-only)';

    public function handle(MusicRuleReplay $replay): int
    {
        ['eligible' => $runs, 'skipped' => $skipped] = $this->population();

        if ($runs === [] && $skipped === []) {
            $this->components->error('No runs matched. Pass --all or --run=.');

            return self::FAILURE;
        }

        $report = $replay->over($runs);

        if ($this->option('json')) {
            $this->line(CanonicalJson::encode([
                'population' => count($runs),
                'skipped_ineligible_run_ids' => $skipped,
                ...$report,
            ]));

            return self::SUCCESS;
        }

        $this->render($report, $skipped);

        return self::SUCCESS;
    }

    /**
     * @param  ReplayReport  $report
     * @param  list<int>  $skipped
     */
    private function render(array $report, array $skipped): void
    {
        $this->components->info(sprintf(
            'Read-only: %d runs assessed, %d unassessable',
            $report['runs_assessed'],
            count($report['unassessable']),
        ));

        if ($skipped !== []) {
            $this->components->warn(sprintf(
                'Skipped runs %s: excluded or not completed, as --all would skip them.',
                implode(', ', $skipped),
            ));
        }

        foreach ($report['unassessable'] as ['run' => $run, 'missing' => $missing]) {
            $this->components->warn(sprintf('Run %d is unassessable: no readable %s.', $run, implode(', ', $missing)));
        }

        $this->table(
            ['Rule', 'Actions', 'Runs'],
            array_map(
                static fn (string $rule, int $count): array => [$rule, (string) $count, (string) ($report['runs_by_rule'][$rule] ?? 0)],
                array_keys($report['actions_by_rule']),
                $report['actions_by_rule'],
            ),
        );

        $rules = array_keys($report['actions_by_rule']);
        $this->table(
            ['Year', ...$rules],
            array_map(
                static fn (string $year, array $counts): array => [$year, ...array_map(static fn (string $rule): string => (string) ($counts[$rule] ?? 0), $rules)],
                array_map('strval', array_keys($report['actions_by_year'])),
                $report['actions_by_year'],
            ),
        );

        $this->table(
            ['Run', 'Rule', 'Section', 'Before', 'After'],
            array_map(static fn (array $action): array => [
                (string) $action['run'],
                $action['rule'],
                trim($action['type'].' '.($action['title'] ?? '')),
                self::bounds($action['before']),
                self::bounds($action['after']),
            ], $report['actions']),
        );
    }

    /**
     * @param  array{0: float, 1: float}|null  $bounds
     */
    private static function bounds(?array $bounds): string
    {
        return $bounds === null ? '—' : sprintf('%.1f–%.1f', $bounds[0], $bounds[1]);
    }

    /**
     * The runs to replay, and the named runs left out. Named runs obey the same eligibility as
     * `--all`. Superseded runs are left out, as the timeline backfill leaves them out: their
     * structure is no longer current.
     *
     * @return array{eligible: list<MediaProcessingLog>, skipped: list<int>}
     */
    private function population(): array
    {
        /** @var list<string> $runIds */
        $runIds = (array) $this->option('run');

        if ($runIds !== []) {
            $wanted = array_map('intval', $runIds);
            $found = MediaProcessingLog::query()->with('churchService')->whereIn('id', $wanted)->orderBy('id')->get();
            $missing = array_values(array_diff($wanted, $found->pluck('id')->all()));

            if ($missing !== []) {
                throw new RuntimeException(
                    'These runs could not be found: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );
            }

            return [
                'eligible' => array_values($found->filter(fn (MediaProcessingLog $run): bool => $this->isEligible($run))->all()),
                'skipped' => array_values($found
                    ->reject(fn (MediaProcessingLog $run): bool => $this->isEligible($run))
                    ->map(static fn (MediaProcessingLog $run): int => (int) $run->id)
                    ->all()),
            ];
        }

        if (! $this->option('all')) {
            return ['eligible' => [], 'skipped' => []];
        }

        return [
            'eligible' => array_values(MediaProcessingLog::query()
                ->with('churchService')
                ->whereNotNull('historic_import_operation_id')
                ->where('status', 'completed')
                ->notSuperseded()
                ->orderBy('id')
                ->get()
                ->filter(fn (MediaProcessingLog $run): bool => $this->isEligible($run))
                ->all()),
            'skipped' => [],
        ];
    }

    private function isEligible(MediaProcessingLog $run): bool
    {
        return $run->historic_import_operation_id !== null
            && $run->status === ProcessingStatus::Completed
            && $run->superseded_at === null
            && ! $run->isExcluded();
    }
}
