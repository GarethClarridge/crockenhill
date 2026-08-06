<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportIngressLock;
use App\Services\Import\HorizonPauseAccounting;
use App\Services\Import\ImportIngressGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §15.2's fourth ingress requirement: "if Horizon is paused globally rather than
 * by affected queue, the budget/report explicitly records the delay imposed on
 * unrelated default/background work."
 *
 * The premise the requirement is conditional on holds in this application, and
 * these tests pin why: `supervisor-media` serves `default` in the same queue list
 * as the media queues, so no pause stops import work alone. The report therefore
 * owes a delay figure, and the accounting produces one from the window itself
 * rather than from an operator's recollection.
 */
class ImportIngressQueuePauseAccountingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pausing_the_import_supervisors_also_stops_the_default_queue(): void
    {
        $accounting = app(HorizonPauseAccounting::class);

        $this->assertArrayHasKey('supervisor-media', $accounting->supervisorsToPause());
        $this->assertContains('default', $accounting->collateralQueues());
        $this->assertFalse(
            $accounting->queueGranularPauseIsPossible(),
            'supervisor-media serves default alongside the media queues, so a queue-granular pause is not available.',
        );
    }

    /**
     * The dedicated media and historic queues carry only import work, so pausing
     * them costs nothing outside the window and they must not be counted as
     * collateral.
     */
    #[Test]
    public function the_dedicated_media_and_historic_queues_are_not_counted_as_collateral(): void
    {
        $accounting = app(HorizonPauseAccounting::class);

        $this->assertContains('video-processing', $accounting->importQueues());
        $this->assertContains('historic-whisper', $accounting->importQueues());
        $this->assertNotContains('video-processing', $accounting->collateralQueues());
        $this->assertNotContains('historic-whisper', $accounting->collateralQueues());
    }

    /**
     * `media-processing.queues.default` resolves to the application-wide default
     * queue. Treating that key as import-only would erase the entire finding.
     */
    #[Test]
    public function the_shared_default_queue_is_never_classed_as_import_only(): void
    {
        $this->assertNotContains('default', app(HorizonPauseAccounting::class)->importQueues());
    }

    /**
     * A supervisor layout that isolates the media queues makes a queue-granular
     * pause possible, and the accounting has to say so rather than repeat a
     * finding that has stopped being true.
     */
    #[Test]
    public function a_supervisor_split_that_isolates_import_queues_removes_the_collateral(): void
    {
        Config::set('horizon.defaults', [
            'supervisor-media' => ['connection' => 'redis', 'queue' => ['video-processing', 'audio-processing']],
            'supervisor-default' => ['connection' => 'redis', 'queue' => ['default']],
        ]);
        Config::set('horizon.environments', []);

        $accounting = app(HorizonPauseAccounting::class);

        $this->assertSame(['supervisor-media'], array_keys($accounting->supervisorsToPause()));
        $this->assertSame([], $accounting->collateralQueues());
        $this->assertTrue($accounting->queueGranularPauseIsPossible());
        $this->assertStringContainsString(
            'no unrelated background work is delayed',
            $accounting->summarise($accounting->atBlock()),
        );
    }

    #[Test]
    public function blocking_a_window_records_the_collateral_and_its_depth(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $accounting = ImportIngressLock::query()->sole()->queue_pause_accounting;

        $this->assertIsArray($accounting);
        $this->assertFalse($accounting['queue_granular_pause_possible']);
        $this->assertContains('default', $accounting['collateral_queues']);
        $this->assertArrayHasKey('default', $accounting['collateral_depth_at_block']);
        $this->assertArrayHasKey('supervisor-media', $accounting['supervisors_to_pause']);
    }

    /**
     * The delay figure §15.2 asks for: how long unrelated work was held, and how
     * much of it was still waiting when the window closed.
     */
    #[Test]
    public function releasing_a_window_records_the_delay_imposed_on_unrelated_work(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');

        $this->travel(37)->minutes();

        Queue::push('SomeUnrelatedBackgroundJob', '', 'default');
        Queue::push('AnotherUnrelatedBackgroundJob', '', 'default');

        $lock = $gate->release('historic-import-1');
        $accounting = $lock->queue_pause_accounting;

        $this->assertSame(37, $accounting['collateral_delay_minutes']);
        $this->assertSame(2, $accounting['collateral_jobs_delayed']);
        $this->assertSame(2, $accounting['collateral_depth_at_release']['default']);
    }

    #[Test]
    public function the_release_summary_states_the_delay_for_the_closeout_report(): void
    {
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');

        $this->travel(12)->minutes();

        $lock = $gate->release('historic-import-1');
        $summary = app(HorizonPauseAccounting::class)->summarise($lock->queue_pause_accounting);

        $this->assertStringContainsString('Unrelated background work on', $summary);
        $this->assertStringContainsString('default', $summary);
        $this->assertStringContainsString('delayed by up to 12 minute(s)', $summary);
    }

    /**
     * The operator has to know which supervisors to pause and what else stops
     * when they do, before the window opens rather than in the closeout.
     */
    #[Test]
    public function blocking_tells_the_operator_which_supervisors_to_pause_and_what_else_stops(): void
    {
        $collateral = implode(', ', app(HorizonPauseAccounting::class)->collateralQueues());

        $this->artisan('import:ingress', [
            'action' => 'block',
            '--operation' => 'historic-import-1',
            '--reason' => 'Historic archive import window',
        ])
            ->expectsOutputToContain('horizon:pause-supervisor supervisor-media')
            ->expectsOutputToContain("also stops unrelated work on: {$collateral}")
            ->assertSuccessful();

        $this->assertContains('default', app(HorizonPauseAccounting::class)->collateralQueues());
    }

    #[Test]
    public function releasing_reports_the_delay_and_how_to_resume_the_supervisors(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->travel(5)->minutes();

        $this->artisan('import:ingress', ['action' => 'release', '--operation' => 'historic-import-1'])
            ->expectsOutputToContain('horizon:continue-supervisor')
            ->expectsOutputToContain('delayed by up to 5 minute(s)')
            ->assertSuccessful();
    }

    /**
     * An unreadable queue must not be able to stop an operator blocking ingress,
     * and must not be reported as an empty one either.
     */
    #[Test]
    public function an_unreadable_queue_is_recorded_as_unmeasured_rather_than_empty(): void
    {
        Config::set('queue.connections.broken', ['driver' => 'no-such-driver']);
        Config::set('horizon.defaults.supervisor-media.connection', 'broken');
        Config::set('horizon.environments', []);

        $lock = app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->assertNull($lock->queue_pause_accounting['collateral_depth_at_block']['default']);
    }
}
