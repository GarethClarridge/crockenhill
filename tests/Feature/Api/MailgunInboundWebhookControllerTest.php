<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\ProcessInboundOosEmail;
use App\Models\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailgunInboundWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('service-tracking.mailgun.signing_key', 'test-signing-key');
    }

    #[Test]
    public function test_valid_signature_is_accepted_and_queued(): void
    {
        Queue::fake();

        $payload = $this->validPayload();

        $this->postJson('/api/webhooks/mailgun/inbound', $payload)
            ->assertAccepted()
            ->assertJson([
                'status' => 'accepted',
            ]);

        $this->assertDatabaseHas('inbound_emails', [
            'message_id' => '<message-1@example.com>',
            'from' => 'Service Planning <planning@example.com>',
            'subject' => 'Order of Service for Sunday',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('inbound_emails', [
            'processing_metadata->token' => 'abc123',
        ]);
        $this->assertDatabaseMissing('inbound_emails', [
            'processing_metadata->signature' => $payload['signature'],
        ]);

        Queue::assertPushed(ProcessInboundOosEmail::class, function (ProcessInboundOosEmail $job): bool {
            return InboundEmail::query()->where('message_id', '<message-1@example.com>')->exists();
        });
    }

    #[Test]
    public function test_invalid_signature_is_rejected(): void
    {
        Queue::fake();

        $payload = $this->validPayload([
            'signature' => 'invalid-signature',
        ]);

        $this->postJson('/api/webhooks/mailgun/inbound', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('inbound_emails', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_invalid_signature_is_rejected_before_field_validation(): void
    {
        Queue::fake();

        $timestamp = (string) now()->getTimestamp();

        $this->postJson('/api/webhooks/mailgun/inbound', [
            'timestamp' => $timestamp,
            'token' => 'abc123',
            'signature' => 'invalid-signature',
        ])->assertForbidden();

        $this->assertDatabaseCount('inbound_emails', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_duplicate_message_id_returns_ok_without_reprocessing(): void
    {
        Queue::fake();

        $payload = $this->validPayload();

        $this->postJson('/api/webhooks/mailgun/inbound', $payload)
            ->assertAccepted();

        $this->postJson('/api/webhooks/mailgun/inbound', $payload)
            ->assertOk()
            ->assertJson([
                'status' => 'duplicate',
            ]);

        $this->assertDatabaseCount('inbound_emails', 1);
        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    #[Test]
    public function test_stale_timestamp_is_rejected(): void
    {
        Queue::fake();

        $payload = $this->validPayload([
            'timestamp' => (string) (now()->subMinutes(10)->getTimestamp()),
        ]);
        $payload['signature'] = $this->signatureFor($payload['timestamp'], $payload['token']);

        $this->postJson('/api/webhooks/mailgun/inbound', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('inbound_emails', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_missing_required_fields_returns_validation_errors(): void
    {
        Queue::fake();

        $timestamp = (string) now()->getTimestamp();

        $this->postJson('/api/webhooks/mailgun/inbound', [
            'timestamp' => $timestamp,
            'token' => 'abc123',
            'signature' => $this->signatureFor($timestamp, 'abc123'),
            'subject' => 'Order of Service',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'from',
                'Message-Id',
                'body-plain',
            ]);

        $this->assertDatabaseCount('inbound_emails', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $payload = [
            'timestamp' => (string) now()->getTimestamp(),
            'token' => 'abc123',
            'from' => 'Service Planning <planning@example.com>',
            'subject' => 'Order of Service for Sunday',
            'Message-Id' => '<message-1@example.com>',
            'body-plain' => "Welcome\nSong\nPrayer",
            'body-html' => '<p>Welcome</p><p>Song</p><p>Prayer</p>',
            'recipient' => 'oos@crockenhill.org',
        ];

        $payload['signature'] = $this->signatureFor($payload['timestamp'], $payload['token']);

        return array_merge($payload, $overrides);
    }

    private function signatureFor(string $timestamp, string $token): string
    {
        return hash_hmac('sha256', $timestamp.$token, 'test-signing-key');
    }
}
