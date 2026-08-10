<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\Import\HorizonPauseAccounting;
use App\Services\Import\ImportIngressGate;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
        $this->assertSame(0, app(ImportIngressGate::class)->dispatchDeferredInboundEmail('historic-import-1'));
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
        $this->assertSame(2, $gate->dispatchDeferredInboundEmail('historic-import-1'));
        Queue::assertPushed(ProcessInboundOosEmail::class, 3);
        $gate->assertDeferredInboundEmailReconciled('historic-import-1');
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
