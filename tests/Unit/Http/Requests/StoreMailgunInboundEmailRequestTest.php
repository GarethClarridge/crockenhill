<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\StoreMailgunInboundEmailRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreMailgunInboundEmailRequestTest extends TestCase
{
    /** @test */
    public function it_validates_max_lengths()
    {
        $request = new StoreMailgunInboundEmailRequest();
        $request->merge([
            'Message-Id' => str_repeat('a', 513),
            'body-plain' => 'body',
        ]);

        $data = [
            'timestamp' => '1234567890',
            'token' => 'token',
            'signature' => 'signature',
            'from' => str_repeat('a', 256),
            'subject' => str_repeat('a', 256),
            'Message-Id' => str_repeat('a', 513),
            'body-plain' => 'body',
        ];

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
        $this->assertArrayHasKey('subject', $validator->errors()->toArray());
        $this->assertArrayHasKey('Message-Id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_passes_valid_lengths()
    {
        $request = new StoreMailgunInboundEmailRequest();
        $request->merge([
            'Message-Id' => str_repeat('a', 512),
            'body-plain' => 'body',
        ]);

        $data = [
            'timestamp' => '1234567890',
            'token' => 'token',
            'signature' => 'signature',
            'from' => str_repeat('a', 255),
            'subject' => str_repeat('a', 255),
            'Message-Id' => str_repeat('a', 512),
            'body-plain' => 'body',
        ];

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }
}
