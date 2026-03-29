<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\StoreMailgunInboundEmailRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreMailgunInboundEmailRequestTest extends TestCase
{
    private function createRequest(array $data = []): StoreMailgunInboundEmailRequest
    {
        $request = new StoreMailgunInboundEmailRequest;
        $request->merge($data);

        return $request;
    }

    private function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = $this->createRequest($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    #[Test]
    public function it_authorizes_all_requests(): void
    {
        $request = $this->createRequest();
        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_requires_base_fields(): void
    {
        $validator = $this->validate([]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('timestamp'));
        $this->assertTrue($validator->errors()->has('token'));
        $this->assertTrue($validator->errors()->has('signature'));
        $this->assertTrue($validator->errors()->has('from'));
        $this->assertTrue($validator->errors()->has('subject'));
    }

    #[Test]
    public function it_requires_message_id_via_with_validator(): void
    {
        $data = [
            'timestamp' => '1234567890',
            'token' => 'abc',
            'signature' => 'def',
            'from' => 'test@example.com',
            'subject' => 'test',
            'body-plain' => 'test body',
        ];

        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('Message-Id'));
    }

    #[Test]
    public function it_accepts_message_id_in_direct_field(): void
    {
        $data = [
            'timestamp' => '1234567890',
            'token' => 'abc',
            'signature' => 'def',
            'from' => 'test@example.com',
            'subject' => 'test',
            'body-plain' => 'test body',
            'Message-Id' => '<message-id@example.com>',
        ];

        $validator = $this->validate($data);

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_accepts_message_id_in_headers(): void
    {
        $data = [
            'timestamp' => '1234567890',
            'token' => 'abc',
            'signature' => 'def',
            'from' => 'test@example.com',
            'subject' => 'test',
            'body-plain' => 'test body',
            'message-headers' => json_encode([['Message-Id', '<header-id@example.com>']]),
        ];

        $validator = $this->validate($data);

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_either_plain_or_html_body(): void
    {
        $data = [
            'timestamp' => '1234567890',
            'token' => 'abc',
            'signature' => 'def',
            'from' => 'test@example.com',
            'subject' => 'test',
            'Message-Id' => '<id@example.com>',
        ];

        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('body-plain'));

        $dataWithPlain = array_merge($data, ['body-plain' => 'plain content']);
        $this->assertFalse($this->validate($dataWithPlain)->fails());

        $dataWithHtml = array_merge($data, ['body-html' => '<p>html content</p>']);
        $this->assertFalse($this->validate($dataWithHtml)->fails());
    }

    #[Test]
    public function it_resolves_message_id_from_field_or_headers(): void
    {
        $request = $this->createRequest(['Message-Id' => ' direct-id ']);
        $this->assertSame('direct-id', $request->messageId());

        $request = $this->createRequest([
            'message-headers' => json_encode([['Message-Id', ' header-id ']]),
        ]);
        $this->assertSame('header-id', $request->messageId());

        $request = $this->createRequest([
            'Message-Id' => 'direct-id',
            'message-headers' => json_encode([['Message-Id', 'header-id']]),
        ]);
        $this->assertSame('direct-id', $request->messageId());

        $request = $this->createRequest();
        $this->assertNull($request->messageId());
    }

    #[Test]
    public function it_resolves_received_at_from_date_header(): void
    {
        $date = 'Sun, 09 Mar 2025 10:00:00 +0000';
        $request = $this->createRequest(['Date' => $date]);

        $this->assertTrue($request->receivedAt()->equalTo(Carbon::parse($date)));

        $request = $this->createRequest(['Date' => 'invalid date']);
        $this->assertInstanceOf(Carbon::class, $request->receivedAt());

        $request = $this->createRequest();
        $this->assertInstanceOf(Carbon::class, $request->receivedAt());
    }

    #[Test]
    public function it_parses_message_headers_json(): void
    {
        $headers = [['Subject', 'Test'], ['From', 'test@example.com']];
        $request = $this->createRequest(['message-headers' => json_encode($headers)]);

        $reflection = new \ReflectionClass(StoreMailgunInboundEmailRequest::class);
        $method = $reflection->getMethod('parsedMessageHeaders');
        $method->setAccessible(true);

        $this->assertSame($headers, $method->invoke($request));

        $request = $this->createRequest(['message-headers' => 'not json']);
        $this->assertSame([], $method->invoke($request));

        $request = $this->createRequest(['message-headers' => '']);
        $this->assertSame([], $method->invoke($request));

        $request = $this->createRequest(['message-headers' => json_encode(['not', 'nested', 'arrays'])]);
        $this->assertSame([], $method->invoke($request));
    }

    #[Test]
    public function it_builds_processing_metadata_excluding_sensitive_fields(): void
    {
        $headers = [['Message-Id', '<id@example.com>']];
        $data = [
            'timestamp' => '123456',
            'token' => 'abc',
            'signature' => 'def',
            'from' => 'sender@example.com',
            'subject' => 'test subject',
            'body-plain' => 'plain content',
            'body-html' => 'html content',
            'recipient' => 'target@example.com',
            'message-headers' => json_encode($headers),
            'Message-Id' => '<id@example.com>',
            'custom-field' => 'custom-value',
        ];

        $request = $this->createRequest($data);
        $metadata = $request->processingMetadata();

        $this->assertArrayNotHasKey('timestamp', $metadata);
        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertArrayNotHasKey('signature', $metadata);
        $this->assertArrayNotHasKey('from', $metadata);
        $this->assertArrayNotHasKey('subject', $metadata);
        $this->assertArrayNotHasKey('body-plain', $metadata);
        $this->assertArrayNotHasKey('body-html', $metadata);

        $this->assertSame('target@example.com', $metadata['recipient']);
        $this->assertSame('custom-value', $metadata['custom-field']);
        $this->assertSame('<id@example.com>', $metadata['resolved_message_id']);
        $this->assertSame($headers, $metadata['parsed_message_headers']);
    }
}
