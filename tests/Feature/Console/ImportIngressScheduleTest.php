<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Import\ImportIngressGate;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §15.2: "the separate scheduler cannot enqueue affected work while the lock is
 * active."
 *
 * The two media cleanup commands are the sharpest case, because they *delete*
 * media on an age heuristic. A production import window is precisely when the
 * importer is writing assets to destinations that no publication points at yet,
 * so a sweep landing mid-operation would remove the files it had just created.
 *
 * This lives in its own class deliberately. Laravel defines the console
 * schedule only in a console context: once a test in the same class has issued
 * an HTTP request, `app(Schedule::class)` resolves with zero events and any
 * assertion over it silently passes on an empty set. Keeping this class free of
 * HTTP requests is what makes the assertions below mean anything.
 */
class ImportIngressScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @var Collection<int, Event> */
    private Collection $events;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * The guarded commands are `->environments(['production'])`, which is
         * itself a reject filter. Without this the filters would fail for that
         * reason alone and the test would pass whether or not the skip exists.
         */
        $this->app['env'] = 'production';

        /**
         * Both guarded commands use `withoutOverlapping()`, which is itself a
         * cache-backed skip filter. A mutex left behind by a previous test would
         * make the filters fail for that reason instead of the ingress lock.
         */
        Cache::flush();

        $this->events = $this->ingressAffectedEvents();
    }

    /**
     * The three states are asserted in one test rather than three, because
     * Laravel populates the console schedule only on its first resolution
     * within a test class: a second test reads an empty schedule and every
     * assertion over it passes vacuously. One method, one resolution, and the
     * before/during/after sequence is the behaviour that actually matters.
     */
    #[Test]
    public function media_cleanup_is_skipped_only_while_ingress_is_blocked(): void
    {
        $gate = app(ImportIngressGate::class);

        $this->assertTrue(
            $this->events->every(fn (Event $event): bool => $event->filtersPass($this->app)),
            'The guarded commands must be runnable before the window opens, or the next assertion proves nothing.',
        );

        $gate->block('historic-import-1', 'Historic archive import window');

        $this->assertTrue(
            $this->events->every(fn (Event $event): bool => $event->filtersPass($this->app) === false),
            'Every ingress-affected scheduled command must skip while the lock is held.',
        );

        $gate->release('historic-import-1');

        $this->assertTrue(
            $this->events->every(fn (Event $event): bool => $event->filtersPass($this->app)),
            'Cleanup must resume after the window; a permanent skip would leak temp media indefinitely.',
        );
    }

    /**
     * Commands that delete media and therefore must not run during a window.
     *
     * @return Collection<int, Event>
     */
    private function ingressAffectedEvents(): Collection
    {
        $guarded = ['media:cleanup-temp-files', 'media:cleanup-unpublished-section-assets'];

        /**
         * `withSchedule()` registers its definition inside an `Artisan::starting`
         * hook, as an `afterResolving(Schedule::class)` callback. So the schedule
         * is populated only once a console application has booted in this
         * process and the Schedule is resolved *after* that — read it any other
         * way and it comes back empty, and every assertion over it passes
         * vacuously. Running a command, then forgetting the cached instance,
         * makes this hold regardless of what the worker ran beforehand.
         */
        $this->artisan('import:ingress', ['action' => 'status'])->run();
        $this->app->forgetInstance(Schedule::class);

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => collect($guarded)->contains(
                fn (string $command): bool => str_contains((string) $event->command, $command),
            ))
            ->values();

        /**
         * Match on the distinct commands rather than the event count: the
         * schedule definition runs more than once when the console kernel is
         * bootstrapped inside a test, so each command appears more than once.
         * Every registration still has to carry the guard, which is why the
         * duplicates are kept in the returned collection rather than filtered.
         */
        $found = $events
            ->map(fn (Event $event): string => (string) $event->command)
            ->flatMap(fn (string $command): array => collect($guarded)
                ->filter(fn (string $guard): bool => str_contains($command, $guard))
                ->values()
                ->all())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect($guarded)->sort()->values()->all(),
            $found,
            'Both media cleanup commands must be scheduled for this guard to be meaningful.',
        );

        return $events;
    }
}
