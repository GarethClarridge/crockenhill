<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\Email\OosSemanticParserCandidate;
use App\Services\Import\HorizonPauseAccounting;
use App\Services\Import\ImportIngressGate;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FixedOosSemanticParserCandidate;
use Tests\TestCase;

/**
 * §15.2. A production import window refuses new media-processing and
 * archive-import submissions, without taking the site down and without losing
 * anything that arrives while it is closed.
 *
 * The distinction the whole package turns on: an authenticated upload may be
 * *refused*, because the operator still holds the file and the response says
 * when to retry. An inbound webhook may not, because the sender is Mailgun and
 * a refusal risks dropping an order of service. So evidence is still accepted
 * and staged durably; only the processing it would trigger is deferred.
 */
class ImportIngressGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Config::set('service-tracking.mailgun.signing_key', 'test-signing-key');
        Cache::flush();

        /**
         * HIR6 runs the deferred job in several of these cases rather than
         * stopping at the queue push, so the extraction the parser would
         * otherwise reach for over the network is answered locally. One
         * confident morning order, which is what a real order of service
         * staged during a window looks like.
         */
        $this->app->instance(OosSemanticParserCandidate::class, FixedOosSemanticParserCandidate::using(function (OosEmailSourceDocument $source): OosEmailItemExtractionResult {
            $items = [['type' => 'song', 'title' => 'Amazing Grace']];

            return new OosEmailItemExtractionResult(
                items: $items,
                confidence: 0.99,
                services: [[
                    'service' => 'morning',
                    'date' => $source->receivedDate,
                    'items' => $items,
                    'confidence' => 0.99,
                ]],
            );
        }));
    }

    #[Test]
    public function a_blocked_window_refuses_new_media_uploads_with_a_retryable_response(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $response = $this->actingAs($user)->postJson('/api/media/livestream', [
            'file' => UploadedFile::fake()->create('livestream.mp4', 100, 'video/mp4'),
        ]);

        $response->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJson([
                'status' => 'import_ingress_blocked',
                'operation_id' => 'historic-import-1',
            ]);

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function a_blocked_window_refuses_a_processing_retry(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->actingAs($user)
            ->postJson('/api/media/processing/does-not-matter/retry')
            ->assertStatus(503);
    }

    /**
     * The refusal must be temporary in fact, not only in status code.
     */
    #[Test]
    public function releasing_the_window_reopens_media_uploads(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $gate->release('historic-import-1');

        $response = $this->actingAs($user)->postJson('/api/media/livestream', [
            'file' => UploadedFile::fake()->create('livestream.mp4', 100, 'video/mp4'),
        ]);

        $this->assertNotSame(503, $response->getStatusCode());
    }

    /**
     * Losslessness, §15.2's second requirement. Mailgun's delivery is accepted
     * and durably staged; only the processing it would have triggered waits.
     */
    #[Test]
    public function inbound_order_of_service_email_is_still_staged_while_ingress_is_blocked(): void
    {
        Queue::fake();
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload())
            ->assertStatus(202)
            ->assertJson(['status' => 'deferred']);

        $this->assertDatabaseHas('inbound_emails', [
            'message_id' => '<staged-during-window@example.com>',
            'status' => InboundEmailStatus::Pending->value,
        ]);
        Queue::assertNotPushed(ProcessInboundOosEmail::class);
    }

    #[Test]
    public function email_staged_during_the_window_is_processed_when_ingress_reopens(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');

        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());

        Queue::assertNotPushed(ProcessInboundOosEmail::class);

        $this->artisan('import:ingress', ['action' => 'release', '--operation' => 'historic-import-1'])
            ->assertSuccessful();

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
        $this->assertDatabaseHas('import_deferred_inbound_emails', [
            'operation_id' => 'historic-import-1',
            'state' => 'dispatched',
        ]);
    }

    #[Test]
    public function inbound_email_still_dispatches_normally_when_ingress_is_open(): void
    {
        Queue::fake();

        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload())
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    #[Test]
    public function only_one_window_can_hold_ingress_at_a_time(): void
    {
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'First window');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already blocked by operation historic-import-1');

        $gate->block('historic-import-2', 'Second window');
    }

    #[Test]
    public function a_window_cannot_be_released_by_another_operation(): void
    {
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocked by operation historic-import-1, not historic-import-2');

        $gate->release('historic-import-2');
    }

    #[Test]
    public function a_released_window_is_retained_for_the_closeout_report(): void
    {
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window', 'operator@crockenhill.test');
        $gate->release('historic-import-1');

        $lock = ImportIngressLock::query()->sole();

        $this->assertNotNull($lock->released_at);
        $this->assertSame('operator@crockenhill.test', $lock->blocked_by);
        $this->assertSame('Historic archive import window', $lock->reason);
        $this->assertFalse($gate->isBlocked());
    }

    /**
     * Blocking ingress is not taking the site down; §15.2 prohibits `artisan
     * down` for this operation unless separately approved.
     */
    #[Test]
    public function public_reads_stay_online_while_ingress_is_blocked(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->get(route('sermons.index'))->assertSuccessful();
        $this->get(route('home'))->assertSuccessful();
    }

    #[Test]
    public function the_status_action_reports_the_holding_operation(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->artisan('import:ingress', ['action' => 'status'])
            ->expectsOutputToContain('historic-import-1')
            ->assertSuccessful();
    }

    #[Test]
    public function blocking_requires_an_operation_and_a_reason(): void
    {
        $this->artisan('import:ingress', ['action' => 'block'])
            ->expectsOutputToContain('requires --operation and --reason')
            ->assertFailed();

        $this->assertDatabaseCount('import_ingress_locks', 0);
    }

    #[Test]
    public function release_drains_only_its_operation_outbox_and_never_sweeps_the_ordinary_pending_inbox(): void
    {
        Queue::fake();
        $ordinary = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending]);
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());

        $gate->release('historic-import-1');
        $this->assertDatabaseHas('import_deferred_inbound_emails', [
            'operation_id' => 'historic-import-1',
            'state' => 'pending',
        ]);
        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
        $this->assertSame(InboundEmailStatus::Pending, $ordinary->fresh()->status);

        /**
         * HIR6: the drain hands the email to the queue, and the queue is where
         * the work happens. Reconciliation only follows once the job has run.
         */
        $this->assertSame(1, $this->runQueuedInboundJobs());
        $this->assertSame(InboundEmailStatus::Pending, $ordinary->fresh()->status);
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    /**
     * HIR0 red test for review finding 7 (Medium), owned by package **HIR6**.
     *
     * `assertDeferredInboundEmailReconciled()` and the operational-closeout
     * evidence both accept `dispatched` as terminal. But `dispatched` records a
     * queue handoff, not an outcome: `ProcessInboundOosEmail` writes the
     * distinct `processed` state only after parse/import succeeds.
     *
     * Under `Queue::fake()` nothing runs at all, which is the same durable state
     * as a job still queued, still executing, or about to fail permanently in
     * production. The operation can therefore complete exact closeout while an
     * order of service that arrived during the freeze has not been imported, and
     * its "pending = 0 / failed = 0" evidence is stale the moment the job fails
     * and returns the row to `pending`.
     *
     * This deliberately contradicts the `dispatched`-is-enough assertions in
     * {@see self::release_drains_only_its_operation_outbox_and_never_sweeps_the_ordinary_pending_inbox()}
     * and {@see self::reopening_and_drain_are_separate_retry_safe_steps()},
     * which the review names as codifying the gap. They are superseded
     * evidence: HIR6 runs the job in those cases rather than deleting them.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 7
     * @see docs/archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §12 (HIR6)
     */
    #[Test]
    #[Group('hir-red')]
    public function a_queued_but_unprocessed_deferred_email_does_not_count_as_reconciled(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        Queue::assertPushed(ProcessInboundOosEmail::class, 1);

        // Handed to the queue and never executed: the job wrote no outcome, so
        // the row is exactly as unfinished as a queued one in production.
        $this->assertDatabaseHas('import_deferred_inbound_emails', [
            'operation_id' => 'historic-import-1',
            'state' => 'dispatched',
        ]);
        $this->assertDatabaseMissing('import_deferred_inbound_emails', [
            'operation_id' => 'historic-import-1',
            'state' => 'processed',
        ]);

        $this->expectException(RuntimeException::class);

        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    #[Test]
    public function duplicate_webhook_delivery_creates_one_operation_outbox_record(): void
    {
        Queue::fake();
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload())
            ->assertJson(['status' => 'deferred']);
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload(token: 'ingress-window-token-redelivery'))
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('import_deferred_inbound_emails', 1);
    }

    #[Test]
    public function reopening_and_drain_are_separate_retry_safe_steps(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());

        $gate->release('historic-import-1');
        $this->assertFalse($gate->isBlocked());

        try {
            $gate->assertDeferredInboundEmailReconciled('historic-import-1');
            $this->fail('Operation closeout must wait for its outbox.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('undrained', $exception->getMessage());
        }

        $this->assertSame(1, app(ImportIngressGate::class)->dispatchDeferredInboundEmail('historic-import-1'));

        /** A repeated drain finds nothing claimable; the row is owned already. */
        $this->assertSame(0, app(ImportIngressGate::class)->dispatchDeferredInboundEmail('historic-import-1'));

        /** Still not reconciled: the queue has the job, nothing has run it. */
        try {
            app(ImportIngressGate::class)->assertDeferredInboundEmailReconciled('historic-import-1');
            $this->fail('A dispatched email is not a processed one.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dispatched=1', $exception->getMessage());
        }

        $this->assertSame(1, $this->runQueuedInboundJobs());
        app(ImportIngressGate::class)->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    #[Test]
    public function a_dispatch_failure_after_one_email_resumes_from_the_durable_outbox_cursor(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');

        foreach ([1, 2, 3] as $index) {
            $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload(
                messageId: "<window-{$index}@example.com>",
                token: "window-token-{$index}",
            ))->assertAccepted();
        }

        $gate->release('historic-import-1');
        $realDispatcher = app(Dispatcher::class);
        $attempt = 0;
        $failingDispatcher = Mockery::mock(Dispatcher::class);
        $failingDispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(function (object $job) use ($realDispatcher, &$attempt): mixed {
                $attempt++;

                if ($attempt === 2) {
                    throw new RuntimeException('injected dispatcher failure');
                }

                return $realDispatcher->dispatch($job);
            });
        $failingGate = new ImportIngressGate(app(HorizonPauseAccounting::class), $failingDispatcher);

        try {
            $failingGate->dispatchDeferredInboundEmail('historic-import-1');
            $this->fail('The injected dispatch failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected dispatcher failure', $exception->getMessage());
        }

        $this->assertSame(1, ImportDeferredInboundEmail::query()->where('state', 'dispatched')->count());
        $this->assertSame(2, ImportDeferredInboundEmail::query()->where('state', 'pending')->count());

        /** The claim the injected failure could not dispatch says why it came back. */
        $released = ImportDeferredInboundEmail::query()
            ->where('state', 'pending')
            ->whereNotNull('last_failed_at')
            ->sole();
        $this->assertNull($released->dispatch_token);
        $this->assertNull($released->lease_expires_at);
        $this->assertSame(1, $released->failure_count);
        $this->assertStringContainsString('injected dispatcher failure', (string) $released->last_error);

        $this->assertSame(2, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        Queue::assertPushed(ProcessInboundOosEmail::class, 3);

        $this->assertSame(3, $this->runQueuedInboundJobs());
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    /**
     * A synchronous dispatcher runs the job inside `dispatch()`, so the row is
     * already `processed` by the time the drain gets to its post-dispatch
     * update. That update is conditional on the claim, so it affects zero rows
     * rather than dragging a finished import back to `dispatched`.
     */
    #[Test]
    public function a_synchronous_dispatcher_that_finishes_first_is_not_regressed_to_dispatched(): void
    {
        Config::set('queue.default', 'sync');
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));

        $deferred = ImportDeferredInboundEmail::query()->sole();
        $this->assertSame(ImportDeferredInboundEmail::StateProcessed, $deferred->state);
        $this->assertNotNull($deferred->processed_at);
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    /**
     * A drain that died between claiming a row and dispatching its job leaves
     * `dispatching` behind. While the lease is live that claim is another
     * drainer's, and taking it would put two jobs behind one email; once it has
     * expired the row is recoverable.
     */
    #[Test]
    public function a_live_claim_is_left_alone_and_an_expired_one_is_recovered(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $deferred = ImportDeferredInboundEmail::query()->sole();
        $deferred->forceFill([
            'state' => ImportDeferredInboundEmail::StateDispatching,
            'dispatch_token' => (string) Str::uuid(),
            'dispatch_claimed_at' => now(),
            'lease_expires_at' => now()->addHour(),
            'dispatch_attempts' => 1,
        ])->save();

        $this->assertSame(0, $gate->dispatchDeferredInboundEmail('historic-import-1'), 'A live claim belongs to another drainer.');
        Queue::assertNotPushed(ProcessInboundOosEmail::class);

        $deferred->forceFill(['lease_expires_at' => now()->subMinute()])->save();

        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
        $this->assertSame(
            2,
            ImportDeferredInboundEmail::query()->sole()->dispatch_attempts,
            'A recovered claim is a second attempt, and the count has to say so.',
        );
    }

    /** A row already handed to the queue is nobody's to claim again. */
    #[Test]
    public function a_dispatched_row_is_never_dispatched_twice(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        $this->assertSame(0, $gate->dispatchDeferredInboundEmail('historic-import-1'));

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    /**
     * A job that exhausts its retries returns the claim to the drain with the
     * reason attached, and closeout stays blocked until it actually succeeds.
     */
    #[Test]
    public function an_exhausted_job_failure_returns_the_row_to_pending_and_blocks_closeout(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');
        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));

        Queue::pushed(ProcessInboundOosEmail::class)
            ->each(fn (ProcessInboundOosEmail $job) => $job->failed(new RuntimeException('parser exploded')));

        $deferred = ImportDeferredInboundEmail::query()->sole();
        $this->assertSame(ImportDeferredInboundEmail::StatePending, $deferred->state);
        $this->assertNull($deferred->processed_at);
        $this->assertNull($deferred->dispatch_token);
        $this->assertNull($deferred->dispatched_at);
        $this->assertSame(1, $deferred->failure_count);
        $this->assertStringContainsString('parser exploded', (string) $deferred->last_error);
        $this->assertNotNull($deferred->last_failed_at);

        try {
            $gate->assertDeferredInboundEmailReconciled('historic-import-1');
            $this->fail('A failed import is not a finished one.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pending=1', $exception->getMessage());
        }

        /** And the operator can retry it without reopening the window. */
        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        $this->assertSame(2, $this->runQueuedInboundJobs());
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    /** A failure recorded after a successful attempt must not reopen the import. */
    #[Test]
    public function a_late_failure_does_not_reopen_an_already_processed_row(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');
        $gate->dispatchDeferredInboundEmail('historic-import-1');
        $this->runQueuedInboundJobs();

        $processedAt = ImportDeferredInboundEmail::query()->sole()->processed_at;

        Queue::pushed(ProcessInboundOosEmail::class)
            ->each(fn (ProcessInboundOosEmail $job) => $job->failed(new RuntimeException('late failure')));

        $deferred = ImportDeferredInboundEmail::query()->sole();
        $this->assertSame(ImportDeferredInboundEmail::StateProcessed, $deferred->state);
        $this->assertEquals($processedAt, $deferred->processed_at);
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
    }

    /**
     * The drain action is the operator's own retry. It never reopens the window
     * and never touches an email the window did not stage.
     */
    #[Test]
    public function the_drain_action_retries_one_operation_outbox_without_reopening_the_window(): void
    {
        Queue::fake();
        $ordinary = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending]);
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $this->artisan('import:ingress', ['action' => 'drain', '--operation' => 'historic-import-1'])
            ->expectsOutputToContain('Queued 1 deferred order-of-service email(s)')
            ->assertSuccessful();

        $this->assertSame(InboundEmailStatus::Pending, $ordinary->fresh()->status);
        $this->assertFalse($gate->isBlocked());

        /** Idempotent: a second drain claims nothing. */
        $this->artisan('import:ingress', ['action' => 'drain', '--operation' => 'historic-import-1'])
            ->expectsOutputToContain('Queued 0 deferred order-of-service email(s)')
            ->assertSuccessful();

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    #[Test]
    public function the_drain_action_requires_an_operation(): void
    {
        $this->artisan('import:ingress', ['action' => 'drain'])
            ->expectsOutputToContain('requires --operation')
            ->assertFailed();
    }

    /**
     * The lease has to outlive the job's own uniqueness window. If it expired
     * first a drain could reclaim a row whose job is still queued, dispatch a
     * second one, and have `ShouldBeUnique` drop it — leaving a claim with no
     * job behind it.
     */
    #[Test]
    public function the_claim_lease_outlives_the_jobs_uniqueness_window(): void
    {
        $this->assertGreaterThan(
            ProcessInboundOosEmail::UniqueForSeconds,
            ImportIngressGate::leaseSeconds(),
        );
    }

    #[Test]
    public function sequential_import_windows_keep_independent_outboxes(): void
    {
        Queue::fake();
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'First window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $this->inboundEmailPayload());
        $gate->release('historic-import-1');

        $payload = $this->inboundEmailPayload(
            messageId: '<second-window@example.com>',
            token: 'ingress-window-token-second',
        );
        $gate->block('historic-import-2', 'Second window');
        $this->postJson(route('api.webhooks.mailgun.inbound'), $payload);
        $gate->release('historic-import-2');

        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        $this->assertSame(1, $gate->dispatchDeferredInboundEmail('historic-import-2'));
        $this->assertSame(
            ['historic-import-1', 'historic-import-2'],
            ImportDeferredInboundEmail::query()->orderBy('id')->pluck('operation_id')->all(),
        );
    }

    /** @return array<string, string> */
    /**
     * Run the jobs `Queue::fake()` recorded, the way a worker would.
     *
     * HIR6 made `dispatched` non-terminal, so a case that stops at the push is
     * asserting a queue handoff rather than an import. The job is idempotent, so
     * calling this twice re-runs nothing that already finished.
     *
     * @return int the number of jobs executed
     */
    private function runQueuedInboundJobs(): int
    {
        $jobs = Queue::pushed(ProcessInboundOosEmail::class);

        foreach ($jobs as $job) {
            app()->call([$job, 'handle']);
        }

        return $jobs->count();
    }

    private function inboundEmailPayload(
        string $messageId = '<staged-during-window@example.com>',
        string $token = 'ingress-window-token',
    ): array {
        $payload = [
            'timestamp' => (string) now()->getTimestamp(),
            'token' => $token,
            'from' => 'Service Planning <planning@example.com>',
            'subject' => 'Order of Service for Sunday',
            'Message-Id' => $messageId,
            'body-plain' => "Welcome\nSong\nPrayer",
            'recipient' => 'oos@crockenhill.org',
        ];

        $payload['signature'] = hash_hmac(
            'sha256',
            $payload['timestamp'].$payload['token'],
            'test-signing-key',
        );

        return $payload;
    }
}
