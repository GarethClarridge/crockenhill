<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
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
}
