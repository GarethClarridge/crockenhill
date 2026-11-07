<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Queries\ServiceReviewDashboardQuery;
use App\Queries\ServiceReviewInventoryQuery;
use App\Services\ChurchService\ReviewInventory\PendingApplication;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Writes the exact review inventory (video plan §4.1d RA1): one record per actionable reason with
 * its disposition, stage and next operation, joined to a read-only final-code replay so built but
 * unapplied fixes are not counted as automation work, plus the per-run application routes
 * (§4.1a step 3), service-only and merge obligations and stopped runs. Read-only: it writes
 * files to --output and nothing else.
 *
 * Deletion trigger: delete once RA1–RA6 are closed and the round they prepare has been verified
 * (video plan §4.1d "Verification, sequencing and stopping").
 */
class ReviewInventoryCommand extends Command
{
    protected $signature = 'historic-import:review-inventory
        {--replay= : Directory of a read-only final-code replay (rows*.jsonl); without it nothing counts as pending application}
        {--output= : Directory for inventory.jsonl, summary.json and routes.json}
        {--hand-edited=942,974,1363 : Runs whose hand-edited structure is never recomposed (X7)}';

    protected $description = 'Inventory every actionable review reason with its disposition and next operation (RA1); read-only';

    private const DISPOSITIONS = ['resolved_by_current_evidence', 'resolved_by_pending_application', 'automatic_repair', 'human_decision', 'unassessable'];

    public function handle(ServiceReviewInventoryQuery $inventory, ServiceReviewDashboardQuery $dashboard): int
    {
        $output = (string) $this->option('output');
        if ($output === '' || (! is_dir($output) && ! mkdir($output, 0755, true))) {
            $this->components->error('--output must name a writable directory.');

            return self::FAILURE;
        }

        try {
            $replay = (string) $this->option('replay');
            $pending = $replay === '' ? PendingApplication::none() : PendingApplication::fromReplayDirectory($replay);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($replay === '') {
            $this->warn('No --replay: built but unapplied work is not taken into account.');
        }

        $handle = fopen($output.'/inventory.jsonl', 'w');
        if ($handle === false) {
            $this->components->error("Cannot write {$output}/inventory.jsonl.");

            return self::FAILURE;
        }
        $byReason = [];
        $byStage = [];
        $sections = [];
        foreach ($inventory->records($pending) as $record) {
            fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $key = $record['reason'].($record['detail'] === null ? '' : ':'.$record['detail']);
            $stage = $record['stage'] ?? '—';
            $byReason[$key][$record['disposition']] = ($byReason[$key][$record['disposition']] ?? 0) + 1;
            $byStage[$stage][$record['disposition']] = ($byStage[$stage][$record['disposition']] ?? 0) + 1;
            $sections[$record['section_id']][] = $record;
        }
        fclose($handle);

        // Parity with the dashboard (contract C1): the inventory must count exactly its sections.
        $dashboardCount = $dashboard->reviewCandidateSectionCount();
        if (count($sections) !== $dashboardCount) {
            $this->components->error(sprintf('Inventory covers %d sections, the dashboard counts %d.', count($sections), $dashboardCount));

            return self::FAILURE;
        }

        $outcomes = $this->sectionOutcomes($sections);
        $byStage = $this->sorted($byStage);
        $serviceOnly = ChurchService::query()->where('needs_review', true)->orderBy('date')->get(['id', 'date', 'service'])
            ->map(fn (ChurchService $service): array => ['id' => $service->id, 'date' => $service->date->toDateString(), 'service' => $service->service->value])->all();
        $merges = $dashboard->pendingMergeServicesQuery()->orderBy('date')->pluck('id')->all();
        $stopped = $this->stoppedRuns();
        $records = array_sum(array_map('count', $sections));
        $handEdited = array_values(array_map('intval', array_filter(explode(',', (string) $this->option('hand-edited')))));
        $routes = $pending->routes($handEdited);
        // A stopped run cannot take these operations until its own failure has a route (945, 1143).
        foreach ($stopped as $run) {
            if (isset($routes[$run['id']])) {
                $routes[$run['id']] = ["stopped run ({$run['status']} at {$run['current_step']}): route its failure first", ...$routes[$run['id']]];
            }
        }

        file_put_contents($output.'/summary.json', json_encode([
            'replay' => $replay === '' ? null : realpath($replay),
            'sections' => count($sections),
            'records' => $records,
            ...$outcomes,
            'by_reason' => $this->sorted($byReason),
            'by_stage' => $byStage,
            'service_only_obligations' => $serviceOnly,
            'pending_merges' => $merges,
            'stopped_runs' => $stopped,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        file_put_contents($output.'/routes.json', json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->table(['Stage', ...self::DISPOSITIONS], array_map(
            fn (string $stage, array $counts): array => [$stage, ...array_map(fn (string $disposition): int => $counts[$disposition] ?? 0, self::DISPOSITIONS)],
            array_keys($byStage), $byStage,
        ));
        $this->line(sprintf(
            '%d sections, %d records. Processing review: %d sections; %d clear without human work, %d need only automatic stages or pending work, %d keep a human decision, %d blocked on a missing input. %d service-only, %d merges, %d stopped runs (%d excluded). %d runs routed.',
            count($sections), $records, $outcomes['processing_review_sections'], $outcomes['removable_sections'],
            $outcomes['automatable_sections'], $outcomes['human_sections'], $outcomes['unassessable_sections'],
            count($serviceOnly), count($merges),
            count($stopped), count(array_filter($stopped, fn (array $run): bool => $run['excluded'] !== null)),
            count($routes),
        ));

        return self::SUCCESS;
    }

    /**
     * Sections by what stands between them and leaving processing review. Publication approval
     * is a separate obligation, so a section with only that reason is not processing review.
     *
     * @param  array<int, list<array<string, mixed>>>  $sections
     * @return array<string, int>
     */
    private function sectionOutcomes(array $sections): array
    {
        $outcomes = ['processing_review_sections' => 0, 'removable_sections' => 0, 'automatable_sections' => 0, 'human_sections' => 0, 'unassessable_sections' => 0];
        foreach ($sections as $records) {
            $processing = array_values(array_filter($records, fn (array $record): bool => $record['reason'] !== 'pending_approval'));
            if ($processing === []) {
                continue;
            }
            $outcomes['processing_review_sections']++;
            $dispositions = array_column($processing, 'disposition');
            match (true) {
                array_all($processing, fn (array $record): bool => $record['clears_without_human_work']) => $outcomes['removable_sections']++,
                in_array('human_decision', $dispositions, true) => $outcomes['human_sections']++,
                in_array('unassessable', $dispositions, true) => $outcomes['unassessable_sections']++,
                default => $outcomes['automatable_sections']++,
            };
        }

        return $outcomes;
    }

    /**
     * Runs that are neither completed nor superseded; excluded ones are listed apart from unresolved work.
     *
     * @return list<array{id: int, status: string, current_step: string|null, excluded: string|null, error: string|null}>
     */
    private function stoppedRuns(): array
    {
        return array_values(MediaProcessingLog::query()->notSuperseded()
            ->where('status', '!=', ProcessingStatus::Completed->value)
            ->orderBy('id')
            ->get(['id', 'status', 'current_step', 'error_message', 'processing_metadata'])
            ->map(fn (MediaProcessingLog $run): array => [
                'id' => $run->id,
                'status' => $run->status->value,
                'current_step' => $run->current_step,
                'excluded' => $run->exclusionReason(),
                'error' => $run->error_message === null ? null : mb_substr($run->error_message, 0, 200),
            ])->all());
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @return array<string, array<string, int>>
     */
    private function sorted(array $counts): array
    {
        uasort($counts, fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        return $counts;
    }
}
