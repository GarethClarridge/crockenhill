<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Checks;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

/**
 * Surfaces schedule-monitor's records through /health: a monitored scheduled
 * task is reported when its last run failed (last_failed_at newer than
 * last_finished_at — the package updates exactly one of the two per run) or
 * when it has not started within its grace period of the expected cron time.
 * A dead scheduler shows up as every task going overdue at once. The task
 * table is populated by `schedule-monitor:sync` in the deploy hook, so an
 * empty table (local, tests) reports healthy.
 */
class ScheduledTasksCheck extends Check
{
    public function run(): Result
    {
        $problems = MonitoredScheduledTask::query()
            ->get()
            ->flatMap(fn (MonitoredScheduledTask $task): array => $this->problemsFor($task))
            ->all();

        if ($problems === []) {
            return Result::make()
                ->ok()
                ->shortSummary('All on time');
        }

        return Result::make()
            ->failed('Scheduled task problems: '.implode('; ', $problems))
            ->shortSummary(count($problems).' failing');
    }

    /**
     * @return list<string>
     */
    private function problemsFor(MonitoredScheduledTask $task): array
    {
        $problems = [];

        if ($this->lastRunFailed($task)) {
            $problems[] = "{$task->name} failed its last run ({$task->last_failed_at?->diffForHumans()})";
        }

        if ($this->isOverdue($task)) {
            $reference = $task->last_started_at ?? $task->created_at;
            $problems[] = "{$task->name} is overdue (last started {$reference?->diffForHumans()})";
        }

        return $problems;
    }

    private function lastRunFailed(MonitoredScheduledTask $task): bool
    {
        if ($task->last_failed_at === null) {
            return false;
        }

        return $task->last_finished_at === null || $task->last_failed_at->gte($task->last_finished_at);
    }

    private function isOverdue(MonitoredScheduledTask $task): bool
    {
        $reference = $task->last_started_at ?? $task->created_at;

        if ($reference === null) {
            return false;
        }

        $expectedNextStart = Carbon::instance(
            (new CronExpression($task->cron_expression))->getNextRunDate(
                $reference->toDateTime(),
                0,
                false,
                $task->timezone ?? config('app.timezone'),
            )
        );

        return now()->gt($expectedNextStart->addMinutes($task->grace_time_in_minutes));
    }
}
