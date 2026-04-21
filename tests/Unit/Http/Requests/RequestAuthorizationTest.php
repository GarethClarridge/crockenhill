<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ProcessMediaRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequestAuthorizationTest extends TestCase
{
    #[Test]
    public function process_media_request_denies_guests(): void
    {
        $request = \Mockery::mock(ProcessMediaRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }
}
