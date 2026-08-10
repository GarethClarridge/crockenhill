<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\ApiTokenAbility;
use App\Exceptions\HistoricImportFrozen;
use App\Models\Sermon;
use App\Services\Import\HistoricImportMutationFreeze;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

/**
 * F46 freezes targeted admin/data mutation for the production import window.
 * These cover how that refusal *presents*: a bare exception surfaces as HTTP
 * 500, which an operator cannot tell apart from the incident the window's
 * monitoring exists to catch.
 */
class HistoricImportFreezeRefusalTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    /** @var list<string> */
    private array $approvalPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->approvalPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_frozen_mutation_names_the_operation_and_the_end_of_the_window(): void
    {
        Config::set('church.historic_corpus.production_import_approval', $this->approval(
            'historic-window-1',
            now()->addHours(3)->toIso8601String(),
        ));

        try {
            Sermon::factory()->create();
            $this->fail('The mutation freeze did not refuse an ordinary targeted write.');
        } catch (HistoricImportFrozen $exception) {
            $this->assertSame('historic-window-1', $exception->operationId);
            $this->assertStringContainsString('paused for the historic import window', $exception->getMessage());
            $this->assertStringContainsString('historic-window-1', $exception->getMessage());
            $this->assertNotNull($exception->retryAfterSeconds());
        }
    }

    /**
     * An unreadable approval is a reason to keep refusing, not to open the
     * freeze — it just refuses without being able to name the operation.
     */
    #[Test]
    public function an_unreadable_approval_still_refuses_without_operator_context(): void
    {
        Config::set('church.historic_corpus.production_import_approval', '/nonexistent/approval.json');

        try {
            Sermon::factory()->create();
            $this->fail('A malformed approval opened the freeze.');
        } catch (HistoricImportFrozen $exception) {
            $this->assertNull($exception->operationId);
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertStringContainsString('paused for the historic import window', $exception->getMessage());
        }
    }

    /**
     * The approval expiring does not lift the freeze — removing the artifact
     * does — so an elapsed window must not promise an immediate retry.
     */
    #[Test]
    public function an_elapsed_window_advertises_no_retry_delay(): void
    {
        Config::set('church.historic_corpus.production_import_approval', $this->approval(
            'historic-window-2',
            now()->subHour()->toIso8601String(),
        ));

        try {
            Sermon::factory()->create();
            $this->fail('The mutation freeze did not refuse after the window elapsed.');
        } catch (HistoricImportFrozen $exception) {
            $this->assertNull($exception->retryAfterSeconds());
        }
    }

    /**
     * The branch an operator actually meets: Livewire's XHR endpoint and the
     * API routes both expect JSON, and no current web route writes a frozen
     * model. Rendering is exercised through the handler because the codebase
     * does not define ad-hoc test routes.
     */
    #[Test]
    public function a_json_request_is_refused_with_a_retry_after_rather_than_a_server_error(): void
    {
        $response = app(ExceptionHandler::class)->render(
            Request::create('/api/services/openlp', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
            new HistoricImportFrozen('historic-window-3', new DateTimeImmutable('+2 hours')),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringContainsString('paused for the historic import window', (string) $response->getContent());
        $this->assertStringContainsString('historic-window-3', (string) $response->getContent());
    }

    /**
     * Kept because a future web write must not regress to the generic 500, and
     * because the operator-facing reason must replace the public maintenance
     * copy rather than sit alongside it.
     */
    #[Test]
    public function a_browser_request_renders_the_reason_in_place_of_the_generic_maintenance_copy(): void
    {
        $response = app(ExceptionHandler::class)->render(
            Request::create('/admin/services/inbox', 'POST'),
            new HistoricImportFrozen('historic-window-4', new DateTimeImmutable('+2 hours')),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringContainsString('paused for the historic import window', (string) $response->getContent());
        $this->assertStringNotContainsString(
            'Please try again shortly',
            (string) $response->getContent(),
            'The generic maintenance paragraph should be replaced by the refusal reason.',
        );
    }

    /**
     * A known limitation, asserted so it cannot regress silently: several API
     * controllers wrap their work in a broad `catch (\Exception)`, which
     * swallows the refusal and returns their own error shape instead of the
     * 503. The security-relevant property still holds — the write is blocked —
     * but the operator loses the "frozen for the import window" message there.
     */
    #[Test]
    public function a_controller_that_swallows_exceptions_still_blocks_the_write(): void
    {
        $this->fakeMediaDisks(['local']);
        $token = $this->createVerifiedAdmin()
            ->createToken('freeze-test', [ApiTokenAbility::MediaProcess->value])
            ->plainTextToken;
        Config::set('church.historic_corpus.production_import_approval', $this->approval(
            'historic-window-7',
            now()->addHours(2)->toIso8601String(),
        ));

        $response = $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $this->fakeAudioUpload()]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    /**
     * The only scheduled task that writes a frozen model. Its existing skip is
     * keyed on the ingress gate, but F46 lifts the freeze only after audit,
     * smoke and queue/scheduler recovery — so the freeze outlives the ingress
     * release, and the ingress predicate alone leaves this task failing.
     */
    #[Test]
    public function the_section_asset_cleanup_task_skips_while_the_freeze_is_on(): void
    {
        $event = $this->scheduledEvent('media:cleanup-unpublished-section-assets');

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->assertTrue($event->filtersPass($this->app), 'The task should run when nothing is frozen.');

        Config::set('church.historic_corpus.production_import_approval', $this->approval(
            'historic-window-5',
            now()->addHours(2)->toIso8601String(),
        ));
        $this->assertFalse($event->filtersPass($this->app), 'The task should skip while the freeze is on.');
    }

    #[Test]
    public function an_authorised_process_is_not_frozen(): void
    {
        Config::set('church.historic_corpus.production_import_approval', $this->approval(
            'historic-window-6',
            now()->addHours(2)->toIso8601String(),
        ));

        $freeze = app(HistoricImportMutationFreeze::class);
        $this->assertTrue($freeze->isFrozen());

        $freeze->authorize('historic-window-6');

        $this->assertFalse($freeze->isFrozen());
        Sermon::factory()->create();
        $this->assertDatabaseCount('sermons', 1);
    }

    private function scheduledEvent(string $command): Event
    {
        // Artisan must be booted before the schedule is populated, or every
        // assertion below passes vacuously against an empty schedule.
        $this->artisan('list')->run();

        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("The schedule has no {$command} task.");
    }

    private function approval(string $operationId, string $expiresAt): string
    {
        $path = sys_get_temp_dir().'/historic-freeze-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'operation_id' => $operationId,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
        $this->approvalPaths[] = $path;

        return $path;
    }
}
