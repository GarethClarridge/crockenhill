<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\MonitoredScheduledTasks;
use Tests\TestCase;

class SchedulerRegistrationTest extends TestCase
{
    /** @var array<int, Event>|null */
    private ?array $cachedEvents = null;

    /** @return array<int, Event> */
    private function scheduledEvents(): array
    {
        if ($this->cachedEvents === null) {
            // Booting the console kernel via artisan() populates the Schedule singleton.
            // We discard the output — we only want the side-effect of booting the kernel.
            $this->artisan('schedule:list')->run();
            $this->cachedEvents = $this->app->make(Schedule::class)->events();
        }

        return $this->cachedEvents;
    }

    private function findEvent(string $commandFragment): ?Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_contains($event->command, $commandFragment)) {
                return $event;
            }
        }

        return null;
    }

    #[Test]
    public function media_cleanup_temp_files_has_overlap_protection(): void
    {
        $event = $this->findEvent('media:cleanup-temp-files');

        $this->assertNotNull($event, 'media:cleanup-temp-files should be registered in the scheduler');
        $this->assertTrue($event->withoutOverlapping, 'media:cleanup-temp-files should have withoutOverlapping enabled');
    }

    #[Test]
    public function media_cleanup_temp_files_is_gated_to_production(): void
    {
        $event = $this->findEvent('media:cleanup-temp-files');

        $this->assertNotNull($event);
        $this->assertNotEmpty($event->environments, 'media:cleanup-temp-files should be environment-gated');
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function media_cleanup_unpublished_section_assets_has_overlap_protection(): void
    {
        $event = $this->findEvent('media:cleanup-unpublished-section-assets');

        $this->assertNotNull($event, 'media:cleanup-unpublished-section-assets should be registered in the scheduler');
        $this->assertTrue($event->withoutOverlapping, 'media:cleanup-unpublished-section-assets should have withoutOverlapping enabled');
    }

    #[Test]
    public function media_cleanup_unpublished_section_assets_is_gated_to_production(): void
    {
        $event = $this->findEvent('media:cleanup-unpublished-section-assets');

        $this->assertNotNull($event);
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function calendar_sync_is_gated_to_production(): void
    {
        $event = $this->findEvent('calendar:sync');

        $this->assertNotNull($event, 'calendar:sync should be registered in the scheduler');
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function scripture_refresh_passages_has_overlap_protection_and_is_gated_to_production(): void
    {
        $event = $this->findEvent('scripture:refresh-passages');

        $this->assertNotNull($event, 'scripture:refresh-passages should be registered in the scheduler');
        $this->assertTrue($event->withoutOverlapping);
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function health_check_is_scheduled_with_overlap_protection_and_gated_to_production(): void
    {
        $event = $this->findEvent('health:check');

        $this->assertNotNull($event, 'health:check should be registered in the scheduler');
        $this->assertTrue($event->withoutOverlapping, 'health:check should have withoutOverlapping enabled');
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function monitoring_history_pruning_is_scheduled_and_gated_to_production(): void
    {
        $event = $this->findEvent('model:prune');

        $this->assertNotNull($event, 'model:prune should be registered in the scheduler');
        $this->assertStringContainsString('HealthCheckResultHistoryItem', $event->command);
        $this->assertStringContainsString('MonitoredScheduledTaskLogItem', $event->command);
        $this->assertContains('production', $event->environments);
    }

    #[Test]
    public function the_retired_canary_checker_is_no_longer_scheduled(): void
    {
        $this->assertNull(
            $this->findEvent('monitoring:check-canaries'),
            'monitoring:check-canaries was folded into health:check (RouteCanariesCheck) and must not be scheduled'
        );
    }

    #[Test]
    public function backup_commands_are_scheduled_with_overlap_protection_on_one_server_and_gated_to_production(): void
    {
        foreach (['backup:clean', 'backup:run', 'backup:monitor'] as $command) {
            $event = $this->findEvent($command);

            $this->assertNotNull($event, "{$command} should be registered in the scheduler");
            $this->assertTrue($event->withoutOverlapping, "{$command} should have withoutOverlapping enabled");
            $this->assertTrue($event->onOneServer, "{$command} should run on one server only");
            $this->assertContains('production', $event->environments);
        }
    }

    #[Test]
    public function the_schedule_heartbeat_runs_every_minute_unmonitored_and_gated_to_production(): void
    {
        $event = $this->findEvent('health:schedule-check-heartbeat');

        $this->assertNotNull($event, 'health:schedule-check-heartbeat should be registered: ScheduleCheck verifies its cache timestamp');
        $this->assertSame('* * * * *', $event->expression, 'the heartbeat must run every minute');
        $this->assertContains('production', $event->environments);

        $this->assertTrue(
            (bool) $this->app->make(MonitoredScheduledTasks::class)->getDoNotMonitor($event),
            'the heartbeat should be excluded from schedule-monitor — ScheduleCheck is its monitor, and per-minute runs would flood the task log'
        );
    }

    #[Test]
    public function long_running_tasks_have_grace_times_matching_their_overlap_lock_windows(): void
    {
        $expectedGraceTimes = [
            'media:cleanup-temp-files' => 60,
            'media:cleanup-unpublished-section-assets' => 30,
            'scripture:refresh-passages' => 60,
            'backup:clean' => 60,
            'backup:run' => 120,
            'backup:monitor' => 30,
        ];

        $registry = $this->app->make(MonitoredScheduledTasks::class);

        foreach ($expectedGraceTimes as $command => $minutes) {
            $event = $this->findEvent($command);

            $this->assertNotNull($event, "{$command} should be registered in the scheduler");
            $this->assertSame(
                $minutes,
                $registry->getGraceTimeInMinutes($event),
                "{$command} should have a grace time matching its withoutOverlapping lock window, so ScheduledTasksCheck does not report a normal run as overdue"
            );
        }
    }
}
