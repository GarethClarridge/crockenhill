<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Monitoring\Checks\ScheduledTasksCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Health\Enums\Status;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Tests\TestCase;

class ScheduledTasksCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_ok_when_no_tasks_are_monitored(): void
    {
        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertSame('All on time', $result->shortSummary);
    }

    #[Test]
    public function it_reports_ok_for_a_task_that_recently_ran_successfully(): void
    {
        MonitoredScheduledTask::create([
            'name' => 'health:check',
            'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 5,
            'last_started_at' => now(),
            'last_finished_at' => now(),
        ]);

        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
    }

    #[Test]
    public function it_fails_when_a_tasks_last_run_failed(): void
    {
        MonitoredScheduledTask::create([
            'name' => 'backup:run',
            'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 60,
            'last_started_at' => now()->subMinutes(10),
            'last_failed_at' => now()->subMinutes(9),
            'last_finished_at' => now()->subDay(),
        ]);

        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('backup:run failed its last run', $result->notificationMessage);
    }

    #[Test]
    public function it_fails_when_a_task_has_not_started_within_its_grace_period(): void
    {
        MonitoredScheduledTask::create([
            'name' => 'calendar:sync',
            'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 5,
            'last_started_at' => now()->subHours(2),
            'last_finished_at' => now()->subHours(2),
        ]);

        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('calendar:sync is overdue', $result->notificationMessage);
    }

    #[Test]
    public function it_fails_when_a_task_started_on_time_but_has_not_finished_within_its_grace_period(): void
    {
        MonitoredScheduledTask::create([
            'name' => 'backup:run',
            'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 5,
            'last_started_at' => now()->subMinutes(2),
            'last_finished_at' => now()->subHours(2),
        ]);

        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('backup:run is overdue', $result->notificationMessage);
    }

    #[Test]
    public function a_recovered_task_no_longer_reports_its_old_failure(): void
    {
        MonitoredScheduledTask::create([
            'name' => 'backup:run',
            'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 60,
            'last_started_at' => now(),
            'last_failed_at' => now()->subHour(),
            'last_finished_at' => now(),
        ]);

        $result = ScheduledTasksCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
    }
}
