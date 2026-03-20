<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MailgunWebhookSignatureValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MailgunWebhookSignatureValidator::class)]
class MailgunWebhookSignatureValidatorTest extends TestCase
{
    private MailgunWebhookSignatureValidator $validator;

    private string $signingKey = 'test-signing-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MailgunWebhookSignatureValidator;

        config(['service-tracking.mailgun.signing_key' => $this->signingKey]);
        config(['service-tracking.mailgun.timestamp_tolerance_seconds' => 300]);
    }

    #[Test]
    public function it_validates_a_correct_signature(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertTrue($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_signing_key_is_missing(): void
    {
        config(['service-tracking.mailgun.signing_key' => '']);
        config(['services.mailgun.signing_key' => '']);

        $timestamp = (string) now()->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_timestamp_is_not_a_digit(): void
    {
        $timestamp = 'not-a-digit';
        $token = 'test-token';
        $signature = 'some-signature';

        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_timestamp_is_too_old(): void
    {
        $timestamp = (string) now()->subSeconds(301)->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_passes_if_timestamp_is_exactly_at_the_tolerance_limit_old(): void
    {
        $timestamp = (string) now()->subSeconds(300)->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertTrue($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_timestamp_is_in_the_future(): void
    {
        $timestamp = (string) now()->addSeconds(301)->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_passes_if_timestamp_is_exactly_at_the_tolerance_limit_future(): void
    {
        $timestamp = (string) now()->addSeconds(300)->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertTrue($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_signature_is_incorrect(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $token = 'test-token';
        $signature = 'invalid-signature';

        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_respects_custom_tolerance_config(): void
    {
        config(['service-tracking.mailgun.timestamp_tolerance_seconds' => 600]);

        $timestamp = (string) now()->subSeconds(450)->getTimestamp();
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        $this->assertTrue($this->validator->isValid($timestamp, $token, $signature));
    }

    #[Test]
    public function it_fails_if_token_is_replayed(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $token = 'unique-replay-token';
        $signature = hash_hmac('sha256', $timestamp.$token, $this->signingKey);

        // First attempt should succeed
        $this->assertTrue($this->validator->isValid($timestamp, $token, $signature));

        // Second attempt with the same token should fail
        $this->assertFalse($this->validator->isValid($timestamp, $token, $signature));
    }
}
