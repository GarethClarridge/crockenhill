<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\StoreMailgunInboundEmailRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreMailgunInboundEmailRequestTest extends TestCase
{
    #[Test]
    public function it_validates_max_lengths(): void
    {
        $request = new StoreMailgunInboundEmailRequest;
        $request->merge([
            'Message-Id' => str_repeat('a', 513),
            'body-plain' => 'body',
        ]);

        $data = [
            'timestamp' => str_repeat('a', 51),
            'token' => str_repeat('a', 101),
            'signature' => str_repeat('a', 129),
            'from' => str_repeat('a', 256),
            'subject' => str_repeat('a', 256),
            'Message-Id' => str_repeat('a', 513),
            'recipient' => str_repeat('a', 256),
            'Date' => str_repeat('a', 129),
            'body-plain' => 'body',
        ];

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('timestamp', $validator->errors()->toArray());
        $this->assertArrayHasKey('token', $validator->errors()->toArray());
        $this->assertArrayHasKey('signature', $validator->errors()->toArray());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
        $this->assertArrayHasKey('subject', $validator->errors()->toArray());
        $this->assertArrayHasKey('Message-Id', $validator->errors()->toArray());
        $this->assertArrayHasKey('recipient', $validator->errors()->toArray());
        $this->assertArrayHasKey('Date', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_max_body_and_header_lengths(): void
    {
        $request = new StoreMailgunInboundEmailRequest;

        $data = [
            'timestamp' => '1234567890',
            'token' => 'token',
            'signature' => 'signature',
            'from' => 'sender@example.com',
            'subject' => 'Subject',
            'Message-Id' => '<msg-id@example.com>',
            'message-headers' => str_repeat('h', 100001),
            'body-plain' => str_repeat('a', 500001),
            'body-html' => str_repeat('b', 500001),
        ];

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('message-headers', $validator->errors()->toArray());
        $this->assertArrayHasKey('body-plain', $validator->errors()->toArray());
        $this->assertArrayHasKey('body-html', $validator->errors()->toArray());
    }

    #[Test]
    public function it_passes_valid_lengths(): void
    {
        $request = new StoreMailgunInboundEmailRequest;
        $request->merge([
            'Message-Id' => str_repeat('a', 512),
            'body-plain' => 'body',
        ]);

        $data = [
            'timestamp' => str_repeat('a', 50),
            'token' => str_repeat('a', 100),
            'signature' => str_repeat('a', 128),
            'from' => str_repeat('a', 255),
            'subject' => str_repeat('a', 255),
            'Message-Id' => str_repeat('a', 512),
            'recipient' => str_repeat('a', 255),
            'Date' => str_repeat('a', 128),
            'message-headers' => str_repeat('h', 100000),
            'body-plain' => str_repeat('a', 500000),
            'body-html' => str_repeat('b', 500000),
        ];

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }

    #[Test]
    public function it_excludes_technical_routing_fields_from_metadata(): void
    {
        $request = new StoreMailgunInboundEmailRequest;
        $request->merge([
            'timestamp' => '123',
            'token' => 'abc',
            'signature' => 'sig',
            'recipient' => 'oos@crockenhill.org',
            'from' => 'sender@example.com',
            'subject' => 'Subject',
            'body-plain' => 'body',
            'other-field' => 'metadata-value',
        ]);

        $metadata = $request->processingMetadata();

        $this->assertArrayHasKey('other-field', $metadata);
        $this->assertArrayNotHasKey('timestamp', $metadata);
        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertArrayNotHasKey('signature', $metadata);
        $this->assertArrayNotHasKey('recipient', $metadata);
        $this->assertArrayNotHasKey('from', $metadata);
        $this->assertArrayNotHasKey('subject', $metadata);
        $this->assertArrayNotHasKey('body-plain', $metadata);
    }
}
